<?php

namespace App\Services\Outreach;

use App\Models\Prospect;

/**
 * Upgrades a prospect's contact to a better PUBLISHED route (a named buyer or the
 * relevant department inbox instead of info@), from docs/growth/sales/contacts-upgrade.csv.
 *
 * Safe by design: never touches a do-not-contact prospect, one already emailed
 * (status not 'new' or a last-contacted date), or one whose contact a director
 * has edited by hand. Status, notes, owner and dates are never changed. Each
 * change is written to the prospect's history with its source. Nothing is sent.
 */
class ContactUpgrader
{
    /** @return array{upgraded:int, skipped:int, unmatched:int} */
    public function import(string $path, ?int $actorId = null): array
    {
        $stats = ['upgraded' => 0, 'skipped' => 0, 'unmatched' => 0];
        $fh = fopen($path, 'r');
        if (! $fh) {
            return $stats;
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), fgetcsv($fh) ?: []);

        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) !== count($header)) {
                continue;
            }
            $r = array_map(fn ($v) => trim((string) $v), array_combine($header, $row));
            $this->apply($r, $actorId, $stats);
        }
        fclose($fh);

        return $stats;
    }

    /** @param array<string,string> $r */
    public function apply(array $r, ?int $actorId, array &$stats): void
    {
        $email = strtolower($r['primary_email'] ?? '');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ($r['company'] ?? '') === '') {
            $stats['skipped']++;

            return;
        }
        $p = Prospect::where('dedupe_key', Prospect::dedupeKey($r['company'], ($r['town'] ?? '') ?: null))->first();
        if (! $p) {
            $stats['unmatched']++;

            return;
        }
        if ($p->isDoNotContact() || $p->status !== 'new' || $p->last_contacted_at
            || $this->directorEditedContact($p)) {
            $stats['skipped']++;

            return;
        }

        $named = ($r['primary_type'] ?? '') === 'named' && ($r['contact_name'] ?? '') !== '';
        $cc = strtolower($r['cc_email'] ?? '');
        $cc = (filter_var($cc, FILTER_VALIDATE_EMAIL) && $cc !== $email) ? $cc : null;

        $before = $p->contact_email ?: '(none)';
        $p->contact_email = $email;
        // A department inbox greets the company team, not a person who isn't reading it.
        $p->contact_name = $named ? mb_substr($r['contact_name'], 0, 200) : null;
        if (($r['contact_role'] ?? '') !== '') {
            $p->contact_role = mb_substr($r['contact_role'], 0, 200);
        }
        $p->cc_email = $cc;
        $p->cc_name = $cc && ($r['cc_name'] ?? '') !== '' ? mb_substr($r['cc_name'], 0, 200) : null;
        if (! $p->isDirty()) {
            $stats['skipped']++;

            return;
        }
        $p->save();
        $p->log('edited', 'Contact upgraded: '.$before.' → '.$email
            .($p->contact_name ? ' ('.$p->contact_name.')' : '')
            .($cc ? ' · CC '.$cc : '')
            .' · source '.($r['source_url'] ?? '-')
            .(($r['why'] ?? '') !== '' ? ' · '.$r['why'] : ''), $actorId);
        $stats['upgraded']++;
    }

    private function directorEditedContact(Prospect $p): bool
    {
        return $p->activities()->where('type', 'edited')->where('body', 'like', 'Edited:%')
            ->where(fn ($q) => $q->where('body', 'like', '%contact_email%')->orWhere('body', 'like', '%contact_name%'))
            ->exists();
    }
}
