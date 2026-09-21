<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\VehicleType;
use App\Services\CalendarEventBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Add a booking straight from the command line — for partner/third-party jobs
 * that arrive by email (e.g. "allocated to you by Birmingham Corporate Travel")
 * and aren't picked up by the automatic inbox ingest. Idempotent: keyed on the
 * partner reference, so running it twice updates rather than duplicates. Builds
 * the calendar-event mirror too, so the job shows fully and can be pushed to
 * Google from the day view. Never loses the booking.
 */
class AddJob extends Command
{
    protected $signature = 'cet:add-job
        {--ref= : partner/booking reference (dedupe key)}
        {--name= : lead passenger name (required)}
        {--phone= : passenger contact number}
        {--email= : passenger email}
        {--at= : pickup date & time, UK local, e.g. "2026-09-23 18:00" (required)}
        {--from= : pickup address (required)}
        {--to= : drop-off address (required)}
        {--vehicle=Executive : vehicle label (Executive, Estate, V Class, Minibus, Rolls Royce)}
        {--pax=1 : number of passengers}
        {--by= : who allocated it, e.g. "Birmingham Corporate Travel Ltd"}
        {--payment=account : cash|card|account}
        {--return : mark as a return journey}';

    protected $description = 'Add a booking from the command line (partner/third-party jobs)';

    public function handle(CalendarEventBuilder $calendar): int
    {
        foreach (['name', 'at', 'from', 'to'] as $required) {
            if (blank($this->option($required))) {
                $this->error("Missing --{$required}. Required: --name, --at, --from, --to.");

                return self::INVALID;
            }
        }

        $pickupAt = $this->parseTime((string) $this->option('at'));
        if (! $pickupAt) {
            $this->error('Could not read --at. Use "YYYY-MM-DD HH:MM", e.g. "2026-09-23 18:00".');

            return self::INVALID;
        }

        $ref = trim((string) $this->option('ref')) ?: null;
        $vehicleType = $this->resolveVehicleType((string) $this->option('vehicle'));

        $booking = DB::transaction(function () use ($ref, $pickupAt, $vehicleType, $calendar) {
            $customer = $this->resolveCustomer();

            $fields = [
                'customer_id' => $customer->id,
                'vehicle_type_id' => $vehicleType->id,
                'journey_type' => $this->option('return') ? 'return' : 'one_way',
                'pickup_at' => $pickupAt,
                'pickup_address' => trim((string) $this->option('from')),
                'destination_address' => trim((string) $this->option('to')),
                'passengers' => max(1, (int) $this->option('pax')),
                'status' => BookingStatus::Pending->value,
                'payment_method' => $this->payment(),
                'payment_status' => 'pending',
                'source' => 'partner',
                'source_system' => 'partner',
                'meta' => array_filter([
                    'lead_name' => trim((string) $this->option('name')),
                    'contact_no' => trim((string) $this->option('phone')) ?: null,
                    'booked_by' => trim((string) $this->option('by')) ?: null,
                    'created_from' => 'cli',
                ]),
            ];

            // Idempotent on the partner reference: update in place, never duplicate.
            $booking = $ref
                ? Booking::firstOrNew(['external_reference' => $ref])
                : new Booking(['external_reference' => null]);
            $booking->fill($fields);
            if (! $booking->exists) {
                $booking->reference = Booking::generateReference();
                $booking->external_reference = $ref;
            }
            $booking->save();

            // Build the calendar mirror (pending — not pushed to Google here).
            $calendar->buildFor($booking->refresh()->loadMissing(['customer', 'vehicleType', 'driver']));

            return $booking;
        });

        $this->info("Saved booking {$booking->reference}".($booking->external_reference ? " (ref {$booking->external_reference})" : ''));
        $this->line("  {$booking->pickup_at->format('D d M Y, H:i')} · ".($booking->meta['lead_name'] ?? '').' · '.$vehicleType->name);
        $this->line('  '.$booking->pickup_address.'  →  '.$booking->destination_address);
        $this->line('  Open it in Command Centre → Bookings, or the day view for '.$booking->pickup_at->format('D d M').'.');

        return self::SUCCESS;
    }

    private function resolveCustomer(): Customer
    {
        $phone = trim((string) $this->option('phone')) ?: null;
        $email = trim((string) $this->option('email')) ?: null;
        $name = trim((string) $this->option('name'));

        $customer = Customer::query()
            ->when($phone, fn ($q) => $q->orWhere('phone', $phone))
            ->when($email, fn ($q) => $q->orWhere('email', $email))
            ->first();

        return $customer ?? Customer::create(['name' => $name, 'phone' => $phone, 'email' => $email]);
    }

    private function payment(): string
    {
        $p = Str::lower(trim((string) $this->option('payment')));

        return in_array($p, ['cash', 'card', 'account'], true) ? $p : 'account';
    }

    private function resolveVehicleType(string $label): VehicleType
    {
        $l = Str::lower($label);
        $slug = match (true) {
            str_contains($l, 'v class') || str_contains($l, 'v-class') => 'v-class',
            str_contains($l, 'rolls') => 'rolls-royce-ghost',
            str_contains($l, 'estate') => 'estate',
            str_contains($l, 'xl') => 'minibus-8-xl',
            str_contains($l, 'minibus') || str_contains($l, '8 seat') => 'minibus-8',
            default => 'executive',
        };

        return VehicleType::where('slug', $slug)->first()
            ?? VehicleType::where('slug', 'executive')->firstOrFail();
    }

    private function parseTime(string $value): ?Carbon
    {
        $value = trim($value);
        foreach (['Y-m-d H:i', 'Y-m-d H:i:s', 'd/m/Y H:i', 'd/m/Y H:i:s'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value, config('app.timezone'));
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
