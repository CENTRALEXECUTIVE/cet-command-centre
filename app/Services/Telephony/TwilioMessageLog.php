<?php

namespace App\Services\Telephony;

use App\Models\Booking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Read-only transcript of the SMS on a booking's masked line, pulled LIVE from
 * Twilio's Programmable Messaging API — nothing is stored in our database (the
 * proxy webhook still strips message bodies on purpose). It exists so the office
 * can see what a driver and customer said to each other on the masked line, for
 * dispute resolution and safeguarding.
 *
 * It labels each message by which masked line it was sent TO: a text to the
 * DRIVER's masked line is from the driver; a text to the CUSTOMER's masked line
 * is from the customer. So we never need, or expose, a real phone number.
 *
 * Silent no-op (returns []) when Twilio isn't configured or on any error — it
 * must never break the booking page.
 */
class TwilioMessageLog
{
    private const API = 'https://api.twilio.com/2010-04-01';

    public function configured(): bool
    {
        return filled(config('services.twilio.sid')) && filled(config('services.twilio.token'));
    }

    /**
     * The masked-line conversation for a booking, oldest first.
     *
     * @return array<int, array{sender:string, body:?string, sent_at:?Carbon, status:?string}>
     */
    public function forBooking(Booking $booking): array
    {
        if (! $this->configured()) {
            return [];
        }

        // Twilio Proxy sessions when this booking used them; otherwise fall back to
        // the SWITCHBOARD (two fixed CET lines) that this account actually runs on —
        // without this, a switchboard-masked booking showed no messages at all.
        $sessions = $booking->proxySessions()->get();
        if ($sessions->isEmpty()) {
            return $this->fromSwitchboard($booking);
        }

        // Map each masked line to the party who TEXTS it, and find the window the
        // mask was live so we only pull this booking's traffic off a shared line.
        $lineSender = [];   // normalised masked number => 'Driver' | 'Customer'
        $earliest = null;
        $latest = null;
        foreach ($sessions as $s) {
            if (filled($s->masked_number)) {
                $lineSender[$this->norm($s->masked_number)] = 'Driver';
            }
            if (filled($s->customer_masked_number)) {
                $lineSender[$this->norm($s->customer_masked_number)] = 'Customer';
            }
            $open = $s->opened_at ?? $s->created_at;
            $close = $s->closed_at ?? now();
            if ($open && (! $earliest || $open->lt($earliest))) {
                $earliest = $open->copy();
            }
            if ($close && (! $latest || $close->gt($latest))) {
                $latest = $close->copy();
            }
        }
        if (! $lineSender || ! $earliest) {
            return [];
        }

        $from = $earliest->copy()->subHour();
        $to = ($latest ?? now())->copy()->addHour();

        $rows = [];
        $seen = [];
        try {
            foreach ($lineSender as $number => $sender) {
                // Inbound messages arrive TO the masked line (the original
                // utterance); the relayed copy to the other party is a duplicate
                // we deliberately skip.
                foreach ($this->fetch(['To' => $number, 'PageSize' => 100]) as $m) {
                    $sid = $m['sid'] ?? null;
                    if ($sid && isset($seen[$sid])) {
                        continue;
                    }
                    if (! str_contains(strtolower($m['direction'] ?? ''), 'inbound')) {
                        continue;
                    }
                    $sent = ! empty($m['date_sent']) ? Carbon::parse($m['date_sent']) : null;
                    if ($sent && ($sent->lt($from) || $sent->gt($to))) {
                        continue;
                    }
                    if ($sid) {
                        $seen[$sid] = true;
                    }
                    $rows[] = [
                        'sender' => $sender,
                        'body' => $m['body'] ?? null,
                        'sent_at' => $sent,
                        'status' => $m['status'] ?? null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('TwilioMessageLog fetch failed', ['booking' => $booking->id, 'error' => $e->getMessage()]);

            return [];
        }

        usort($rows, fn ($a, $b) => ($a['sent_at']?->timestamp ?? 0) <=> ($b['sent_at']?->timestamp ?? 0));

        return $rows;
    }

    /**
     * Switchboard transcript: the account masks with two fixed CET lines, not
     * per-booking Proxy numbers. We attribute a message to THIS booking by the
     * exact real numbers involved — a text TO the customer line FROM the customer's
     * own number is the customer; TO the driver line FROM the driver's number is
     * the driver — within the window the mask was live. No real number is shown.
     *
     * @return array<int, array{sender:string, body:?string, sent_at:?Carbon, status:?string}>
     */
    private function fromSwitchboard(Booking $booking): array
    {
        $masking = app(MaskingService::class);
        $customerLine = $this->norm((string) $masking->customerLine($booking));
        $driverLine = $this->norm((string) $masking->driverLine());
        if ($customerLine === '' || $driverLine === '') {
            return [];
        }

        $customerReal = $this->norm((string) $booking->customerContactNumber());
        $driverReal = $this->norm((string) $booking->driverRealPhone());

        [$start, $end] = $this->maskWindow($booking);

        $pairs = [];
        if ($customerReal !== '') {
            $pairs[] = ['to' => $customerLine, 'from' => $customerReal, 'sender' => 'Customer'];
        }
        if ($driverReal !== '') {
            $pairs[] = ['to' => $driverLine, 'from' => $driverReal, 'sender' => 'Driver'];
        }
        if (! $pairs) {
            return [];
        }

        $rows = [];
        try {
            foreach ($pairs as $p) {
                foreach ($this->fetch([
                    'To' => $p['to'], 'From' => $p['from'], 'PageSize' => 100,
                    'DateSent>' => $start->copy()->subDay()->format('Y-m-d'),
                ]) as $m) {
                    $sent = ! empty($m['date_sent']) ? Carbon::parse($m['date_sent']) : null;
                    if ($sent && ($sent->lt($start) || $sent->gt($end))) {
                        continue;
                    }
                    $rows[] = [
                        'sender' => $p['sender'],
                        'body' => $m['body'] ?? null,
                        'sent_at' => $sent,
                        'status' => $m['status'] ?? null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('TwilioMessageLog switchboard fetch failed', ['booking' => $booking->id, 'error' => $e->getMessage()]);

            return [];
        }

        usort($rows, fn ($a, $b) => ($a['sent_at']?->timestamp ?? 0) <=> ($b['sent_at']?->timestamp ?? 0));

        return $rows;
    }

    /**
     * The CALLS on a booking's masked line — who rang whom, when and for how long.
     * Same attribution as the texts: a call TO the customer line FROM the customer's
     * number is the customer calling their driver, and vice versa. Pulled live from
     * Twilio's Calls API; nothing stored. No real number is ever shown.
     *
     * @return array<int, array{caller:string, callee:string, at:?Carbon, duration:int, status:?string}>
     */
    public function callsForBooking(Booking $booking): array
    {
        if (! $this->configured()) {
            return [];
        }

        $sessions = $booking->proxySessions()->get();

        // Switchboard: the two fixed CET lines + this booking's real numbers.
        if ($sessions->isEmpty()) {
            $masking = app(MaskingService::class);
            $lines = [
                ['to' => $this->norm((string) $masking->customerLine($booking)), 'from' => $this->norm((string) $booking->customerContactNumber()), 'caller' => 'Customer', 'callee' => 'Driver'],
                ['to' => $this->norm((string) $masking->driverLine()), 'from' => $this->norm((string) $booking->driverRealPhone()), 'caller' => 'Driver', 'callee' => 'Customer'],
            ];
        } else {
            // Proxy: a call TO the driver's masked line is the driver ringing the
            // customer; TO the customer's masked line is the customer ringing the driver.
            $lines = [];
            foreach ($sessions as $s) {
                if (filled($s->masked_number)) {
                    $lines[] = ['to' => $this->norm($s->masked_number), 'from' => null, 'caller' => 'Driver', 'callee' => 'Customer'];
                }
                if (filled($s->customer_masked_number)) {
                    $lines[] = ['to' => $this->norm($s->customer_masked_number), 'from' => null, 'caller' => 'Customer', 'callee' => 'Driver'];
                }
            }
        }

        [$start, $end] = $this->maskWindow($booking);

        $rows = [];
        $seen = [];
        try {
            foreach ($lines as $l) {
                if ($l['to'] === '' || ($l['from'] !== null && $l['from'] === '')) {
                    continue;
                }
                $query = array_filter([
                    'To' => $l['to'],
                    'From' => $l['from'],
                    'PageSize' => 100,
                    'StartTime>' => $start->copy()->subDay()->format('Y-m-d'),
                ], fn ($v) => $v !== null && $v !== '');

                foreach ($this->fetchCalls($query) as $c) {
                    $sid = $c['sid'] ?? null;
                    if ($sid && isset($seen[$sid])) {
                        continue;
                    }
                    // Proxy lines are per-booking so To alone identifies them, but a
                    // bridged call also has an outbound leg — keep only the inbound leg.
                    if ($l['from'] === null && ! str_contains(strtolower($c['direction'] ?? ''), 'inbound')) {
                        continue;
                    }
                    $at = ! empty($c['start_time']) ? Carbon::parse($c['start_time']) : null;
                    if ($at && ($at->lt($start) || $at->gt($end))) {
                        continue;
                    }
                    if ($sid) {
                        $seen[$sid] = true;
                    }
                    $rows[] = [
                        'caller' => $l['caller'],
                        'callee' => $l['callee'],
                        'at' => $at,
                        'duration' => (int) ($c['duration'] ?? 0),
                        'status' => $c['status'] ?? null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('TwilioCallLog fetch failed', ['booking' => $booking->id, 'error' => $e->getMessage()]);

            return [];
        }

        usort($rows, fn ($a, $b) => ($a['at']?->timestamp ?? 0) <=> ($b['at']?->timestamp ?? 0));

        return $rows;
    }

    /**
     * The window the mask was live for this booking — generous, since we already
     * pin messages/calls to the exact real numbers, so width can't bleed another
     * job in unless it's the very same driver AND customer.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function maskWindow(Booking $booking): array
    {
        $duration = (int) ($booking->meta['duration_minutes'] ?? 60);

        return [
            $booking->pickup_at->copy()->subHours(6),
            $booking->pickup_at->copy()->addMinutes($duration)
                ->addMinutes((int) round($booking->maskingGraceHours() * 60))->addHours(6),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function fetch(array $query): array
    {
        $sid = (string) config('services.twilio.sid');
        $res = Http::withBasicAuth($sid, (string) config('services.twilio.token'))
            ->timeout(12)
            ->get(self::API.'/Accounts/'.$sid.'/Messages.json', $query);

        return $res->ok() ? ($res->json('messages') ?? []) : [];
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchCalls(array $query): array
    {
        $sid = (string) config('services.twilio.sid');
        $res = Http::withBasicAuth($sid, (string) config('services.twilio.token'))
            ->timeout(12)
            ->get(self::API.'/Accounts/'.$sid.'/Calls.json', $query);

        return $res->ok() ? ($res->json('calls') ?? []) : [];
    }

    private function norm(string $n): string
    {
        $n = preg_replace('/[^\d+]/', '', $n);

        return str_starts_with($n, '0') ? '+44'.substr($n, 1) : $n;
    }
}
