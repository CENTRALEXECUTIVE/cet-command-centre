<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\Calendar\GoogleCalendarService;
use Database\Seeders\RotationSeeder;
use Illuminate\Console\Command;

/**
 * Clean slate for ETO bookings: reports any duplicates/null references (for
 * diagnosis), deletes every ETO booking and its Google Calendar entry, and
 * resets the rotation pointer to the seeded order. Use to recover from a bad
 * import before re-importing cleanly. Refuses to run in production.
 */
class WipeEto extends Command
{
    protected $signature = 'cet:wipe-eto';

    protected $description = 'Delete all ETO bookings + their calendar entries and reset rotation (recovery)';

    public function handle(GoogleCalendarService $calendar): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $bookings = Booking::where('source_system', 'eto')->with('calendarEvent')->get();

        // Diagnosis: what duplicated, and how many had no reference.
        $byRef = $bookings->groupBy(fn ($b) => $b->external_reference ?? '(null)');
        $dupes = $byRef->filter(fn ($g) => $g->count() > 1);
        $this->line('Total ETO bookings:      '.$bookings->count());
        $this->line('Distinct references:     '.$byRef->count());
        $this->line('Null-reference bookings: '.(isset($byRef['(null)']) ? $byRef['(null)']->count() : 0));
        $this->line('Duplicated references:   '.$dupes->count());
        foreach ($dupes->take(25) as $ref => $group) {
            $this->line("   {$ref} ×{$group->count()}");
        }

        // Delete the Google Calendar entries first (need the event ids).
        $eventsDeleted = 0;
        foreach ($bookings as $booking) {
            if ($booking->calendarEvent) {
                if ($calendar->delete($booking->calendarEvent)) {
                    $eventsDeleted++;
                }
            }
        }

        $deleted = Booking::where('source_system', 'eto')->forceDelete();
        (new RotationSeeder)->run();

        $this->newLine();
        $this->info("Deleted {$deleted} ETO booking(s), removed {$eventsDeleted} calendar entr(ies), rotation reset.");

        return self::SUCCESS;
    }
}
