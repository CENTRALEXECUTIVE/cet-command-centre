<?php

namespace App\Enums;

/**
 * The three access roles for the CET Command Centre.
 * Principle of least privilege: each role only sees what it needs.
 */
enum UserRole: string
{
    case Admin = 'admin';                     // Abdi & Maj — full control
    case Driver = 'driver';                   // Abdi, Maj & third-party driver view
    case CorporateClient = 'corporate_client'; // JELD-WEN, LB Foster, Forged Solutions

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Driver => 'Driver',
            self::CorporateClient => 'Corporate Client',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}
