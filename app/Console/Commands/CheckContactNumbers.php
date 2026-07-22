<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Data-integrity check: finds bookings whose linked customer record carries a
 * DIFFERENT phone number to the booking's calendar "Contact No".
 *
 * A mismatch means the booking got filed under a customer record holding another
 * booker's number (historically caused by matching customers on a shared email),
 * so anything reading the record's phone instead of the booking would reach the
 * wrong person. The calendar is the source of truth.
 *
 *   php artisan cet:check-contact-numbers          # report only
 *   php artisan cet:check-contact-numbers --fix     # correct each record's phone
 *                                                     to its booking's calendar number
 *
 * --fix is safe for one-way / single-booking customers. Where several bookings
 * share one record it repairs to the calendar number of the newest, and every
 * such split is listed so you can review it — but note messaging already always
 * reads the calendar contact, so this only tidies the stored record.
 */
class CheckContactNumbers extends Command
{
    protected $signature = 'cet:check-contact-numbers {--fix : Repair each record\'s phone to its booking\'s calendar number}';

    protected $description = 'Flag bookings whose customer record phone differs from the calendar contact';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');

        $bookings = Booking::query()
            ->whereHas('calendarEvent')
            ->with(['customer', 'calendarEvent'])
            ->get();

        $mismatches = [];
        foreach ($bookings as $booking) {
            if ($calendar = $booking->contactNumberMismatch()) {
                $mismatches[] = [$booking, $calendar];
            }
        }

        if (empty($mismatches)) {
            $this->info('All good — every booking\'s contact number matches its calendar.');

            return self::SUCCESS;
        }

        $this->warn(count($mismatches).' booking(s) where the customer record phone differs from the calendar:');
        $this->newLine();

        $repaired = 0;
        foreach ($mismatches as [$booking, $calendar]) {
            $ref = $booking->external_reference ?: $booking->reference;
            $name = $booking->displayCustomerName() ?: ($booking->customer?->name ?? 'Unknown');
            $record = $booking->customer?->phone;

            $this->line("  <fg=yellow>{$ref}</> {$name}");
            $this->line("      record:   {$record}");
            $this->line("      calendar: {$calendar}  <fg=green>(correct)</>");

            if ($fix && $booking->customer) {
                $booking->customer->forceFill(['phone' => $calendar])->save();
                $repaired++;
            }
        }

        $this->newLine();
        if ($fix) {
            $this->info("Repaired {$repaired} customer record(s) to the calendar number.");
        } else {
            $this->line('Run with <fg=cyan>--fix</> to correct the stored records. (Messaging already uses the calendar number regardless.)');
        }

        return self::SUCCESS;
    }
}
