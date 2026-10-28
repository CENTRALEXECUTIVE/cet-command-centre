<?php

namespace App\Services\Outreach;

use App\Models\Prospect;
use App\Models\User;

/**
 * Builds the text and mailto: links for Outreach emails. It only COMPOSES —
 * the director's own email app does the sending. Nothing leaves the server.
 */
class OutreachEmailComposer
{
    /** Normalise stored body text: CRLF/CR → LF, and literal "\n" sequences → real line breaks. */
    public static function normaliseBody(?string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", (string) $body);

        return str_replace('\\n', "\n", $body);
    }

    /**
     * mailto: link with subject + body pre-filled. Encoded per RFC 6068: every
     * reserved character percent-encoded (spaces as %20, never "+"), line breaks
     * as %0D%0A so Outlook/Apple Mail/Gmail all show them as new lines.
     * Returns null when the prospect can't be emailed (no address / do-not-contact).
     */
    public function mailto(Prospect $prospect, string $subject, string $body): ?string
    {
        if (! $prospect->canEmail()) {
            return null;
        }

        $to = preg_replace('/\s+/', '', (string) $prospect->contact_email);
        $enc = fn (string $s) => str_replace('%0A', '%0D%0A', rawurlencode(self::normaliseBody($s)));

        return 'mailto:'.str_replace('%40', '@', rawurlencode($to))
            .'?subject='.$enc($subject)
            .'&body='.$enc($body);
    }

    /** Apply merge fields and expand [Name]/[Signature]/[Opt-out line] placeholders. */
    public function merge(string $text, Prospect $prospect, ?User $sender = null): string
    {
        $first = $prospect->firstName();
        $senderName = $this->senderName($prospect, $sender);
        $signature = str_replace('{sender}', $senderName, (string) config('outreach.signature'));

        $text = self::normaliseBody($text);

        // Placeholders from the sales kit ("[Signature] · [Opt-out line]" on one line).
        $text = str_replace('[Signature] · [Opt-out line]', "[Signature]\n\n[Opt-out line]", $text);
        $text = strtr($text, [
            '[Signature]' => $signature,
            '[Opt-out line]' => (string) config('outreach.opt_out'),
            '[Name]' => $first !== '' ? $first : 'there',
            '[original subject]' => (string) $prospect->email_subject,
        ]);

        return strtr($text, [
            '{first_name}' => $first !== '' ? $first : 'there',
            '{company}' => (string) $prospect->company,
            '{original_subject}' => (string) ($prospect->email_subject ?: 'Executive travel for '.$prospect->company),
            '{sender}' => $senderName,
        ]);
    }

    /** The drafted first email, merged: ['subject' => …, 'body' => …]. */
    public function firstEmail(Prospect $prospect, ?User $sender = null): array
    {
        return [
            'subject' => $this->merge((string) $prospect->email_subject, $prospect, $sender),
            'body' => $this->merge((string) $prospect->email_body, $prospect, $sender),
        ];
    }

    /**
     * Day-4 and day-10 follow-ups for this prospect (corporate or partner track),
     * merged: ['day_4' => ['label','subject','body'], 'day_10' => …].
     */
    public function followUps(Prospect $prospect, ?User $sender = null): array
    {
        $track = $this->track($prospect);
        $out = [];
        foreach ((array) config("outreach.follow_ups.$track", []) as $key => $tpl) {
            $out[$key] = [
                'label' => $tpl['label'] ?? $key,
                'subject' => $this->merge((string) ($tpl['subject'] ?? ''), $prospect, $sender),
                'body' => $this->merge((string) ($tpl['body'] ?? ''), $prospect, $sender),
            ];
        }

        return $out;
    }

    public function track(Prospect $prospect): string
    {
        $segment = mb_strtolower((string) $prospect->segment);
        foreach ((array) config('outreach.partner_keywords', []) as $kw) {
            if ($kw !== '' && str_contains($segment, mb_strtolower($kw))) {
                return 'partner';
            }
        }

        return 'corporate';
    }

    private function senderName(Prospect $prospect, ?User $sender): string
    {
        $name = $prospect->owner?->name ?: $sender?->name ?: 'Central Executive Transfers';

        return trim(explode(' ', trim($name))[0]) ?: $name;
    }
}
