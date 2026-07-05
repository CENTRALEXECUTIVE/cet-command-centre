<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Messaging\BookingNotifier;
use Illuminate\Console\Command;

/**
 * Makes sure every upcoming booking has its pickup reminder prepared and on the
 * "to send" list — including ones imported from ETO, which don't go through the
 * booking form. Idempotent: never duplicates an existing reminder.
 */
class PrepareReminders extends Command
{
    protected $signature = 'cet:prepare-reminders';

    protected $description = 'Prepare pickup reminders for all upcoming bookings';

    public function handle(BookingNotifier $notifier): int
    {
        $bookings = Booking::with('customer')
            ->where('pickup_at', '>=', now())
            ->whereNotIn('status', [
                BookingStatus::Cancelled->value,
                BookingStatus::NoShow->value,
                BookingStatus::Complete->value,
            ])
            ->get();

        foreach ($bookings as $booking) {
            $notifier->ensureReminders($booking);
        }

        $this->info("Checked {$bookings->count()} upcoming booking(s) for reminders.");

        return self::SUCCESS;
    }
}
