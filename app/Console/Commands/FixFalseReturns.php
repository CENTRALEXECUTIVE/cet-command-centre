<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * Heal bookings that ETO's a/b suffix wrongly paired as outbound/return when they
 * are actually two independent bookings on the SAME journey (same pickup — e.g.
 * two passengers on one flight to the same place, like Janine & Sean). Clears the
 * false "Return" label and the link so each shows as its own booking. Idempotent.
 */
class FixFalseReturns extends Command
{
    protected $signature = 'cet:fix-false-returns {--dry : report what would change without writing}';

    protected $description = 'Unlink bookings wrongly paired as a return when the route is not actually reversed';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $fixed = 0;

        Booking::query()
            ->where(fn ($q) => $q->where('is_return_leg', true)->orWhere('journey_type', 'return'))
            ->whereNotNull('linked_booking_id')
            ->with('linkedBooking')
            ->chunkById(200, function ($bookings) use (&$fixed, $dry) {
                foreach ($bookings as $booking) {
                    $sibling = $booking->linkedBooking;
                    // A genuine reversed-route return is left alone. Only a
                    // same-journey (same pickup) false pair is unlinked.
                    if ($sibling && $booking->isReversedRouteWith($sibling)) {
                        continue;
                    }

                    $ref = $booking->external_reference ?: $booking->reference;
                    $this->line(($dry ? '[dry] ' : '').'Unpairing false return: '.$ref
                        .($sibling ? ' (was linked to '.($sibling->external_reference ?: $sibling->reference).')' : ''));

                    if (! $dry) {
                        $booking->unlinkReturnPair();
                    }
                    $fixed++;
                }
            });

        $this->info(($dry ? 'Would unpair ' : 'Unpaired ').$fixed.' false return booking(s).');

        return self::SUCCESS;
    }
}
