<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';       // 💰 outstanding cash
    case Card = 'card';       // 👀 card balance via Tide link
    case Account = 'account'; // Corporate account — invoiced

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Card => 'Card',
            self::Account => 'Account',
        };
    }

    /** Calendar payment emoji per the company rules. */
    public function emoji(): ?string
    {
        return match ($this) {
            self::Cash => '💰',
            self::Card => '👀',
            self::Account => null,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $m) => $m->value, self::cases());
    }
}
