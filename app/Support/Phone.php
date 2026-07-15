<?php

namespace App\Support;

/**
 * UK phone normalisation for wa.me / WhatsApp deep links.
 *
 * WhatsApp only opens a chat if the number is the account's real international
 * number with NO leading zero and NO plus. The classic "this number is not on
 * WhatsApp" error is almost always a formatting slip in the STORED number, not a
 * missing account — most often the very common "+44 (0)7700 900123" style, which
 * a naive strip turns into 4407700900123 (a stray 0 after the 44). This handles
 * those cases centrally.
 */
class Phone
{
    /**
     * Return the number as international digits for wa.me (e.g. 447700900123),
     * or null if there aren't enough digits to be a real number.
     */
    public static function wa(?string $number): ?string
    {
        $d = preg_replace('/\D/', '', (string) $number); // digits only — drops +, (), spaces, dashes
        if ($d === '' || strlen($d) < 7) {
            return null;
        }

        // 00 international access prefix → drop it (0044… → 44…).
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        } elseif (str_starts_with($d, '0')) {
            // National trunk 0 → UK country code (07700… → 447700…).
            $d = '44'.substr($d, 1);
        }

        // "+44 (0)7…" leaves 44 0 7… — strip the stray trunk 0 after the 44.
        if (str_starts_with($d, '440')) {
            $d = '44'.substr($d, 3);
        }

        // A bare UK mobile with no country code or trunk 0 (7700900123).
        if (strlen($d) === 10 && str_starts_with($d, '7')) {
            $d = '44'.$d;
        }

        return $d;
    }
}
