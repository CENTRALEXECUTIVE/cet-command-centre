<?php

namespace App\Support;

use App\Models\Booking;

/**
 * The company details a customer gave on their last VAT-invoice booking, found by
 * email (27 Nov 2026: repeat business customers retyped company, address and VAT
 * number every time). Used to pre-fill the website form and the office New booking.
 */
class LastVatDetails
{
    /** @return array{name?: string, address?: string, vat_number?: string} */
    public static function forEmail(?string $email): array
    {
        $email = strtolower(trim((string) $email));
        if (! str_contains($email, '@')) {
            return [];
        }
        try {
            $b = Booking::whereHas('customer', fn ($q) => $q->whereRaw('LOWER(email) = ?', [$email]))
                ->whereNotNull('meta->vat_details')
                ->latest('id')->first();
            $vd = (array) ($b?->meta['vat_details'] ?? []);

            return array_filter([
                'name' => trim((string) ($vd['name'] ?? $vd['company'] ?? '')),
                'address' => trim((string) ($vd['address'] ?? '')),
                'vat_number' => trim((string) ($vd['vat_number'] ?? '')),
            ], fn ($v) => $v !== '');
        } catch (\Throwable) {
            return [];
        }
    }
}
