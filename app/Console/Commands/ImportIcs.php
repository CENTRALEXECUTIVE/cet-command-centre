<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\Customer;
use App\Models\VehicleType;
use App\Models\Setting;
use App\Services\Calendar\GoogleCalendarService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Restores the calendar from an ICS export (the operator's known-good "base").
 * Each VEVENT is recreated EXACTLY (title, description, times, location) and a
 * matching booking is stored — keyed on the booking reference (or the event UID
 * for older reference-less jobs) — so the live email feed updates these rather
 * than duplicating them. Run cet:purge-calendar first for a clean rebuild.
 */
class ImportIcs extends Command
{
    protected $signature = 'cet:import-ics {path}';

    protected $description = 'Restore the calendar from an ICS export (exact base) and create matching bookings';

    public function handle(GoogleCalendarService $google): int
    {
        $raw = @file_get_contents($this->argument('path'));
        if ($raw === false) {
            $this->error('Cannot read '.$this->argument('path'));

            return self::FAILURE;
        }

        $raw = preg_replace("/\r?\n[ \t]/", '', $raw); // unfold folded lines
        preg_match_all('/BEGIN:VEVENT.*?END:VEVENT/s', $raw, $m);
        $calendarId = Setting::get('calendar_id', 'admin@centralexecutivetransfers.co.uk');

        $stats = ['restored' => 0, 'pushed' => 0, 'skipped' => 0];
        $vehicles = VehicleType::pluck('id', 'slug');

        foreach ($m[0] as $block) {
            $summary = $this->unescape($this->prop($block, 'SUMMARY'));
            $description = $this->unescape($this->prop($block, 'DESCRIPTION'));
            $location = $this->unescape($this->prop($block, 'LOCATION'));
            $start = $this->date($this->prop($block, 'DTSTART'));
            $end = $this->date($this->prop($block, 'DTEND'));
            $uid = $this->prop($block, 'UID');

            if (! $summary || ! $start) {
                $stats['skipped']++;

                continue;
            }
            $end = $end ?: $start->copy()->addHour();

            // Reference = the booking reference in the description, else the UID
            // (so old reference-less jobs are still tracked and not duplicated).
            $ref = preg_match('/Booking Reference:\*?\s*([A-Za-z0-9]+)/', $description, $r)
                ? $r[1]
                : 'ics-'.substr(md5($uid ?: $summary.$start), 0, 12);

            // If this reference is already restored with a richer (longer)
            // description, keep that one — skip this duplicate copy from the ICS.
            $already = Booking::where('source_system', 'eto')->where('external_reference', $ref)->with('calendarEvent')->first();
            if ($already?->calendarEvent && strlen((string) $already->calendarEvent->description) >= strlen($description)) {
                $stats['skipped']++;

                continue;
            }

            $customer = $this->resolveCustomer($description, $summary);
            $vehicleId = $this->resolveVehicle($description, $summary, $vehicles);

            $booking = Booking::updateOrCreate(
                ['source_system' => 'eto', 'external_reference' => $ref],
                [
                    'reference' => Booking::generateReference(),
                    'customer_id' => $customer->id,
                    'vehicle_type_id' => $vehicleId,
                    'pickup_at' => $start,
                    'pickup_address' => $location ?: ($this->field($description, 'Pickup Location') ?: 'See description'),
                    'destination_address' => $this->field($description, 'Drop-off Location') ?: 'See description',
                    'status' => BookingStatus::Pending->value,
                    'payment_method' => 'card',
                    'source' => 'ics',
                    'meta' => ['restored_from_ics' => true],
                ]
            );

            // Store the literal ICS event so it renders EXACTLY as the base.
            $event = CalendarEvent::updateOrCreate(
                ['booking_id' => $booking->id],
                [
                    'calendar_id' => $calendarId,
                    'title' => $summary,
                    'description' => $description,
                    'location' => $location ?: null,
                    'start_at' => $start,
                    'end_at' => $end,
                    'timezone' => 'Europe/London',
                    'notifications' => $this->notifications($summary),
                    'sync_status' => 'pending',
                ]
            );
            $stats['restored']++;
            if ($google->push($event)) {
                $stats['pushed']++;
            }
        }

        $this->info("Restored {$stats['restored']} event(s) from the ICS, pushed {$stats['pushed']} to the calendar, skipped {$stats['skipped']}.");

        return self::SUCCESS;
    }

    private function prop(string $block, string $key): string
    {
        // Match "KEY:" or "KEY;params:" at line start; value is the rest of the line.
        if (preg_match('/^'.preg_quote($key, '/').'(?:;[^:\n]*)?:(.*)$/m', $block, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    private function unescape(string $s): string
    {
        $s = strtr($s, ['\\\\' => "\x00", '\\n' => "\n", '\\N' => "\n", '\\,' => ',', '\\;' => ';']);

        return str_replace("\x00", '\\', $s);
    }

    private function date(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return str_ends_with($value, 'Z')
                ? Carbon::createFromFormat('Ymd\THis\Z', $value, 'UTC')
                : Carbon::createFromFormat('Ymd\THis', $value, 'Europe/London');
        } catch (\Throwable) {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }
    }

    private function field(string $description, string $label): ?string
    {
        if (preg_match('/'.preg_quote($label, '/').':\*?\s*(.+)/', $description, $m)) {
            return trim(explode("\n", $m[1])[0]);
        }

        return null;
    }

    private function resolveCustomer(string $description, string $summary): Customer
    {
        $name = $this->field($description, 'Customer Name')
            ?? trim(preg_replace('/[*👀💰🚼]/u', '', Str::before($summary, ' (')))
            ?: 'Calendar event';
        // Strip a trailing airport code / route word from the summary-derived name.
        $name = trim(preg_replace('/\s+(MAN|LHR|LGW|STN|EMA|LBA|HUY|LPL|BHX|FREE ROAM|Exec|XL).*$/u', '', $name)) ?: $name;

        return Customer::firstOrCreate(['name' => $name], []);
    }

    private function resolveVehicle(string $description, string $summary, $vehicles): int
    {
        $text = strtolower($this->field($description, 'Vehicle Type').' '.$summary);
        $slug = match (true) {
            str_contains($text, 'minibus') => 'minibus-8',
            str_contains($text, 'v class') => 'v-class',
            str_contains($text, 'estate') => 'estate',
            str_contains($text, 'rolls') => 'rolls-royce-ghost',
            default => 'executive',
        };

        return $vehicles[$slug] ?? $vehicles['executive'];
    }

    /** @return array<int, array<string, mixed>> */
    private function notifications(string $summary): array
    {
        $n = [
            ['method' => 'email', 'minutes' => 120],
            ['method' => 'popup', 'minutes' => 180],
            ['method' => 'popup', 'minutes' => 420],
            ['method' => 'popup', 'minutes' => 1440],
        ];
        if (str_contains($summary, '👀') || str_contains($summary, '💰')) {
            $n[] = ['method' => 'popup', 'minutes' => 4320];
        }

        return $n;
    }
}
