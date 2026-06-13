<?php

namespace App\Enums;

/**
 * Live job lifecycle used by the despatch board and driver app.
 */
enum BookingStatus: string
{
    case Pending = 'pending';       // Created, not yet allocated
    case Allocated = 'allocated';   // Driver assigned
    case Accepted = 'accepted';     // Driver accepted the job
    case EnRoute = 'en_route';      // Driver on the way — tracking link sent
    case Collected = 'collected';   // Passenger on board
    case Complete = 'complete';     // Job finished — review request timer starts
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Allocated => 'Allocated',
            self::Accepted => 'Accepted',
            self::EnRoute => 'En Route',
            self::Collected => 'Collected',
            self::Complete => 'Complete',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No Show',
        };
    }

    /** Statuses considered an active job (driver location tracked). */
    public function isActive(): bool
    {
        return in_array($this, [self::Accepted, self::EnRoute, self::Collected], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
