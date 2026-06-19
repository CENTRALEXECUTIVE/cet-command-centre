<?php

namespace App\Console\Commands;

use App\Services\Inbox\OutlookBookingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Backfills the calendar from an ETO CSV export by feeding each row through the
 * SAME logic the live email import uses (OutlookBookingService::upsertFromParsed)
 * — so imported bookings get identical formatting, reference-dedup (already in
 * the calendar → replaced, not re-added), pending→👀, stops, and rotation.
 *
 * Only real upcoming bookings are imported:
 *  - "Request quote" rows (blank Payments) are skipped — not real bookings.
 *  - Rows with no payment line are skipped (your rule: needs pending/cash/card).
 *  - Past jobs are skipped (already happened).
 *  - "Cancelled" rows cancel the matching reference if present.
 * Rows are processed in journey date/time order so rotation assigns correctly.
 */
class ImportEtoCalendar extends Command
{
    protected $signature = 'cet:import-eto-calendar {path} {--all : include past jobs too} {--rotate : auto-assign ABDI/MAJ rotation}';

    protected $description = 'Backfill the calendar from an ETO CSV (reference-keyed, no duplicates)';

    public function handle(OutlookBookingService $svc): int
    {
        $path = $this->argument('path');
        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error("Cannot open {$path}");

            return self::FAILURE;
        }

        $header = array_map('trim', fgetcsv($handle, 0, ';'));
        $rows = [];
        $cutoff = now()->startOfDay();

        while (($r = fgetcsv($handle, 0, ';')) !== false) {
            if (count(array_filter($r, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $d = array_combine($header, array_pad($r, count($header), ''));

            $status = trim($d['Status'] ?? '');
            if (strcasecmp($status, 'Request quote') === 0) {
                continue; // quote, not a booking
            }
            $isCancel = strcasecmp($status, 'Cancelled') === 0;
            $pay = trim($d['Payments'] ?? '');
            if ($pay === '' && ! $isCancel) {
                continue; // no payment line → not for the calendar
            }
            $dt = $this->parseDate($d['Journey date'] ?? '');
            if (! $dt) {
                continue;
            }
            if (! $this->option('all') && $dt->lt($cutoff)) {
                continue; // past job
            }

            $booked = $this->parseDate($d['Created at'] ?? '') ?? $dt;
            $rows[] = ['dt' => $dt, 'booked' => $booked, 'data' => $d, 'cancel' => $isCancel];
        }
        fclose($handle);

        // BOOKING order (when each was booked) so rotation assigns correctly —
        // first booked gets the next driver, then alternates.
        usort($rows, fn ($a, $b) => $a['booked'] <=> $b['booked']);

        // --rotate assigns ABDI/MAJ per the rotation (paired legs share a driver);
        // without it the jobs come in unassigned for manual allocation.
        $allocate = (bool) $this->option('rotate');

        $stats = ['created' => 0, 'updated' => 0, 'cancelled' => 0, 'skipped' => 0];
        foreach ($rows as $row) {
            $parsed = $this->toParsed($row['data'], $row['dt'], $row['cancel']);
            try {
                $result = $svc->upsertFromParsed($parsed, allocateRotation: $allocate);
                $result ? $stats[$result['action']]++ : $stats['skipped']++;
            } catch (\Illuminate\Database\QueryException $e) {
                $stats['skipped']++; // reference already exists (unique index) — no duplicate
            }
        }

        $this->info("Imported {$stats['created']}, updated {$stats['updated']}, cancelled {$stats['cancelled']}, "
            ."skipped {$stats['skipped']} (of ".count($rows).' eligible rows). Run cet:sync-calendar to push any pending.');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function toParsed(array $d, Carbon $dt, bool $isCancel): array
    {
        $ref = trim($d['Reference number'] ?? '');
        if ($isCancel) {
            return ['is_booking' => true, 'cancelled' => true, 'reference' => $ref];
        }

        $pay = trim($d['Payments'] ?? '');
        $lead = trim($d['Lead passenger name'] ?? '');
        $leadPhone = trim($d['Lead passenger phone number'] ?? '');
        $leadEmail = trim($d['Lead passenger email'] ?? '');

        return [
            'is_booking' => true,
            'cancelled' => false,
            'reference' => $ref,
            'customer_name' => $lead ?: (trim($d['Passenger name'] ?? '') ?: 'ETO customer'),
            'customer_phone' => $leadPhone ?: (trim($d['Phone number'] ?? '') ?: null),
            'customer_email' => $leadEmail ?: (trim($d['Email'] ?? '') ?: null),
            'booker_name' => trim($d['Customer'] ?? '') ?: null,
            'pickup_address' => $this->decode($d['Pickup'] ?? '') ?: 'Unknown',
            'destination_address' => $this->decode($d['Dropoff'] ?? '') ?: 'Unknown',
            'pickup_at' => $dt->format('Y-m-d H:i'),
            'passengers' => 1,
            'luggage' => 0,
            'vehicle_type' => trim($d['Vehicle type'] ?? '') ?: null,
            'flight_number' => trim($d['Arrival flight number'] ?? '') ?: (trim($d['Departure flight number'] ?? '') ?: null),
            'meet_and_greet' => (bool) preg_match('/\b(yes|required|true)\b/i', $d['Meet & Greet'] ?? ''),
            'child_seat' => false,
            'notes' => null,
            'stops' => $this->stops($this->decode($d['Via'] ?? '')),
            'payment_status' => stripos($pay, 'pending') !== false ? 'pending' : (stripos($pay, 'paid') !== false ? 'paid' : 'pending'),
            'payment_method' => stripos($pay, 'cash') !== false ? 'Cash' : 'Card',
            'payment_text' => $this->formatPayment($pay),
            'total_amount' => is_numeric(trim($d['Total'] ?? '')) ? (float) trim($d['Total']) : null,
        ];
    }

    /** "Paid, Square, 310 | Pending, Cash, 90" → "£310 (Square) - Paid | £90 (Cash) - Pending". */
    private function formatPayment(string $pay): ?string
    {
        $pay = trim($pay);
        if ($pay === '') {
            return null;
        }
        $out = [];
        foreach (explode('|', $pay) as $seg) {
            $p = array_map('trim', explode(',', $seg));
            if (count($p) >= 3) {
                $out[] = "£{$p[2]} ({$p[1]}) - {$p[0]}";
            } else {
                $out[] = trim($seg);
            }
        }

        return implode(' | ', $out);
    }

    /** @return list<string> */
    private function stops(string $via): array
    {
        $via = trim($via);
        if ($via === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\s*\|\s*|\r\n|\r|\n/', $via))));
    }

    private function decode(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
    }

    private function parseDate(?string $value): ?Carbon
    {
        $value = trim((string) $value);
        foreach (['d/m/Y H:i', 'd/m/Y H:i:s', 'd/m/Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
