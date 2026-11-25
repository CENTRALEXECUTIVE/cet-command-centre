<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** UK clock helpers for typed times (25 Nov 2026). */
class UkTime
{
    /**
     * Validation closure: a "Y-m-d\TH:i" time that doesn't exist in the UK (the hour
     * skipped when the clocks go forward, e.g. 01:30 on 28 Mar 2027) is refused with a
     * plain message. It used to be saved silently as 02:30.
     */
    public static function existsRule(): \Closure
    {
        return function ($attr, $value, $fail) {
            $v = trim((string) $value);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}$/', $v)) {
                return;
            }
            $v = str_replace(' ', 'T', $v);
            try {
                $c = Carbon::createFromFormat('Y-m-d\TH:i', $v, 'Europe/London');
            } catch (\Throwable) {
                return;
            }
            if ($c && $c->format('Y-m-d\TH:i') !== $v) {
                $fail('That time doesn’t exist in the UK: the clocks go forward that night. Please choose '.$c->format('H:i').' or later.');
            }
        };
    }
}
