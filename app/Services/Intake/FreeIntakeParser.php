<?php

namespace App\Services\Intake;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Free, deterministic parser for the "Paste a booking" box — no AI, no cost.
 *
 * Handles the labelled formats the office actually pastes:
 *  - the CET calendar details block ("• *Date & Time:* 24/11/2026 – 07:30" …),
 *  - ETO booking emails ("Date & time: 22/06/2026 11:45", "Pickup: …"),
 *  - and any similar "Label: value" message, with best-effort fallbacks for
 *    loose text (a date+time anywhere, a UK mobile number, "from X to Y").
 *
 * Returns the same field array the AI path produced, ready for
 * BookingIntakeService::normalise(). All times are UK local — never shifted.
 */
class FreeIntakeParser
{
    /** Airport name → title code, for the WHERE word in the calendar title. */
    private const AIRPORTS = [
        'manchester airport' => 'MAN', '(man)' => 'MAN',
        'heathrow' => 'LHR', '(lhr)' => 'LHR',
        'gatwick' => 'LGW', '(lgw)' => 'LGW',
        'stansted' => 'STN', '(stn)' => 'STN',
        'luton' => 'LTN', '(ltn)' => 'LTN',
        'east midlands' => 'EMA', '(ema)' => 'EMA',
        'leeds bradford' => 'LBA', '(lba)' => 'LBA',
        'birmingham airport' => 'BHX', '(bhx)' => 'BHX',
        'john lennon' => 'LPL', 'liverpool airport' => 'LPL', '(lpl)' => 'LPL',
        'newcastle airport' => 'NCL', '(ncl)' => 'NCL',
        'edinburgh airport' => 'EDI', '(edi)' => 'EDI',
        'glasgow airport' => 'GLA', '(gla)' => 'GLA',
        'bristol airport' => 'BRS', '(brs)' => 'BRS',
        'london city' => 'LCY', '(lcy)' => 'LCY',
        'doncaster' => 'DSA', '(dsa)' => 'DSA',
        'humberside' => 'HUY', '(huy)' => 'HUY',
        'teesside' => 'MME', '(mme)' => 'MME',
    ];

    /**
     * @return array<string, mixed> Field array for normalise(); empty strings
     *                              where nothing was found (form stays editable).
     */
    public function parse(string $text): array
    {
        $labels = $this->labelledLines($text);
        $get = function (string ...$keys) use ($labels): ?string {
            foreach ($keys as $k) {
                if (isset($labels[$k]) && $labels[$k] !== '') {
                    return $labels[$k];
                }
            }

            return null;
        };

        $pickup = $get('pickup location', 'pickup address', 'pickup', 'from', 'collection', 'collect from');
        $dropoff = $get('drop-off location', 'dropoff location', 'drop off location', 'dropoff', 'drop-off', 'drop off', 'destination', 'to');

        // Loose fallback: "from X to Y" on one line.
        if ((! $pickup || ! $dropoff) && preg_match('/\bfrom\s+(.{4,80}?)\s+to\s+(.{4,120}?)(?:[.\n]|$)/i', $text, $m)) {
            $pickup = $pickup ?: trim($m[1]);
            $dropoff = $dropoff ?: trim($m[2]);
        }

        [$suitcases, $hand] = $this->luggage($get('luggage', 'bags'), $labels);
        $payment = $this->payment($get('payment', 'payments', 'payment method'), $text);

        return [
            'lead_name' => $get('customer name', 'lead passenger', 'passenger name', 'lead name', 'name', 'customer') ?? '',
            'contact_no' => $this->phone($get('contact no', 'contact number', 'phone number', 'contact', 'phone', 'mobile', 'tel'), $text),
            'email' => $get('email', 'e-mail') ?? $this->email($text),
            'pickup_at' => $this->dateTime($get('date & time', 'date and time', 'pickup time', 'pickup date', 'date & time of pickup', 'when', 'date'), $text),
            'pickup_address' => $pickup ?? '',
            'destination_address' => $dropoff ?? '',
            'where' => $this->where($pickup, $dropoff),
            'flight_number' => $this->flight($get('flight number', 'arrival flight number', 'departure flight number', 'flight'), $text),
            'passengers' => (int) preg_replace('/\D/', '', (string) ($get('passengers', 'pax', 'number of passengers') ?? '')) ?: 1,
            'suitcases' => $suitcases,
            'hand_luggage' => $hand,
            'vehicle' => $get('vehicle type', 'vehicle', 'car type', 'car') ?? '',
            'payment' => $payment['method'],
            'paid' => $payment['paid'],
            'booked_by' => $get('booked by', 'booker') ?? '',
            'notes' => $get('notes', 'comments', 'special requests', 'meet & greet note') ?? '',
        ];
    }

    /** Did the parse find enough to be useful on its own (no AI needed)? */
    public function foundEssentials(array $fields): bool
    {
        return $fields['pickup_at'] !== ''
            && ($fields['pickup_address'] !== '' || $fields['destination_address'] !== '');
    }

    /**
     * "Label: value" lines, tolerant of the calendar's bullets and *bold*
     * markers and of ETO's plain template. First value per label wins.
     *
     * @return array<string, string>
     */
    private function labelledLines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            // Strip bullets, emoji and bold markers: "• *Date & Time:* x" → "Date & Time: x"
            $line = trim(preg_replace('/^[\s•·\-–—]*|[*_]/u', '', trim($line)));
            $line = preg_replace('/^[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}📑]+\s*/u', '', $line);
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            // Don't treat a time ("07:30") as a label split.
            if (! preg_match('/^([^:\d][^:]{0,40}?):\s*(.+)$/u', $line, $m)) {
                continue;
            }
            $label = Str::lower(trim($m[1]));
            $value = trim($m[2]);
            if ($value !== '') {
                $out[$label] ??= $value;
            }
        }

        return $out;
    }

    /** UK-local datetime from a labelled value or anywhere in the text. */
    private function dateTime(?string $value, string $text): string
    {
        foreach (array_filter([$value, $text]) as $source) {
            // 24/11/2026 – 07:30 | 24/11/2026 07:30 | 24-11-2026 7:30
            if (preg_match('#(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})\s*(?:[–\-—]|at)?\s*(\d{1,2})[:.](\d{2})#u', $source, $m)) {
                return sprintf('%04d-%02d-%02d %02d:%02d', $m[3], $m[2], $m[1], $m[4], $m[5]);
            }
            // 2026-11-24 07:30 (ISO-ish)
            if (preg_match('#(\d{4})-(\d{2})-(\d{2})[T\s](\d{1,2})[:.](\d{2})#', $source, $m)) {
                return sprintf('%04d-%02d-%02d %02d:%02d', $m[1], $m[2], $m[3], $m[4], $m[5]);
            }
            // "24 November 2026 at 7:30am" / "24th Nov 2026 07:30"
            if (preg_match('/(\d{1,2})(?:st|nd|rd|th)?\s+([A-Za-z]{3,9})\s+(\d{4})\s*(?:at|[–\-—,])?\s*(\d{1,2})[:.](\d{2})\s*(am|pm)?/iu', $source, $m)) {
                try {
                    $c = Carbon::createFromFormat(
                        'j F Y G:i',
                        $m[1].' '.$m[2].' '.$m[3].' '.((int) $m[4]).':'.$m[5],
                        config('app.timezone')
                    );
                    if (isset($m[6]) && strtolower($m[6]) === 'pm' && (int) $m[4] < 12) {
                        $c->addHours(12);
                    }

                    return $c->format('Y-m-d H:i');
                } catch (\Throwable) {
                    // fall through to the next pattern/source
                }
            }
        }

        return '';
    }

    /** "8 Suitcases + 4 Hand Luggage" → [8, 4]; separate labels also honoured. */
    private function luggage(?string $value, array $labels): array
    {
        $suitcases = isset($labels['suitcases']) ? (int) $labels['suitcases'] : null;
        $hand = isset($labels['hand luggage']) ? (int) $labels['hand luggage'] : null;

        if ($value) {
            if (preg_match('/(\d+)\s*suitcase/i', $value, $m)) {
                $suitcases ??= (int) $m[1];
            }
            if (preg_match('/(\d+)\s*hand/i', $value, $m)) {
                $hand ??= (int) $m[1];
            }
            // Bare number → treat as suitcases.
            if ($suitcases === null && $hand === null && preg_match('/^\s*(\d+)\s*$/', $value, $m)) {
                $suitcases = (int) $m[1];
            }
        }

        return [(int) ($suitcases ?? 0), (int) ($hand ?? 0)];
    }

    /** Payment method + paid flag from the payment line or the whole text. */
    private function payment(?string $value, string $text): array
    {
        $source = $value ?: $text;
        $method = match (true) {
            (bool) preg_match('/\bcash\b/i', $source) => 'cash',
            (bool) preg_match('/\baccount\b/i', $source) => 'account',
            default => 'card', // Square/Stripe/card/unknown → card
        };

        // Paid when it says so and nothing is still owing.
        $paid = (bool) preg_match('/\bpaid\b/i', $source)
            && ! preg_match('/\b(pending|due|outstanding|unpaid|owing|to\s*pay|balance\s*remaining|not\s*paid)\b/i', $source);

        return ['method' => $method, 'paid' => $paid];
    }

    /** The WHERE word for the calendar title, from whichever address is the airport. */
    private function where(?string $pickup, ?string $dropoff): string
    {
        foreach ([$pickup, $dropoff] as $address) {
            if (! $address) {
                continue;
            }
            $l = Str::lower($address);
            foreach (self::AIRPORTS as $needle => $code) {
                if (str_contains($l, $needle)) {
                    return $code;
                }
            }
            // A bare IATA-style token, e.g. "MAN T2".
            if (preg_match('/\b(MAN|LHR|LGW|STN|LTN|EMA|LBA|BHX|LPL|NCL|EDI|GLA|BRS|LCY|DSA|HUY|MME)\b/', $address, $m)) {
                return $m[1];
            }
        }

        // No airport → last locality of the destination (before any postcode).
        if ($dropoff) {
            $parts = array_map('trim', explode(',', $dropoff));
            for ($i = count($parts) - 1; $i >= 0; $i--) {
                $part = preg_replace('/\b[A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2}\b/i', '', $parts[$i]);
                $part = trim($part);
                if ($part !== '' && ! preg_match('/\d/', $part)) {
                    return Str::title($part);
                }
            }
        }

        return '';
    }

    private function phone(?string $value, string $text): string
    {
        foreach (array_filter([$value, $text]) as $source) {
            if (preg_match('/(\+44\s?\d{4}|\b07\d{3})[\s\-]?\d{3}[\s\-]?\d{3}\b/', $source, $m)) {
                return preg_replace('/[\s\-]/', '', $m[0]);
            }
        }

        return $value ?? '';
    }

    private function email(string $text): string
    {
        return preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/', $text, $m) ? $m[0] : '';
    }

    private function flight(?string $value, string $text): string
    {
        foreach (array_filter([$value, $text]) as $source) {
            if (preg_match('/\b([A-Z]{2,3}\s?\d{2,4})\b/', strtoupper($source), $m)) {
                $code = str_replace(' ', '', $m[1]);
                // Avoid mistaking a postcode for a flight number.
                if (! preg_match('/^[A-Z]{1,2}\d{1,2}[A-Z]?\d[A-Z]{2}$/', $code)) {
                    return $code;
                }
            }
            if ($value) {
                break; // a labelled value that didn't match shouldn't scan the whole text
            }
        }

        return $value && strcasecmp(trim($value), 'N/A') !== 0 ? strtoupper(trim($value)) : '';
    }
}
