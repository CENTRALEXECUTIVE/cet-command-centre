<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PhoneCall;
use App\Services\Voice\PhoneAgent;
use App\Services\Watchdog\AdminAlerts;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Twilio voice webhooks for the AI phone agent. Point the Twilio number's
 * "A call comes in" at /webhooks/ai-voice?secret=CET_WEBHOOK_SECRET and its
 * status callback at /webhooks/ai-voice/status?secret=... (POST).
 * Speech-to-text is Twilio's <Gather input="speech">; the voice is Twilio <Say>.
 */
class AiVoiceController extends Controller
{
    public function __construct(private readonly PhoneAgent $agent) {}

    public function incoming(Request $request): Response
    {
        $this->authorise($request);
        $sid = (string) $request->input('CallSid', 'test-'.uniqid());
        $call = PhoneCall::firstOrCreate(['call_sid' => $sid], ['from_number' => $request->input('From'), 'status' => 'active']);

        if (! config('cet.ai_voice.enabled')) {
            return $this->xml($this->dial($call, 'Thank you for calling Central Executive Transfers. Putting you through now.'));
        }
        $hello = $this->agent->greeting();
        if (! $call->transcript) {
            $call->say('agent', $hello);
            $call->save();
        }

        return $this->xml($this->gather($hello));
    }

    public function turn(Request $request): Response
    {
        $this->authorise($request);
        $call = PhoneCall::where('call_sid', (string) $request->input('CallSid'))->first();
        if (! $call) {
            return $this->incoming($request);
        }
        $speech = trim((string) $request->input('SpeechResult', ''));

        if ($speech === '') {
            $silent = collect($call->transcript ?? [])->reverse()->takeWhile(fn ($l) => $l['role'] !== 'caller')
                ->where('role', 'system')->where('text', '[silence]')->count();
            $call->say('system', '[silence]');
            $call->save();
            if ($silent >= 1) {
                return $this->xml($this->say('Sorry, I can’t hear anything. Please call back or book online at central executive transfers dot co dot uk. Goodbye.').'<Hangup/>');
            }

            return $this->xml($this->gather('Sorry, I didn’t catch that. How can I help?'));
        }

        $call->say('caller', $speech);
        $turns = collect($call->transcript)->where('role', 'caller')->count();
        if ($turns > (int) config('cet.ai_voice.max_turns', 40)) {
            $reply = ['say' => 'Let me pass you to the team.', 'action' => $this->agent->canTransfer() ? 'transfer' : 'hangup'];
        } else {
            $reply = $this->agent->reply($call);
        }
        $call->say('agent', $reply['say']);
        if ($reply['action'] === 'transfer') {
            $call->status = 'transferred';
        }
        $call->save();

        return $this->xml(match ($reply['action']) {
            'transfer' => $this->dial($call, $reply['say']),
            'hangup' => $this->say($reply['say']).'<Hangup/>',
            default => $this->gather($reply['say']),
        });
    }

    /** After a transfer attempt: answered = done; otherwise back to the agent to take a message. */
    public function dialDone(Request $request): Response
    {
        $this->authorise($request);
        $call = PhoneCall::where('call_sid', (string) $request->input('CallSid'))->first();
        if (in_array($request->input('DialCallStatus'), ['completed', 'answered'], true) || ! $call) {
            return $this->xml('<Hangup/>');
        }
        $line = 'I’m sorry, nobody can get to the phone just now. Can I take a message, and have the team call you straight back?';
        $call->say('system', 'Transfer not answered: take a message.');
        $call->say('agent', $line);
        $call->status = 'active';
        $call->needs_action = true;
        $call->save();

        return $this->xml($this->gather($line));
    }

    public function status(Request $request, AdminAlerts $alerts): Response
    {
        $this->authorise($request);
        $call = PhoneCall::where('call_sid', (string) $request->input('CallSid'))->first();
        if ($call && in_array($request->input('CallStatus'), ['completed', 'busy', 'failed', 'no-answer', 'canceled'], true) && ! $call->ended_at) {
            $call->ended_at = now();
            if ($call->status === 'active') {
                $call->status = 'completed';
            }
            $call->summary = $this->summary($call);
            $call->save();
            if ($call->needs_action) {
                $alerts->notifyDirectors('ai_call', 'AI phone call needs you', $call->summary, 'warning', null, null);
            }
        }

        return $this->xml('');
    }

    private function summary(PhoneCall $call): string
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
        if ($call->status === 'transferred') {
            return "Call from {$from} was put through to you.";
        }

        return "Call from {$from}: ".(collect($call->quotes ?? [])->count() ? 'quote given, no booking.' : 'general enquiry.');
    }

    private function gather(string $text): string
    {
        $url = $this->url('webhooks.ai-voice.turn');

        return '<Gather input="speech" action="'.$url.'" method="POST" language="en-GB" speechTimeout="auto" speechModel="phone_call" enhanced="true" actionOnEmptyResult="true">'
            .$this->say($text).'</Gather><Redirect method="POST">'.$url.'</Redirect>';
    }

    private function dial(PhoneCall $call, string $text): string
    {
        $numbers = $this->agent->transferNumbers();
        if (! $numbers) {
            return $this->gather('I’m sorry, nobody can take the call right now. Can I take a message?');
        }
        $caller = htmlspecialchars((string) $call->from_number, ENT_XML1);

        return $this->say($text).'<Dial timeout="25" answerOnBridge="true" action="'.$this->url('webhooks.ai-voice.dial-done').'" method="POST"'
            .($caller ? ' callerId="'.$caller.'"' : '').'>'
            .implode('', array_map(fn ($n) => '<Number>'.htmlspecialchars($n, ENT_XML1).'</Number>', $numbers)).'</Dial>';
    }

    private function say(string $text): string
    {
        $voice = htmlspecialchars((string) config('cet.ai_voice.voice'), ENT_XML1);

        return '<Say voice="'.$voice.'" language="en-GB">'.htmlspecialchars($text, ENT_XML1).'</Say>';
    }

    private function url(string $route): string
    {
        return htmlspecialchars(route($route, ['secret' => config('cet.webhook_secret')]), ENT_XML1);
    }

    private function xml(string $inner): Response
    {
        return response('<?xml version="1.0" encoding="UTF-8"?><Response>'.$inner.'</Response>', 200)
            ->header('Content-Type', 'text/xml');
    }

    private function authorise(Request $request): void
    {
        $secret = config('cet.webhook_secret');
        $provided = $request->query('secret') ?? $request->input('secret') ?? $request->header('X-CET-Secret');
        abort_if(blank($secret) || ! hash_equals((string) $secret, (string) $provided), 403, 'Invalid webhook secret.');
    }
}
