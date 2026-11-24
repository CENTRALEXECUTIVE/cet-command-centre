<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PhoneCall;
use App\Services\Voice\PhoneAgent;
use App\Services\Watchdog\AdminAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tools the realtime voice agent (ElevenLabs) calls during a phone call: today's
 * date, an exact quote (same engine as the website), a booking request for the office
 * to confirm, and a message. Plus the post-call webhook that stores the transcript.
 * Guarded by our webhook secret (?secret= or X-CET-Secret header); the post-call hook
 * also accepts ElevenLabs' HMAC signature when ELEVENLABS_WEBHOOK_SECRET is set.
 */
class VoiceToolsController extends Controller
{
    public function __construct(private readonly PhoneAgent $agent) {}

    public function time(Request $request): JsonResponse
    {
        $this->authorise($request);
        $now = now()->timezone(config('app.timezone'));

        return response()->json([
            'today' => $now->format('l j F Y'),
            'date' => $now->format('Y-m-d'),
            'uk_time' => $now->format('H:i'),
            'tomorrow' => $now->copy()->addDay()->format('Y-m-d'),
        ]);
    }

    public function quote(Request $request): JsonResponse
    {
        $this->authorise($request);
        $call = $this->call($request);
        [$result] = $this->agent->runTool($call, 'get_quote', $request->only(['pickup', 'destination', 'pickup_date_time', 'vehicle', 'return_date_time', 'child_seats', 'vat_invoice']));
        $call->save();

        return response()->json($result);
    }

    public function booking(Request $request, AdminAlerts $alerts): JsonResponse
    {
        $this->authorise($request);
        $call = $this->call($request);
        $fields = collect($request->except(['secret', 'caller_id', 'conversation_id']))->filter(fn ($v) => $v !== null && $v !== '')->all();
        [$result] = $this->agent->runTool($call, 'save_booking_request', $fields);
        $call->save();
        $alerts->notifyDirectors('ai_call', 'New phone booking request', $this->summary($call, ''), 'warning');

        return response()->json($result);
    }

    public function message(Request $request, AdminAlerts $alerts): JsonResponse
    {
        $this->authorise($request);
        $call = $this->call($request);
        [$result] = $this->agent->runTool($call, 'take_message', $request->only(['name', 'callback_number', 'message']));
        $call->save();
        $alerts->notifyDirectors('ai_call', 'Phone message', $this->summary($call, ''), 'warning');

        return response()->json($result);
    }

    /** ElevenLabs post-call webhook (type post_call_transcription). Idempotent per conversation. */
    public function postCall(Request $request, AdminAlerts $alerts): JsonResponse
    {
        $this->authoriseSigned($request);
        if ($request->input('type') !== 'post_call_transcription') {
            return response()->json(['ignored' => true]);
        }
        $d = (array) $request->input('data', []);
        $conv = (string) ($d['conversation_id'] ?? '');
        if ($conv === '') {
            return response()->json(['error' => 'no conversation_id'], 422);
        }
        $vars = (array) data_get($d, 'conversation_initiation_client_data.dynamic_variables', []);
        $call = PhoneCall::firstOrNew(['call_sid' => 'el-'.$conv]);
        if ($call->exists && $call->ended_at) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }
        $call->from_number ??= $vars['system__caller_id'] ?? null;
        $call->transcript = collect($d['transcript'] ?? [])
            ->filter(fn ($t) => filled($t['message'] ?? null))
            ->map(fn ($t) => ['role' => ($t['role'] ?? '') === 'user' ? 'caller' : 'agent', 'text' => (string) $t['message']])
            ->values()->all();
        $start = data_get($d, 'metadata.start_time_unix_secs');
        $dur = (int) data_get($d, 'metadata.call_duration_secs', 0);
        $call->ended_at = $start ? \Illuminate\Support\Carbon::createFromTimestamp($start + $dur) : now();
        if ($call->status === 'active' || ! $call->status) {
            $call->status = str_contains((string) data_get($d, 'metadata.termination_reason', ''), 'transfer') ? 'transferred' : 'completed';
        }
        $aiSummary = (string) data_get($d, 'analysis.transcript_summary', '');
        $call->summary = $this->summary($call, $aiSummary);
        $call->save();
        // Booking requests and messages already alerted live from their tool call.
        if ($call->needs_action && ! $call->booking_fields && ! $call->message) {
            $alerts->notifyDirectors('ai_call', 'AI phone call needs you', $call->summary, 'warning');
        }

        return response()->json(['ok' => true]);
    }

    private function summary(PhoneCall $call, string $ai): string
    {
        $from = $call->from_number ?: 'Unknown number';
        if ($call->booking_fields) {
            $f = $call->booking_fields;

            return "Booking request from {$from}: ".($f['lead_name'] ?? '?').', '.($f['pickup_at'] ?? '?').', '
                .($f['pickup_address'] ?? '?').' to '.($f['destination_address'] ?? '?').'. Confirm it in AI phone calls.';
        }
        if ($call->message) {
            return "Message from {$from}: ".$call->message;
        }

        return "Call from {$from}: ".($ai !== '' ? $ai : 'general enquiry.');
    }

    /** One PhoneCall per ElevenLabs conversation, created by whichever tool fires first. */
    private function call(Request $request): PhoneCall
    {
        $conv = (string) ($request->input('conversation_id') ?: 'unknown-'.now()->format('YmdHi').'-'.substr(md5((string) $request->input('caller_id')), 0, 6));
        $call = PhoneCall::firstOrNew(['call_sid' => 'el-'.$conv]);
        if (! $call->exists) {
            $call->status = 'active';
        }
        $caller = (string) $request->input('caller_id', '');
        if ($caller !== '' && ! str_contains($caller, '{{')) {
            $call->from_number ??= $caller;
        }

        return $call;
    }

    private function authorise(Request $request): void
    {
        $secret = (string) config('cet.webhook_secret');
        $provided = (string) ($request->query('secret') ?? $request->header('X-CET-Secret') ?? '');
        abort_if($secret === '' || ! hash_equals($secret, $provided), 403, 'Invalid webhook secret.');
    }

    /** Our secret in the URL, or ElevenLabs' signature: "t=<unix>,v0=<hex hmac sha256 of '<t>.<raw body>'>". */
    private function authoriseSigned(Request $request): void
    {
        $secret = (string) config('cet.webhook_secret');
        $provided = (string) ($request->query('secret') ?? $request->header('X-CET-Secret') ?? '');
        if ($secret !== '' && hash_equals($secret, $provided)) {
            return;
        }
        $key = (string) config('services.elevenlabs.webhook_secret');
        $sig = (string) $request->header('ElevenLabs-Signature', '');
        abort_if($key === '' || $sig === '', 403, 'Invalid webhook signature.');
        parse_str(str_replace(',', '&', $sig), $parts);
        $t = (int) ($parts['t'] ?? 0);
        $expected = hash_hmac('sha256', $t.'.'.$request->getContent(), $key);
        abort_if(abs(time() - $t) > 1800 || ! hash_equals($expected, (string) ($parts['v0'] ?? '')), 403, 'Invalid webhook signature.');
    }
}
