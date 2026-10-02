<?php

namespace App\Console\Commands;

use App\Services\Bookings\RecurringBookingGenerator;
use Illuminate\Console\Command;

/**
 * Creates real pending bookings from active standing/recurring templates for their
 * upcoming occurrences. Scheduled daily; idempotent.
 */
class GenerateRecurringBookings extends Command
{
    protected $signature = 'cet:generate-recurring';

    protected $description = 'Generate upcoming bookings from recurring/standing templates';

    public function handle(RecurringBookingGenerator $generator): int
    {
        $made = $generator->generateDue();
        $this->info("Generated {$made} booking(s) from recurring templates.");

        return self::SUCCESS;
    }
}
