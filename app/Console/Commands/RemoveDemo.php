<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\Calendar\GoogleCalendarService;
use Database\Seeders\RotationSeeder;
use Illuminate\Console\Command;

/**
 * Removes demo/sample bookings (those NOT from the real ETO feed — i.e. no
 * source_system='eto') and deletes their Google Calendar entries, then resets
 * the rotation pointer to the seeded ABDI/MAJ order. Live ETO bookings (email +
 * imported history) are kept. Refuses to run in production.
 */
class RemoveDemo extends Command
{
    protected $signature = 'cet:remove-demo';

    protected $description = 'Delete demo/sample bookings and their calendar events; reset rotation';

    public function handle(GoogleCalendarService $calendar): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        // Demo = anything not tagged as a real ETO booking.
        $demo = Booking::whereNull('source_system')->orWhere('source_system', '!=', 'eto');
        $bookings = (clone $demo)->with('calendarEvent')->get();

        $eventsRemoved = 0;
        foreach ($bookings as $booking) {
            if ($booking->calendarEvent) {
                if ($calendar->delete($booking->calendarEvent)) {
                    $eventsRemoved++;
                }
                $booking->calendarEvent->delete();
            }
        }

        $count = (clone $demo)->forceDelete();

        // Reset the rotation pointer to the seeded order (demo jobs had advanced it).
        (new RotationSeeder)->run();

        $this->info("Removed {$count} demo booking(s), deleted {$eventsRemoved} calendar event(s), rotation reset.");

        return self::SUCCESS;
    }
}
