<?php

namespace App\Services\Import;

use App\Models\Booking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Audits an ETO bookings CSV against Command Centre: for each booking reference
 * it confirms the booking exists, is on the calendar, has a synced event in the
 * correct CET title format, matches on pickup time, and carries a fare. Anything
 * off is listed as an issue and flagged on the booking (meta['audit_issues']) so
 * it shows a marker. Read-only against the calendar — never edits it.
 */
class EtoAuditService
{
    /**
     * @return array{results: array<int, array<string, mixed>>, counts: array<string, int>}
     */
    public function audit(string $path, bool $flag = true): array
    {
        $rows = $this->readCsv($path);
        $results = [];
        $counts = ['checked' => 0, 'ok' => 0, 'flagged' => 0, 'missing' => 0];

        foreach ($rows as $row) {
            if (! $this->isBookingRow($row['Status'] ?? '')) {
                continue; // quote request / non-booking
            }
            $ref = $this->clean($row['Reference number'] ?? '');
            if ($ref === '') {
                continue;
            }

            $counts['checked']++;
            $result = $this->check($ref, $row, $flag);
            $results[] = $result;
            $counts[$result['status']]++;
        }

        // Flagged first, then missing, then OK — so problems are at the top.
        usort($results, fn ($a, $b) => [$this->rank($a['status']), $a['pickup'] ?? '']
            <=> [$this->rank($b['status']), $b['pickup'] ?? '']);

        return ['results' => $results, 'counts' => $counts];
    }

    private function rank(string $status): int
    {
        return ['flagged' => 0, 'missing' => 1, 'ok' => 2][$status] ?? 3;
    }

    /** @return array<string, mixed> */
    private function check(string $ref, array $row, bool $flag): array
    {
        $name = $this->clean($row['Lead passenger name'] ?? '') ?: $this->clean($row['Passenger name'] ?? '') ?: '—';
        $booking = Booking::where('external_reference', $ref)->with('calendarEvent')->first();

        if (! $booking) {
            return [
                'reference' => $ref, 'name' => $name, 'pickup' => null, 'url' => null,
                'status' => 'missing', 'issues' => ['Not in the system — import it'],
            ];
        }

        $issues = [];
        $ev = $booking->calendarEvent;
        // Cancelled / no-show jobs are legitimately off the calendar — don't flag those.
        $liveJob = $booking->status?->isActive() ?? true;

        if (! $ev || blank($ev->google_event_id)) {
            if ($liveJob) {
                $issues[] = 'Not on the calendar';
            }
        } else {
            if ($ev->sync_status !== 'synced') {
                $issues[] = 'Calendar event not synced ('.$ev->sync_status.')';
            }
            if (! $this->titleOk($ev->title)) {
                $issues[] = 'Calendar title not in CET format';
            }
        }

        // Pickup time: catches timezone / edit drift against the ETO record.
        $csvAt = $this->parseDate($row['Journey date'] ?? '');
        if ($csvAt && $booking->pickup_at && $csvAt->format('H:i') !== $booking->pickup_at->format('H:i')) {
            $issues[] = 'Pickup time differs — ETO '.$csvAt->format('H:i').' vs system '.$booking->pickup_at->format('H:i');
        }

        $total = $this->money($row['Total'] ?? null);
        if ($total > 0 && ! ($booking->final_price ?? $booking->quoted_price)) {
            $issues[] = 'No fare stored (ETO £'.number_format($total, 2).')';
        }

        if ($flag) {
            $booking->forceFill(['meta' => array_merge($booking->meta ?? [], [
                'audit_issues' => $issues,
                'audited_at' => now()->toDateTimeString(),
            ])])->save();
        }

        return [
            'reference' => $ref, 'name' => $name, 'pickup' => $booking->pickup_at,
            'url' => route('bookings.show', $booking),
            'status' => $issues === [] ? 'ok' : 'flagged', 'issues' => $issues,
        ];
    }

    /** CET title format: *[emoji ]Name WHERE (TAG)* — asterisks + a bracket tag. */
    private function titleOk(?string $title): bool
    {
        return (bool) preg_match('/^\*.+\([^)]+\)\*$/u', trim((string) $title));
    }

    private function isBookingRow(string $status): bool
    {
        $s = Str::lower(trim($status));

        return $s !== '' && ! str_contains($s, 'quote') && ! str_contains($s, 'request');
    }

    /** @return array<int, array<string, string>> */
    private function readCsv(string $path): array
    {
        $lines = array_filter(array_map('trim', file($path) ?: []));
        if (! $lines) {
            return [];
        }
        $delim = str_contains($lines[0], ';') ? ';' : ',';
        $header = array_map(fn ($h) => trim($h, "\" \t"), str_getcsv(array_shift($lines), $delim));

        $rows = [];
        foreach ($lines as $line) {
            $cells = str_getcsv($line, $delim);
            $rows[] = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
        }

        return $rows;
    }

    private function parseDate(?string $value): ?Carbon
    {
        $value = trim((string) $value);
        foreach (['d/m/Y H:i', 'd/m/Y H:i:s', 'd/m/Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value, 'UTC')->setTimezone(config('app.timezone'));
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function money(mixed $value): float
    {
        $value = preg_replace('/[^0-9.]/', '', (string) $value);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value));
    }
}
