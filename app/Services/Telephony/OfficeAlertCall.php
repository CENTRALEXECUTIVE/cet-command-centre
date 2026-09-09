<?php

namespace App\Services\Telephony;

use App\Models\Booking;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Emergency phone call to the office when a job is about to be missed. Places an
 * outbound Twilio voice call that reads the alert aloud and asks the person to
 * press a key to acknowledge; the watchdog keeps calling until they do (or the
 * driver sets off / a cover is arranged). Raw HTTP, no SDK — same approach as the
 * proxy service, so deploys need no composer step. Silent no-op until a Twilio
 * account + a voice "from" number + an office "to" number are configured.
 */
class OfficeAlertCall
{
    private const BASE = 'https://api.twilio.com/2010-04-01';

    public function configured(): bool
    {
        return filled(config('services.twilio.sid'))
            && filled(config('services.twilio.token'))
            && filled($this->from())
            && filled($this->to());
    }

    /**
     * Ring the office about a job at risk. Returns true when a call was placed.
     * The call plays $message twice, then gathers a keypress that hits the
     * acknowledge webhook (which stops further calls for this booking).
     */
    public function ring(Booking $booking, string $message): bool
    {
        if (! $this->configured()) {
            return false;
        }

        try {
            $res = $this->twilio()->post($this->callsUrl(), [
                'From' => $this->from(),
                'To' => $this->to(),
                'Twiml' => $this->twiml($booking, $message),
            ]);

            if ($res->failed()) {
                Log::warning('[alert-call] failed', ['status' => $res->status(), 'body' => $res->body()]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('[alert-call] error: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Place a one-off TEST call so the office can confirm the emergency line
     * actually rings. No acknowledge loop — it just speaks a test message and
     * hangs up. Returns true when the call was placed.
     */
    public function ringTest(): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $twiml = '<?xml version="1.0" encoding="UTF-8"?><Response><Say voice="alice" language="en-GB">'
            .'This is a test of the Central Executive Transfers emergency alert. '
            .'If you can hear this, your at-risk job alert calls are working.</Say></Response>';

        try {
            $res = $this->twilio()->post($this->callsUrl(), [
                'From' => $this->from(), 'To' => $this->to(), 'Twiml' => $twiml,
            ]);

            return $res->successful();
        } catch (\Throwable $e) {
            Log::warning('[alert-call] test error: '.$e->getMessage());

            return false;
        }
    }

    /** The office number the alert would ring, for showing in the UI. */
    public function target(): ?string
    {
        return $this->to();
    }

    /** The spoken script + a keypress gather that acknowledges (stops the calls). */
    private function twiml(Booking $booking, string $message): string
    {
        $ack = URL::signedRoute('webhooks.alert-ack', ['booking' => $booking->id]);
        $say = htmlspecialchars($message, ENT_QUOTES | ENT_XML1);

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Response>'
            .'<Pause length="1"/>'
            .'<Gather numDigits="1" timeout="8" action="'.htmlspecialchars($ack, ENT_QUOTES | ENT_XML1).'" method="POST">'
            .'<Say voice="alice" language="en-GB">Central Executive Transfers alert. '.$say.' Press any key to acknowledge.</Say>'
            .'<Say voice="alice" language="en-GB">'.$say.' Press any key to acknowledge.</Say>'
            .'</Gather>'
            .'<Say voice="alice" language="en-GB">No key pressed. We will call again.</Say>'
            .'</Response>';
    }

    private function from(): ?string
    {
        return config('cet.alert_call_from')
            ?: config('services.twilio_masking.customer_line')
            ?: config('services.twilio_masking.driver_line')
            ?: config('services.twilio_masking.proxy_number');
    }

    private function to(): ?string
    {
        return config('cet.office_call_number') ?: null;
    }

    private function callsUrl(): string
    {
        return self::BASE.'/Accounts/'.config('services.twilio.sid').'/Calls.json';
    }

    private function twilio(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::asForm()
            ->withBasicAuth((string) config('services.twilio.sid'), (string) config('services.twilio.token'))
            ->timeout(15);
    }
}
