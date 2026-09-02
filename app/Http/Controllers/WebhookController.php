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
            'auto_response_sent' => $call->auto_response_sent,
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
            return response()->json(['error' => 'bad signature'], 403);
        }

        // A verified call got through — stamp it so the payroll health indicator
        // can show the webhook is actually reaching us.
        $square->markWebhookSeen();

        // One Square webhook covers both driver TIPS (TIP- orders) and booking
        // FARE payments (FARE- orders); each service ignores the other's prefix.
        $payload = $request->json()->all();
        $booking = $square->recordTipFromWebhook($payload)
            ?? app(\App\Services\Payments\SquareBookingPaymentService::class)->recordFareFromWebhook($payload);

        return response()->json(['recorded' => (bool) $booking]);
    }

    private function authoriseWebhook(Request $request): void
    {
        $secret = config('cet.webhook_secret');
        $provided = $request->input('secret') ?? $request->header('X-CET-Secret');

        abort_if(blank($secret) || ! hash_equals($secret, (string) $provided), 403, 'Invalid webhook secret.');
    }
}
