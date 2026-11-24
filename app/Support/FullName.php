<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Directors (24 Nov 2026): website bookings must give a first name AND a surname
 * (separate boxes, like ETO). The forms post {prefix}_first_name + {prefix}_surname;
 * this joins them into the single name the rest of the system uses. Office forms are
 * unaffected (they still take one name and may save without a surname).
 */
class FullName
{
    /** Join first + surname into $target on the request, when the split boxes were used. */
    public static function merge(Request $request, string $first, string $last, string $target): void
    {
        if (! $request->has($first) && ! $request->has($last)) {
            return;
        }
        $full = trim(preg_replace('/\s+/', ' ', trim((string) $request->input($first)).' '.trim((string) $request->input($last))));
        $request->merge([$target => $full]);
    }

    /** Validation closure: a name with at least two words (first name and surname). */
    public static function rule(string $message = 'Please enter your first name and surname.'): \Closure
    {
        return function ($attr, $value, $fail) use ($message) {
            if (filled($value) && count(preg_split('/\s+/', trim((string) $value))) < 2) {
                $fail($message);
            }
        };
    }
}
