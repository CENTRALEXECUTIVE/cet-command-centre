<?php

namespace App\Services\Outreach;

use App\Models\Prospect;

/**
 * One-off, guarded fixes to a prospect's contact_name so the drafted greeting
 * matches the address we will actually send to. The fill-only contacts loader
 * never overwrites a name, so a stale researched name ("Prof Ben Morgan (CEO…)")
 * would otherwise greet the wrong person at a general inbox.
 *
 * Each correction only applies when the prospect's CURRENT name still starts
 * with the old researched value — a name a director has since edited is never
 * touched. Do-not-contact prospects are never touched. Status, notes, owner and
 * dates are never changed. A null new name means "Dear Sir or Madam," (house
 * style for a general inbox). Each change is written to the prospect's history.
 */
class ContactNameCorrections
{
    /**
     * @param  array<int, array{company:string, town:?string, old_prefix:string, new_name:?string, reason:string}>  $rows
     * @return array{changed:int, skipped:int}
     */
    public function apply(array $rows, ?int $actorId = null): array
    {
        $stats = ['changed' => 0, 'skipped' => 0];

        foreach ($rows as $row) {
            $p = Prospect::where('dedupe_key', Prospect::dedupeKey($row['company'], $row['town'] ?? null))->first();
            $current = trim((string) $p?->contact_name);

            if (! $p || $p->isDoNotContact() || $row['old_prefix'] === ''
                || ! str_starts_with(mb_strtolower($current), mb_strtolower($row['old_prefix']))) {
                $stats['skipped']++;

                continue;
            }

            $new = filled($row['new_name'] ?? null) ? mb_substr(trim($row['new_name']), 0, 200) : null;
            $p->contact_name = $new;
            $p->save();
            $p->log('edited', 'Greeting fix: contact_name changed from "'.$current.'" to '
                .($new ? '"'.$new.'"' : '(blank, so the email opens "Dear Sir or Madam,")')
                .' - '.$row['reason'], $actorId);
            $stats['changed']++;
        }

        return $stats;
    }
}
