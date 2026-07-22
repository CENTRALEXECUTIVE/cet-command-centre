<?php

namespace App\Enums;

/**
 * Live job lifecycle used by the despatch board and driver app — the iCabbi
 * cadence: allocated → accepted → en route → arrived → POB → completed.
 */
enum BookingStatus: string
{
    case Pending = 'pending';       // Created, not yet allocated
    case Allocated = 'allocated';   // Offered/assigned to a driver
    case Accepted = 'accepted';     // Driver accepted the job
    case EnRoute = 'en_route';      // Driver on the way — tracking link sent
    case Arrived = 'arrived';       // Driver at the pickup, waiting for passenger
    case Collected = 'collected';   // Passenger on board (POB)
    case Complete = 'complete';     // Dropped off / job completed — review timer starts
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Allocated => 'Allocated',
            self::Accepted => 'Accepted',
            self::EnRoute => 'En Route',
            self::Arrived => 'Arrived',
            self::Collected => 'On Board',
            self::Complete => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No Show',
        };
    }

    /** Statuses considered an active job (driver is working it). */
    public function isActive(): bool
    {
        return in_array($this, [self::Accepted, self::EnRoute, self::Arrived, self::Collected], true);
    }

    /**
     * Statuses during which driver GPS is recorded. Tracking starts on the
     * SET OFF tap (En Route) and stops on Complete/no-show/cancel — never
     * before the driver is actually driving to the job.
     */
    public function isTracked(): bool
    {
        return in_array($this, [self::EnRoute, self::Arrived, self::Collected], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Complete, self::Cancelled, self::NoShow], true);
    }

    /**
     * The legal next statuses from here — the single source of truth for the
     * despatch board, driver app and status engine.
     *
     * @return array<int, self>
     */
    public function nextStatuses(): array
    {
        return match ($this) {
            self::Pending => [self::Allocated, self::Cancelled],
            self::Allocated => [self::Accepted, self::Pending, self::Cancelled, self::NoShow],
            self::Accepted => [self::EnRoute, self::Cancelled, self::NoShow],
            self::EnRoute => [self::Arrived, self::Cancelled, self::NoShow],
            self::Arrived => [self::Collected, self::Cancelled, self::NoShow],
            self::Collected => [self::Complete, self::Cancelled],
            self::Complete, self::Cancelled, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->nextStatuses(), true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
