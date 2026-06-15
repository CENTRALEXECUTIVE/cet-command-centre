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
        $counterpart = $from ? $this->masking->counterpartFor($from) : null;

        return response($this->masking->dialTwiml($counterpart), 200)
            ->header('Content-Type', 'text/xml');
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

    private function authoriseWebhook(Request $request): void
    {
        $secret = config('cet.webhook_secret');
        $provided = $request->input('secret') ?? $request->header('X-CET-Secret');

        abort_if(blank($secret) || ! hash_equals($secret, (string) $provided), 403, 'Invalid webhook secret.');
    }
}
