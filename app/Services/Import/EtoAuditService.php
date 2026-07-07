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

        // Problems first, then newest bookings before older ones within each group.
        usort($results, fn ($a, $b) => [$this->rank($a['status']), $this->stamp($b['pickup'])]
            <=> [$this->rank($b['status']), $this->stamp($a['pickup'])]);

        return ['results' => $results, 'counts' => $counts];
    }

    /**
     * Look a booking up by ETO reference OR customer/lead name and reconfirm it —
     * the on-demand single-booking check (no CSV needed). Time/fare-vs-ETO checks
     * are skipped (there's no ETO row), but every calendar/format/location check
     * still runs. Newest matches first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, bool $flag = true): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $bookings = Booking::query()
            ->with(['calendarEvent', 'customer'])
            ->where(function ($q) use ($query) {
                $q->where('external_reference', $query)
                    ->orWhere('reference', $query)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$query}%"))
                    ->orWhere('meta->lead_name', 'like', "%{$query}%");
            })
            ->orderByDesc('pickup_at')
            ->limit(25)
            ->get();

        return $bookings->map(fn (Booking $b) => $this->inspect($b, null, $flag,
            $b->external_reference ?? $b->reference,
            $b->meta['lead_name'] ?? $b->customer?->name ?? '—'))->all();
    }

    private function rank(string $status): int
    {
        return ['flagged' => 0, 'missing' => 1, 'ok' => 2][$status] ?? 3;
    }

    /** Sortable stamp for a pickup value (Carbon|string|null) — newest sorts first. */
    private function stamp(mixed $pickup): string
    {
        return $pickup instanceof \DateTimeInterface ? $pickup->format('Y-m-d H:i') : (string) $pickup;
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

        return $this->inspect($booking, $row, $flag, $ref, $name);
    }

    /**
     * Run every check against a booking. $row is the matching ETO CSV row when
     * auditing an export, or null for an on-demand search (time/fare-vs-ETO
     * comparisons are then skipped — everything else still runs).
     *
     * @param  array<string, string>|null  $row
     * @return array<string, mixed>
     */
    private function inspect(Booking $booking, ?array $row, bool $flag, string $ref, string $name): array
    {
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
            // The location field IS the pickup — if it's dropped off the event
            // the driver has no address on the calendar. This is the big one.
            if (blank($ev->location)) {
                $issues[] = 'Pickup location has dropped off the calendar event';
            }
            // The "Booking Confirmation" body (contact, flight, drop-off, ref…).
            if (blank($ev->description)) {
                $issues[] = 'Booking details block missing from the calendar event';
            }
            // Calendar time should match the booking's pickup time too.
            if ($ev->start_at && $booking->pickup_at && $ev->start_at->format('H:i') !== $booking->pickup_at->format('H:i')) {
                $issues[] = 'Calendar time ('.$ev->start_at->format('H:i').') doesn\'t match the booking ('.$booking->pickup_at->format('H:i').')';
            }
        }

        // Addresses present on the booking itself (importer writes 'Unknown' when blank).
        if ($liveJob && $this->addressMissing($booking->pickup_address)) {
            $issues[] = 'Pickup address is missing/unknown';
        }
        if ($liveJob && $this->addressMissing($booking->destination_address)) {
            $issues[] = 'Drop-off address is missing/unknown';
        }

        // The following compare against the ETO export row — only when auditing a CSV.
        if ($row !== null) {
            // Pickup time: catches timezone / edit drift against the ETO record.
            $csvAt = $this->parseDate($row['Journey date'] ?? '');
            if ($csvAt && $booking->pickup_at && $csvAt->format('H:i') !== $booking->pickup_at->format('H:i')) {
                $issues[] = 'Pickup time differs — ETO '.$csvAt->format('H:i').' vs system '.$booking->pickup_at->format('H:i');
            }

            // Pickup date: a full day off means it's on the wrong day on the calendar.
            if ($csvAt && $booking->pickup_at && $csvAt->format('Y-m-d') !== $booking->pickup_at->format('Y-m-d')) {
                $issues[] = 'Pickup date differs — ETO '.$csvAt->format('d/m/Y').' vs system '.$booking->pickup_at->format('d/m/Y');
            }

            $total = $this->money($row['Total'] ?? null);
            if ($total > 0 && ! ($booking->final_price ?? $booking->quoted_price)) {
                $issues[] = 'No fare stored (ETO £'.number_format($total, 2).')';
            }
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

    /** Blank, or the importer's "Unknown" placeholder for an absent address. */
    private function addressMissing(?string $address): bool
    {
        $a = Str::lower(trim((string) $address));

        return $a === '' || $a === 'unknown';
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
                // ETO's journey times are already UK local — the same clock as the
                // booking email and calendar. Parse in the app timezone; treating
                // them as UTC and adding a BST hour made the audit report the ETO
                // time an hour late and raise a phantom "pickup time differs".
                return Carbon::createFromFormat($format, $value, config('app.timezone'));
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
