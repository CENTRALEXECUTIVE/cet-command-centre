<?php

namespace App\Http\Controllers;

use App\Services\MissedCallService;
use App\Services\Telephony\MaskingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Inbound webhooks from telephony (e.g. Twilio). Guarded by a shared secret in
 * config('cet.webhook_secret') passed as ?secret= or the X-CET-Secret header.
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly MissedCallService $missedCalls,
        private readonly MaskingService $masking,
    ) {}

    /**
     * Twilio voice webhook for number masking — bridges the caller to the other
     * party on their active job. Returns TwiML.
     */
    public function voice(Request $request): Response
    {
        $this->authoriseWebhook($request);

        $from = $request->input('From') ?? $request->input('from');
        $resolved = $from ? $this->masking->resolve($from) : null;

        return response($this->masking->dialTwiml($resolved), 200)
            ->header('Content-Type', 'text/xml');
    }

    /**
     * Twilio SMS webhook for number masking — forwards a text to the other party
     * on the caller's current job (from the counterpart's CET line, so a reply
     * routes straight back). Bodies are never stored.
     */
    public function sms(Request $request): Response
    {
        $this->authoriseWebhook($request);

        $from = $request->input('From') ?? $request->input('from');
        $body = (string) ($request->input('Body') ?? $request->input('body') ?? '');
        $forwarded = $from ? $this->masking->forwardSms($from, $body) : false;

        // We send the forward via the REST API, so the TwiML reply is empty on
        // success, or a polite note back to the sender when there's no job.
        $xml = $forwarded
            ? '<?xml version="1.0" encoding="UTF-8"?><Response/>'
            : '<?xml version="1.0" encoding="UTF-8"?><Response><Message>Sorry, we couldn\'t connect your message. Please contact Central Executive Transfers.</Message></Response>';

        return response($xml, 200)->header('Content-Type', 'text/xml');
    }

    public function missedCall(Request $request): JsonResponse
    {
        $this->authoriseWebhook($request);

        // Twilio sends the caller as "From"; accept a couple of aliases.
        $from = $request->input('From') ?? $request->input('from') ?? $request->input('caller');

        if (blank($from)) {
            return response()->json(['error' => 'Missing caller number'], 422);
        }

        $call = $this->missedCalls->handle($from);

        return response()->json([
            'handled' => true,
            'auto_response_sent' => (bool) $call->auto_response_sent, // always false now — the office replies by hand
        ]);
    }

    /**
     * Twilio Proxy callbacks (call/message interactions and out-of-session
     * contact attempts) — logged to the masking audit trail. Message bodies
     * are stripped before storage; only metadata is kept.
     */
    public function proxyEvent(Request $request): JsonResponse
    {
        $this->authoriseWebhook($request);

        app(\App\Services\Telephony\TwilioProxyService::class)
            ->recordWebhookEvent($request->all());

        return response()->json(['logged' => true]);
    }

    /**
     * Square payment webhook — a customer card TIP. Verified by Square's HMAC
     * signature (not the shared secret), then the tip is logged onto the driver's
     * payroll. Always answers 200 on a genuine call so Square stops retrying,
     * even when there's nothing to record.
     */
    public function square(Request $request, \App\Services\Payments\SquareTipService $square): JsonResponse
    {
        $signature = $request->header('x-square-hmacsha256-signature');
        if (! $square->verifySignature($signature, $request->getContent(), $request->fullUrl())) {
            \Illuminate\Support\Facades\Log::warning('[Square webhook] REJECTED — bad signature (check the signature key in Settings → payments and the webhook URL in Square)', ['url' => $request->fullUrl()]);

            return response()->json(['error' => 'bad signature'], 403);
        }

        // A verified call got through — stamp it so the payroll health indicator
        // can show the webhook is actually reaching us.
        $square->markWebhookSeen();

        // One Square webhook covers both driver TIPS (TIP- orders) and booking
        // FARE payments (FARE- orders); each service ignores the other's prefix.
        $payload = $request->json()->all();
        $tipBooking = $square->recordTipFromWebhook($payload);
        $fareBooking = $tipBooking
            ? null
            : app(\App\Services\Payments\SquareBookingPaymentService::class)->recordFareFromWebhook($payload);

        \Illuminate\Support\Facades\Log::info('[Square webhook] '.($payload['type'] ?? '?').' → '.($tipBooking ? 'tip '.$tipBooking->reference : ($fareBooking ? 'fare recorded '.$fareBooking->reference : 'not a new fare (ignored)')));

        // A fare paid online confirms the web booking: allocate + calendar + alert.
        if ($fareBooking) {
            app(\App\Services\BookingStatusService::class)->onFarePaid($fareBooking->fresh()); // heal status + customer & office paid emails
        }

        // Not a tip or a fare? It may be a COVER- combined-invoice payment — settle it.
        $cover = ($tipBooking || $fareBooking)
            ? false
            : $this->settleCoverByReference($square->coverReferenceFromWebhook($payload), 'square');

        return response()->json(['recorded' => (bool) ($tipBooking ?? $fareBooking), 'cover' => $cover]);
    }

    /**
     * Square webhook for the SISTER company (Central Executive Chauffeurs) account —
     * fare payments from no-VAT customers land here. Verified with the sister
     * account's own signing key; only fare (FARE-) orders are recorded, attributed
     * to the 'chauffeurs' entity. A separate endpoint keeps the two accounts'
     * webhooks cleanly apart. 403 until the sister account is configured.
     */
    public function squareChauffeurs(Request $request): JsonResponse
    {
        $fares = app(\App\Services\Payments\SquareBookingPaymentService::class);
        $sisterSet = filled(config('services.square_chauffeurs.access_token'))
            && filled(config('services.square_chauffeurs.location_id'));
        $key = $fares->webhookSignatureKey('chauffeurs');
        $signature = $request->header('x-square-hmacsha256-signature');

        if (! $sisterSet || blank($key) || blank($signature)) {
            \Illuminate\Support\Facades\Log::warning('[Square sister webhook] REJECTED — not configured');

            return response()->json(['error' => 'not configured'], 403);
        }
        // Scheme-proof (a host proxy can make the app see http where Square signed
        // https) — check the URL as given and with the scheme forced either way.
        $url = $request->fullUrl();
        $urls = array_unique([$url, preg_replace('#^http://#i', 'https://', $url), preg_replace('#^https://#i', 'http://', $url)]);
        $matched = false;
        foreach ($urls as $u) {
            if (hash_equals(base64_encode(hash_hmac('sha256', $u.$request->getContent(), $key, true)), (string) $signature)) {
                $matched = true;
                break;
            }
        }
        if (! $matched) {
            \Illuminate\Support\Facades\Log::warning('[Square sister webhook] REJECTED — bad signature');

            return response()->json(['error' => 'bad signature'], 403);
        }

        $payload = $request->json()->all();
        $fareBooking = $fares->recordFareFromWebhook($payload, 'chauffeurs');
        \Illuminate\Support\Facades\Log::info('[Square sister webhook] '.($payload['type'] ?? '?').' → '.($fareBooking ? 'fare recorded '.$fareBooking->reference : 'not a new fare (ignored)'));
        if ($fareBooking) {
            app(\App\Services\BookingStatusService::class)->onFarePaid($fareBooking->fresh()); // heal status + customer & office paid emails
        }

        $cover = $fareBooking
            ? false
            : $this->settleCoverByReference($fares->coverReferenceFromWebhook($payload, 'chauffeurs'), 'square');

        return response()->json(['recorded' => (bool) $fareBooking, 'cover' => $cover]);
    }

    /**
     * Stripe webhook for the sister company (Central Executive Transfers PVT LTD):
     * a completed Checkout Session marks the booking's fare paid. Verified with the
     * Stripe signing secret over the RAW request body.
     */
    public function stripe(Request $request): JsonResponse
    {
        $stripe = app(\App\Services\Payments\StripePaymentService::class);
        if (! $stripe->enabled()) {
            \Illuminate\Support\Facades\Log::warning('[Stripe webhook] REJECTED — Stripe not configured');

            return response()->json(['error' => 'not configured'], 403);
        }
        if (! $stripe->verifyWebhook($request->getContent(), $request->header('Stripe-Signature'))) {
            \Illuminate\Support\Facades\Log::warning('[Stripe webhook] REJECTED — bad signature (the webhook signing secret in Settings → payments must match this Stripe endpoint, same live/test mode)');

            return response()->json(['error' => 'bad signature'], 403);
        }

        $payload = $request->json()->all();
        $fareBooking = $stripe->recordFareFromWebhook($payload);
        \Illuminate\Support\Facades\Log::info('[Stripe webhook] '.($payload['type'] ?? '?').' → '.($fareBooking ? 'fare recorded '.$fareBooking->reference : 'not a new fare (ignored)'));
        if ($fareBooking) {
            app(\App\Services\BookingStatusService::class)->onFarePaid($fareBooking->fresh()); // heal status + customer & office paid emails
        }

        $cover = $fareBooking
            ? false
            : $this->settleCoverByReference($stripe->coverReferenceFromWebhook($payload), 'stripe');

        return response()->json(['recorded' => (bool) $fareBooking, 'cover' => $cover]);
    }

    /**
     * Settle a COVER- combined invoice paid by card: find the booking that created
     * the link (it stashed the reference + the ids of every job on the invoice) and
     * mark them all paid, so they drop off the cover-invoice list and can't be
     * billed again. Returns true if anything was settled.
     */
    private function settleCoverByReference(?string $reference, string $provider): bool
    {
        if (! $reference) {
            return false;
        }

        $anchor = \App\Models\Booking::where('meta->cover_payment_link->reference', $reference)->first();
        if (! $anchor) {
            return false;
        }

        $link = $anchor->meta['cover_payment_link'] ?? [];
        $ids = is_array($link['ids'] ?? null) ? $link['ids'] : [];
        $bookings = $ids ? \App\Models\Booking::whereIn('id', $ids)->get() : collect([$anchor]);

        $settled = false;
        foreach ($bookings as $b) {
            if ($b->isCoverJob() && ! $b->isCoverPaid()) {
                $b->markCoverPaid([
                    'provider' => $provider,
                    'reference' => $reference,
                    'amount' => $link['amount'] ?? null,
                ]);
                $settled = true;
            }
        }

        return $settled;
    }

    /**
     * A keypress on the emergency "job at risk" auto-call. Acknowledges the job so
     * the watchdog stops ringing the office. Signed URL (the call TwiML carried
     * the signature); returns TwiML so Twilio speaks the confirmation.
     */
    public function alertAck(Request $request, \App\Models\Booking $booking): Response
    {
        abort_unless($request->hasValidSignature(), 403);

        $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
            'at_risk_ack' => now()->toIso8601String(),
        ])])->save();

        \App\Models\WatchdogEvent::log('at_risk_ack', 'At-risk job acknowledged by phone', 'info', $booking);

        return response(
            '<?xml version="1.0" encoding="UTF-8"?><Response><Say voice="alice" language="en-GB">'
            .'Acknowledged. Thank you.</Say><Hangup/></Response>',
            200, ['Content-Type' => 'text/xml']
        );
    }

    private function authoriseWebhook(Request $request): void
    {
        $secret = config('cet.webhook_secret');
        $provided = $request->input('secret') ?? $request->header('X-CET-Secret');

        abort_if(blank($secret) || ! hash_equals($secret, (string) $provided), 403, 'Invalid webhook secret.');
    }
}
