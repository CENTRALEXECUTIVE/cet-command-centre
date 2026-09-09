<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Auto-allocate any upcoming, unallocated booking whose calendar title tag names
 * a system driver — so a job the calendar shows as ABDI or MAJ (or any named
 * driver) is assigned to them automatically, without the office allocating it by
 * hand. Mirrors the calendar; never changes it, never overrides an existing
 * assignment. Runs on the schedule so both existing and freshly-imported jobs are
 * picked up (import already does this for brand-new ones — this is the safety net).
 */
class AutoAllocateTagged extends Command
{
    protected $signature = 'cet:auto-allocate-tagged';

    protected $description = 'Allocate upcoming tagged bookings (ABDI/MAJ/named driver) to their driver';

    public function handle(): int
    {
        // Upcoming, still-UNALLOCATED jobs that either carry a stored driver tag
        // OR are linked to a calendar event we can read the "(ABDI)"/"(MAJ)" tag
        // off. We only ever FILL a blank driver slot — a job that already has a
        // driver (office allocation, last-minute cover, rotation) is never touched.
        $bookings = Booking::whereNull('driver_id')
            ->where('status', BookingStatus::Pending->value)
            ->where('pickup_at', '>=', now()->subHours(12))
            ->where(function ($q) {
                $q->whereNotNull('meta->driver_tag')
                    ->orWhereHas('calendarEvent');
            })
            ->with('calendarEvent')
            ->get();

        $changed = 0;
        foreach ($bookings as $booking) {
            if ($booking->reconcileDriverWithCalendarTag()) {
                $changed++;
            }
        }

        $this->info("Allocated {$changed} of {$bookings->count()} unallocated tagged booking(s) to the calendar driver.");

        return self::SUCCESS;
    }
}
