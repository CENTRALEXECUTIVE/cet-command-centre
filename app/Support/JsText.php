<?php

namespace App\Support;

/**
 * Text placed inside a single-quoted JavaScript string in an HTML attribute, e.g.
 * onsubmit="return confirm('Remove {{ JsText::q($name) }}?')". Blade's {{ }} turns '
 * into &#039;, which the browser turns back into ' BEFORE the script runs, so a name
 * like O'Brien broke the prompt (the customer was deleted with no confirmation) and a
 * crafted name could run script (QA 25 Nov 2026). Escape for JS first; {{ }} then
 * escapes for HTML.
 */
class JsText
{
    public static function q(mixed $value): string
    {
        $s = (string) $value;

        return str_replace(
            ['\\', "'", '"', "\r", "\n", '<', '>', "\u{2028}", "\u{2029}"],
            ['\\\\', "\\'", '\\"', '\\r', '\\n', '\\x3C', '\\x3E', '\\u2028', '\\u2029'],
            $s,
        );
    }
}
