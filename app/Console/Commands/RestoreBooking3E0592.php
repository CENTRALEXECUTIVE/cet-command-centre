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

/**
 * ONE-OFF repair. Booking CET-3E0592 (a covering job for "Lawrence", ref Ryanhn)
 * was polluted by the old calendar-refresh bug: it was rewritten with Penny
 * Coates' 30 Aug details and its calendar event got linked to Penny's Google
 * event. This restores Lawrence's details, points the booking at a Lawrence
 * customer (never renaming Penny's shared record), clears the poisoned calendar
 * link and rebuilds a fresh event under the Ryanhn reference.
 *
 * Safe + idempotent: it only touches CET-3E0592, never Penny's real booking
 * (CET-09A10D) or her Google event. Run with --dry to preview.
 */
class RestoreBooking3E0592 extends Command
{
    protected $signature = 'cet:restore-3e0592 {--dry : Show what would change without saving}';

    protected $description = 'Restore the polluted CET-3E0592 booking to the Lawrence covering job';

    public function handle(CalendarEventBuilder $calendar): int
    {
        $booking = Booking::where('reference', 'CET-3E0592')->first()
            ?? Booking::where('reference', 'like', '%3E0592%')->first();
        if (! $booking) {
            $this->error('CET-3E0592 not found — nothing to do.');

            return self::SUCCESS;
        }
        $this->line('Matched booking id '.$booking->id.' (reference '.$booking->reference.').');

        $estate = VehicleType::where('slug', 'estate')->first()
            ?? VehicleType::where('name', 'like', '%Estate%')->first();
        if (! $estate) {
            $this->error('No Estate vehicle type found — seed vehicle types first.');

            return self::FAILURE;
        }

        // The correct covering-job details (from the original booking message).
        $target = [
            'external_reference' => 'Ryanhn',
            'vehicle_type_id' => $estate->id,
            'pickup_at' => Carbon::createFromFormat('Y-m-d H:i', '2026-09-23 15:05', config('app.timezone')),
            'pickup_address' => 'Manchester Airport',
            'destination_address' => '19 Horsewood Road S13 9WL',
            'flight_number' => 'LS1754',
            'passengers' => 2,
            'luggage' => 2,
            'status' => BookingStatus::Pending->value,
            'driver_id' => null,
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'quoted_price' => null,
            'final_price' => null,
        ];

        $this->line('Booking CET-3E0592 currently shows: '.$booking->displayName()
            .' · '.$booking->pickup_at?->format('D d M Y, H:i')
            .' · '.$booking->pickup_address.' → '.$booking->destination_address);
        $this->line('Will restore to: Lawrence · Wed 23 Sep 2026, 15:05 · Manchester Airport → 19 Horsewood Road S13 9WL · Estate · ref Ryanhn');

        if ($this->option('dry')) {
            $this->warn('Dry run — no changes saved.');

            return self::SUCCESS;
        }

        // 1. The critical restore — always committed on its own, so a later
        //    calendar-rebuild hiccup can never roll it back.
        DB::transaction(function () use ($booking, $target) {
            // A Lawrence customer — reuse one already on his number, else create a
            // fresh record. NEVER rename an existing (possibly Penny's) record.
            $lawrence = Customer::where('phone', '07868882217')->where('name', 'like', '%Lawrence%')->first()
                ?? Customer::create(['name' => 'Lawrence', 'phone' => '07868882217']);

            // Rebuild clean meta: drop the pollution (Penny's driver_details,
            // payment_text, audit flags, edited markers, bad calendar link).
            $meta = [
                'lead_name' => 'Lawrence',
                'where' => 'MAN',
                'contact_no' => '07868882217',
                'suitcases' => 2,
                'hand_luggage' => 0,
                'driver_tag' => 'COVER',
                'source_note' => 'Covering job for another driver (ref Ryanhn). Restored from data mix.',
            ];

            $booking->forceFill(array_merge($target, [
                'customer_id' => $lawrence->id,
                'special_requests' => null,
                'meta' => $meta,
            ]))->save();

            // Drop the poisoned calendar link (it points at Penny's Google event).
            $booking->calendarEvents()->delete();
        });

        // 2. Rebuild a fresh calendar event carrying the Ryanhn reference +
        //    Lawrence's details — best-effort, OUTSIDE the transaction so a rebuild
        //    problem never undoes the restore above.
        try {
            $calendar->buildFor($booking->fresh(['customer', 'vehicleType', 'airport', 'driver']));
        } catch (\Throwable $e) {
            $this->warn('Restored the booking, but rebuilding its calendar event failed: '.$e->getMessage());
            $this->warn('Run cet:sync-calendar (or open + save the booking) to rebuild it.');
        }

        $fresh = $booking->fresh(['customer', 'calendarEvent']);
        $this->info('Restored. CET-3E0592 is now: '.$fresh->displayName()
            .' · '.$fresh->pickup_at?->format('D d M Y, H:i')
            .' · ref '.$fresh->external_reference);
        $this->line('Penny Coates\' real booking (CET-09A10D) was not touched.');
        $this->line('Run cet:sync-calendar to push the fresh event to Google.');

        return self::SUCCESS;
    }
}
