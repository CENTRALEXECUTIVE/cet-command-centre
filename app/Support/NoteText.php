<?php

namespace App\Support;

/**
 * Render free-text notes for HTML: escape everything (XSS-safe), then turn any
 * http/https URL into a clickable link. Used for driver notes / special requests
 * so a job-card link the office pastes is tappable, not plain text. Newlines are
 * preserved by a white-space:pre-wrap container at the call site.
 */
class NoteText
{
    public static function linkify(?string $text): string
    {
        $escaped = e((string) $text); // escape FIRST — never trust note content
        if ($escaped === '') {
            return '';
        }

        // Match http(s) URLs; stop at whitespace or a tag boundary. Trailing
        // sentence punctuation is trimmed off the link (kept as plain text).
        return preg_replace_callback('#https?://[^\s<]+#i', function ($m) {
            $url = $m[0];
            $trail = '';
            while ($url !== '' && str_contains('.,;:!?)]}', substr($url, -1))) {
                $trail = substr($url, -1).$trail;
                $url = substr($url, 0, -1);
            }

            return '<a href="'.$url.'" target="_blank" rel="noopener nofollow" '
                .'style="color:#2563eb;text-decoration:underline;word-break:break-all">'.$url.'</a>'.$trail;
        }, $escaped);
    }
}
