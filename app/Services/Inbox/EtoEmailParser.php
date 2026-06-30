<?php

namespace App\Services\Inbox;

use Illuminate\Support\Carbon;

/**
 * Free, deterministic parser for EasyTaxiOffice (ETO) booking emails.
 *
 * ETO notifications arrive in a fixed "Label: value" template, e.g.
 *
 *   New booking CWJB1S has been created.
 *   Journey
 *   Date & time:    22/06/2026 11:45
 *   Pickup:         Manchester Airport (MAN), Terminal 2, Manchester, UK
 *   Dropoff:        North Lakes Hotel & Spa, Ullswater Road, Penrith, UK
 *   ...
 *   Lead passenger
 *   Name:           Christian Michel
 *   Phone number:   +447741612887
 *   Reservation
 *   Reference number: CWJB1S
 *
 * Because the layout is constant this is more reliable (and entirely free) than
 * sending the email to an AI. It returns the SAME array shape the AI path used,
 * so OutlookBookingService::upsertFromParsed() is unchanged. Returns null when
 * the text is not a recognisable ETO booking email (the caller may then fall
 * back to the optional AI parser, if a key is configured).
 *
 * Dates are UK local (DD/MM/YYYY HH:MM, "Time zone: UTC+1 London") and parsed in
 * the app timezone (Europe/London in production).
 */
class EtoEmailParser
{
    /** Section headings in the ETO template (lines with no "label: value"). */
    private const SECTIONS = ['journey', 'customer', 'lead passenger', 'reservation'];

    /**
     * @return array<string, mixed>|null
     */
    public function parse(string $subject, string $body, ?string $from = null): ?array
    {
        $text = trim($subject."\n".$body);
        if ($text === '') {
            return null;
        }

        // A quote request is NOT a booking — it must never reach the calendar.
        if (preg_match('/\bquote\b/i', $subject) || preg_match('/\b(request(?:ed)? a quote|quote request|new quote|quotation)\b/i', $text)) {
            return null;
        }

        $action = $this->detectAction($text);
        $fields = $this->fieldsBySection($body);
        $reference = $this->get($fields, 'reservation', 'reference number')
            ?? $this->getFlat($fields, 'reference number')
            ?? $this->headlineReference($text);

        // Only a confirmed booking notification (created / amended / cancelled)
        // is imported. No action (e.g. a quote or other mail) → not a booking.
        if (! $reference || ! $action) {
            return null;
        }

        if ($action === 'cancelled') {
            return ['is_booking' => true, 'cancelled' => true, 'reference' => $reference];
        }

        $pickupAt = $this->parseDateTime(
            $this->get($fields, 'journey', 'date & time') ?? $this->getFlat($fields, 'date & time')
        );
        $pickup = $this->getFlat($fields, 'pickup');
        $dropoff = $this->getFlat($fields, 'dropoff');

        // A new/amended booking needs the core journey details to be usable.
        if (! $pickupAt || ! $pickup || ! $dropoff) {
            return null;
        }

        // The lead passenger is who actually travels and carries the phone the
        // driver calls; fall back to the booking customer when absent.
        $name = $this->get($fields, 'lead passenger', 'name')
            ?? $this->get($fields, 'customer', 'name')
            ?? $this->getFlat($fields, 'name');
        $phone = $this->get($fields, 'lead passenger', 'phone number')
            ?? $this->get($fields, 'customer', 'phone number')
            ?? $this->getFlat($fields, 'phone number');
        $email = $this->get($fields, 'lead passenger', 'email')
            ?? $this->get($fields, 'customer', 'email')
            ?? $this->getFlat($fields, 'email')
            ?? $from;

        $flight = $this->getFlat($fields, 'arrival flight number')
            ?? $this->getFlat($fields, 'departure flight number')
            ?? $this->getFlat($fields, 'flight number');
        $meetGreet = $this->isYes($this->getFlat($fields, 'meet & greet') ?? $this->getFlat($fields, 'meet and greet'));
        $notes = $this->getFlat($fields, 'comments') ?? $this->getFlat($fields, 'notes');
        $payment = $this->payment($fields);

        // Child/booster/infant seat anywhere in the email → 🚼.
        $childSeats = $this->seatCount($body, 'child|baby|infant|car');
        $boosterSeats = $this->seatCount($body, 'booster');
        $childSeat = $childSeats > 0 || $boosterSeats > 0;

        return [
            'is_booking' => true,
            'cancelled' => false,
            'reference' => $reference,
            'child_seats' => $childSeats,
            'booster_seats' => $boosterSeats,
            'customer_name' => $name ?? 'ETO customer',
            'customer_phone' => $phone,
            'customer_email' => $email,
            'booker_name' => $this->get($fields, 'customer', 'name'),
            'pickup_address' => $pickup,
            'destination_address' => $dropoff,
            'pickup_at' => $pickupAt,
            'passengers' => (int) ($this->getFlat($fields, 'passengers') ?: 1),
            'suitcases' => (int) ($this->getFlat($fields, 'suitcases') ?? 0),
            'hand_luggage' => (int) ($this->getFlat($fields, 'hand luggage')
                ?? $this->getFlat($fields, 'luggage') ?? 0),
            'luggage' => (int) ($this->getFlat($fields, 'hand luggage')
                ?? $this->getFlat($fields, 'suitcases')
                ?? $this->getFlat($fields, 'luggage') ?? 0),
            'stops' => $this->stops($body),
            'vehicle_type' => $this->getFlat($fields, 'vehicle type'),
            'flight_number' => $flight,
            'meet_and_greet' => $meetGreet,
            'child_seat' => $childSeat,
            'notes' => $notes,
            'payment_status' => $payment['status'],
            'payment_method' => $payment['method'],
            'payment_text' => $payment['text'],
            'total_amount' => $payment['total'],
        ];
    }

    /**
     * Read the ETO payment lines, e.g.
     *   Total:    £110
     *   Payments: £110 (Square) - Paid
     * Returns the status (paid|deposit|partial|pending), the method label
     * (Square/Stripe/Cash/Card), the gross total, and a display string.
     *
     * @param array{sections: array<string, array<string, string>>, flat: array<string, string>} $fields
     * @return array{status: string, method: string|null, total: float|null, text: string|null}
     */
    private function payment(array $fields): array
    {
        $payments = $this->getFlat($fields, 'payments') ?? '';
        $total = $this->money($this->getFlat($fields, 'total'));

        // ETO lists each part on its own line, e.g.
        //   "Deposit £150 (Square) - Paid"
        //   "Balance £150 (Square) - Paid"   → FULLY PAID (no balance owed)
        // or "Balance £150 (Square) - Pending" → a real outstanding balance.
        // The status hinges on whether anything is still OUTSTANDING, not on the
        // first word seen — matching "Deposit" first wrongly flagged fully-paid
        // jobs as partial (a false 👀 on the calendar).
        $outstanding = (bool) preg_match('/\b(pending|due|outstanding|unpaid|owing|owed|to\s*pay|balance\s*remaining)\b/i', $payments);
        $mentionsPaid = (bool) preg_match('/\bpaid\b/i', $payments);

        if ($mentionsPaid && ! $outstanding) {
            $status = 'paid'; // every part paid → fully paid
        } elseif (preg_match('/\bdeposit\b/i', $payments) && $outstanding) {
            $status = 'deposit'; // deposit taken, balance still owed
        } elseif (preg_match('/\bpart(?:ial(?:ly)?)?\b/i', $payments) && $outstanding) {
            $status = 'partial';
        } elseif ($outstanding) {
            $status = 'pending';
        } elseif ($mentionsPaid) {
            $status = 'paid';
        } else {
            $status = 'pending';
        }

        // Method(s) in brackets, e.g. "(Square)", "(Stripe)". A deposit+balance
        // split paid by two methods → "Stripe + Square".
        $method = null;
        if (preg_match_all('/\(([A-Za-z][A-Za-z ]*)\)/', $payments, $mm)) {
            $methods = array_values(array_unique(array_map('trim', $mm[1])));
            $method = implode(' + ', $methods);
        } elseif (stripos($payments, 'cash') !== false) {
            $method = 'Cash';
        }

        // Standard display: "Paid £215 (Square)" — Paid first, never a dash (rule 6).
        $amount = $total !== null ? '£'.rtrim(rtrim(number_format($total, 2), '0'), '.') : '';
        $methodPart = $method ? " ({$method})" : '';
        if ($status === 'paid') {
            $text = trim("Paid {$amount}{$methodPart}");
        } elseif ($amount !== '') {
            $text = trim(ucfirst($status)." {$amount}{$methodPart}");
        } else {
            $text = trim($payments) ?: null;
        }

        return [
            'status' => $status,
            'method' => $method,
            'total' => $total,
            'text' => $text,
        ];
    }

    /**
     * Intermediate "Via" stops, which ETO lists between Pickup and Dropoff (one
     * per line, the first carrying the "Via:" label).
     *
     * @return list<string>
     */
    private function stops(string $body): array
    {
        if (! preg_match('/\bVia:\s*(.+?)\n\s*(?:Dropoff|Drop-off|Vehicle type|Passengers)\b/is', $body, $m)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\n/', $m[1]))));
    }

    private function money(?string $value): ?float
    {
        if ($value && preg_match('/([\d,]+(?:\.\d{2})?)/', $value, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }

        return null;
    }

    /** Count of a seat type in the email, e.g. "2 child seats" → 2, else 1 if mentioned. */
    private function seatCount(string $body, string $types): int
    {
        if (preg_match('/(\d+)\s*(?:x\s*)?(?:'.$types.')\s*seat/i', $body, $m)) {
            return (int) $m[1];
        }

        return preg_match('/\b(?:'.$types.')\s*seat/i', $body) ? 1 : 0;
    }

    private function isYes(?string $value): bool
    {
        return $value !== null && preg_match('/\b(required|yes|true|y)\b/i', $value) === 1;
    }

    /** created | amended | cancelled | null, from the email headline/subject. */
    private function detectAction(string $text): ?string
    {
        if (preg_match('/\bhas been cancell?ed\b/i', $text)) {
            return 'cancelled';
        }
        if (preg_match('/\bhas been (amended|updated|changed|modified|edited|rebooked)\b/i', $text)) {
            return 'amended';
        }
        if (preg_match('/\bhas been created\b/i', $text) || preg_match('/\bnew booking\b/i', $text)) {
            return 'created';
        }

        return null;
    }

    /** "...booking CWJB1S has been..." → CWJB1S */
    private function headlineReference(string $text): ?string
    {
        if (preg_match('/\bbooking\s+([A-Z0-9]{5,10})\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    /**
     * Split the body into sections of "label => value" pairs. Labels can repeat
     * across sections (e.g. Name/Email under both Customer and Lead passenger),
     * so we key by section and also keep a flat first-seen map.
     *
     * @return array{sections: array<string, array<string, string>>, flat: array<string, string>}
     */
    private function fieldsBySection(string $body): array
    {
        $sections = [];
        $flat = [];
        $current = 'general';

        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $heading = strtolower($line);
            if (in_array($heading, self::SECTIONS, true)) {
                $current = $heading;

                continue;
            }

            if (preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
                $label = strtolower(trim($m[1]));
                $value = trim($m[2]);
                if ($value === '') {
                    continue;
                }
                $sections[$current][$label] ??= $value;
                $flat[$label] ??= $value;
            }
        }

        return ['sections' => $sections, 'flat' => $flat];
    }

    /** @param array{sections: array<string, array<string, string>>, flat: array<string, string>} $fields */
    private function get(array $fields, string $section, string $label): ?string
    {
        return $fields['sections'][$section][$label] ?? null;
    }

    /** @param array{sections: array<string, array<string, string>>, flat: array<string, string>} $fields */
    private function getFlat(array $fields, string $label): ?string
    {
        return $fields['flat'][$label] ?? null;
    }

    /** "22/06/2026 11:45" (UK local) → "2026-06-22 11:45". */
    private function parseDateTime(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        // Trim any trailing timezone note and keep "DD/MM/YYYY HH:MM".
        if (preg_match('#(\d{1,2}/\d{1,2}/\d{4})\s+(\d{1,2}:\d{2})#', $value, $m)) {
            try {
                return Carbon::createFromFormat('d/m/Y H:i', $m[1].' '.$m[2])->format('Y-m-d H:i');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
