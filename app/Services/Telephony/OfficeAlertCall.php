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
     * Ring the assigned DRIVER first — they're the one who forgot, and their own
     * phone (with Emergency Bypass) is the fastest way to wake them. Returns false
     * when the driver has no number saved, so the caller drops straight to the
     * backup tier.
     */
    public function ringDriver(Booking $booking, string $message): bool
    {
        return $this->place($booking->driver?->phone, $booking, $message);
    }

    /**
     * BACKUP tier — used once the driver's own phone hasn't got them moving.
     * Routed to whoever's free: a director who isn't the job's own (forgetful)
     * driver and isn't busy/carrying a passenger gets their own mobile rung; if
     * nobody's free we fall back to the business line (which forwards on
     * no-answer). So the backup call never blares next to a passenger.
     */
    public function ringForJob(Booking $booking, string $message): bool
    {
        return $this->place($this->targetFor($booking), $booking, $message);
    }

    /** Ring the plain business line (used by the manual test). */
    public function ring(Booking $booking, string $message): bool
    {
        return $this->place($this->to(), $booking, $message);
    }

    /** Choose the number to ring for this at-risk job. */
    private function targetFor(Booking $booking): ?string
    {
        $free = \App\Models\User::where('role', \App\Enums\UserRole::Admin->value)
            ->where('is_active', true)
            ->whereNotNull('phone')
            ->where('id', '!=', $booking->driver_id) // reach the OTHER person, not the one who forgot
            ->get()
            ->first(fn (\App\Models\User $u) => ! $u->busyForAlerts());

        return $free?->phone ?: $this->to(); // fall back to the business line
    }

    /** Place one call to $to with the acknowledge-gather TwiML. */
    private function place(?string $to, Booking $booking, string $message): bool
    {
        // Dial in proper international format. A director's mobile saved as
        // "07534…" (or "+44 (0)7534…") would be rejected by Twilio and the call
        // would silently fall through to the backup — so normalise it to E.164
        // first, so ringing the DRIVER first actually works whatever the format.
        $to = $this->dial($to);

        if (! $this->configured() || blank($to)) {
            return false;
        }

        try {
            $res = $this->twilio()->post($this->callsUrl(), [
                'From' => $this->dial($this->from()) ?? $this->from(),
                'To' => $to,
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
                'From' => $this->dial($this->from()) ?? $this->from(),
                'To' => $this->dial($this->to()) ?? $this->to(),
                'Twiml' => $twiml,
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

    /**
     * Non-secret status of the emergency-call setup, for the diagnostics command
     * (`cet:alert-call-status`). Never returns credentials — only whether they're
     * present, the from/to numbers, and where the "from" resolved from.
     *
     * @return array{twilio_credentials: bool, from: ?string, from_source: ?string, to: ?string, configured: bool}
     */
    public function diagnostics(): array
    {
        return [
            'twilio_credentials' => filled(config('services.twilio.sid')) && filled(config('services.twilio.token')),
            'from' => $this->from(),
            'from_source' => $this->fromSource(),
            'to' => $this->to(),
            'configured' => $this->configured(),
        ];
    }

    /** Which env/config the "from" number came from (label only, no value). */
    private function fromSource(): ?string
    {
        return match (true) {
            filled(config('cet.alert_call_from')) => 'CET_ALERT_CALL_FROM',
            filled(config('services.twilio_masking.driver_line')) => 'driver line (TWILIO_DRIVER_LINE)',
            filled(config('services.twilio_masking.customer_line')) => 'customer line (TWILIO_CUSTOMER_LINE)',
            filled(config('services.twilio_masking.proxy_number')) => 'legacy proxy number (TWILIO_PROXY_NUMBER)',
            default => null,
        };
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
        // Explicit override first, else the DRIVER switchboard line (kept internal,
        // so the customer-facing line stays customer-only), then other fallbacks.
        return config('cet.alert_call_from')
            ?: config('services.twilio_masking.driver_line')
            ?: config('services.twilio_masking.customer_line')
            ?: config('services.twilio_masking.proxy_number');
    }

    private function to(): ?string
    {
        return config('cet.office_call_number') ?: null;
    }

    /** Normalise any UK/stored number to E.164 (+44…) so Twilio will dial it. */
    private function dial(?string $number): ?string
    {
        $digits = \App\Support\Phone::wa($number); // international digits, no +

        return $digits ? '+'.$digits : null;
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
