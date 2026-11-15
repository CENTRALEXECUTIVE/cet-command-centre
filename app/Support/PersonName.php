<?php

namespace App\Support;

/**
 * How we address a customer by name in short labels and greetings (15 Nov 2026: a pay
 * statement read "Mr MAN" because the "first name" of "Mr Smith" was taken as "Mr").
 * "John Smith" → "John"; "Mr Smith" / "Mrs. J Patel" → "Mr Smith" / "Mrs Patel"
 * (a title keeps the surname); a bare title or blank → the fallback.
 */
class PersonName
{
    private const TITLES = '/^(mr|mrs|ms|miss|mx|dr|prof|professor|sir|dame|lord|lady|rev|revd)\.?$/i';

    public static function short(?string $name, string $fallback = ''): string
    {
        $parts = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return $fallback;
        }
        if (preg_match(self::TITLES, $parts[0])) {
            $title = ucfirst(strtolower(rtrim($parts[0], '.')));
            // Skip a lone initial ("Mrs J Patel" → "Mrs Patel").
            $rest = array_values(array_filter(array_slice($parts, 1), fn ($p) => ! preg_match('/^[A-Za-z]\.?$/', $p)));
            $surname = $rest ? end($rest) : null;

            return $surname ? $title.' '.$surname : $fallback;
        }

        return $parts[0];
    }
}
