<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** UK clock helpers for typed times (25 Nov 2026). */
class UkTime
{
    /**
     * A booking is NEVER refused for its time. A "Y-m-d\TH:i" time that doesn't exist
     * in the UK (the hour skipped when the clocks go forward, e.g. 01:30 on 28 Mar 2027)
     * is moved to 02:00 — the moment the clocks change, so the driver is early rather
     * than late — and a note asks the office to confirm the time with the customer.
     * (PHP alone would silently save it as 02:30.) The office form passes no notes
     * field: staff see the moved time on the booking, and driver notes go to the driver.
     */
    public static function fixGap(Request $request, array $fields, ?string $notesField = 'notes'): void
    {
        $notes = [];
        foreach ($fields as $field) {
            $raw = trim((string) $request->input($field));
            if (! preg_match('/^(\d{4}-\d{2}-\d{2})([T ])(\d{2}:\d{2})$/', $raw, $m)) {
                continue;
            }
            try {
                $c = Carbon::createFromFormat('Y-m-d H:i', $m[1].' '.$m[3], 'Europe/London');
            } catch (\Throwable) {
                continue;
            }
            if ($c && $c->format('Y-m-d H:i') !== $m[1].' '.$m[3]) {
                $request->merge([$field => $m[1].$m[2].'02:00']);
                $notes[] = 'Pickup time entered as '.$m[3].' on '.Carbon::parse($m[1])->format('j M Y')
                    .' (the clocks go forward that night, so that time doesn’t exist) — booked for 02:00. Office: please confirm the time with the customer.';
            }
        }
        if ($notes && $notesField) {
            $request->merge([$notesField => trim(trim((string) $request->input($notesField))."\n".implode("\n", $notes))]);
        }
    }
}
