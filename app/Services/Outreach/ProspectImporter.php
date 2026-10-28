<?php

namespace App\Services\Outreach;

use App\Models\Prospect;
use Illuminate\Support\Facades\DB;

/**
 * Imports an Outreach prospects CSV. Used by the /outreach upload and by
 * `php artisan cet:import-prospects {path}`.
 *
 * Columns (exact): company, segment, town, website, why_they_need_us,
 * contact_role, contact_name, contact_email, contact_phone, linkedin_url,
 * fit_score, angle, source_url, email_subject, email_body.
 * Also accepts the older batch layout (2026-10-28-prospects.csv): id, track,
 * sector → segment, town_site → town, status (only an opt-out is honoured).
 *
 * Rules:
 *  - Keyed by lower(company)+town: an existing prospect is a DUPLICATE and is
 *    skipped. Its status, notes, owner and dates are never changed; only fields
 *    it has BLANK may be filled from the new row (e.g. a later batch adds the
 *    email address).
 *  - Do-not-contact is absolute: a DNC prospect is never touched or re-opened,
 *    and a new row whose email matches a DNC prospect's email is skipped too.
 *  - The file is only read, never stored.
 */
class ProspectImporter
{
    public const FIELDS = [
        'company', 'segment', 'town', 'website', 'why_they_need_us', 'contact_role',
        'contact_name', 'contact_email', 'contact_phone', 'linkedin_url', 'fit_score',
        'angle', 'source_url', 'email_subject', 'email_body',
    ];

    /** Older column names → current ones. */
    private const ALIASES = [
        'sector' => 'segment',
        'town_site' => 'town',
        'town/site' => 'town',
        'subject' => 'email_subject',
        'body' => 'email_body',
        'email' => 'contact_email',
        'phone' => 'contact_phone',
        'linkedin' => 'linkedin_url',
    ];

    private const MAX = [
        'company' => 200, 'segment' => 120, 'town' => 200, 'website' => 500,
        'contact_role' => 200, 'contact_name' => 200, 'contact_email' => 255,
        'contact_phone' => 60, 'linkedin_url' => 500, 'source_url' => 1000,
        'email_subject' => 300,
    ];

    /**
     * @return array{created:int, duplicates:int, filled:int, dnc_skipped:int, invalid:int, errors:array<int,string>}
     */
    public function import(string $path, ?int $ownerId = null, ?int $actorId = null): array
    {
        $stats = ['created' => 0, 'duplicates' => 0, 'filled' => 0, 'dnc_skipped' => 0, 'invalid' => 0, 'errors' => []];

        $fh = @fopen($path, 'r');
        if (! $fh) {
            $stats['errors'][] = 'Could not read the file.';

            return $stats;
        }

        try {
            $first = (string) fgets($fh);
            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
            rewind($fh);

            $header = fgetcsv($fh, 0, $delimiter, '"', '');
            if (! $header) {
                $stats['errors'][] = 'The file is empty.';

                return $stats;
            }
            $columns = array_map(fn ($h) => $this->normaliseHeader((string) $h), $header);
            if (! in_array('company', $columns, true)) {
                $stats['errors'][] = 'No "company" column found — check the header row.';

                return $stats;
            }

            $line = 1;
            while (($row = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
                $line++;
                if ($row === [null] || count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue; // blank line
                }
                $assoc = [];
                foreach ($columns as $i => $col) {
                    if ($col !== '' && ! array_key_exists($col, $assoc)) {
                        $assoc[$col] = isset($row[$i]) ? trim((string) $row[$i]) : '';
                    }
                }
                $this->importRow($assoc, $line, $ownerId, $actorId, $stats);
            }
        } finally {
            fclose($fh);
        }

        return $stats;
    }

    private function importRow(array $raw, int $line, ?int $ownerId, ?int $actorId, array &$stats): void
    {
        $data = $this->clean($raw);
        if (($data['company'] ?? '') === '') {
            $stats['invalid']++;
            $stats['errors'][] = "Line {$line}: no company name — skipped.";

            return;
        }

        $existing = Prospect::where('dedupe_key', Prospect::dedupeKey($data['company'], $data['town'] ?? null))->first();

        if ($existing) {
            if ($existing->isDoNotContact()) {
                $stats['dnc_skipped']++;

                return;
            }
            $stats['duplicates']++;
            $this->fillBlanks($existing, $data, $actorId) && $stats['filled']++;

            return;
        }

        // A DNC contact under a differently-spelt company is still DNC.
        if (filled($data['contact_email'] ?? null)
            && Prospect::where('status', 'do_not_contact')
                ->whereRaw('lower(contact_email) = ?', [mb_strtolower($data['contact_email'])])->exists()) {
            $stats['dnc_skipped']++;

            return;
        }

        $optedOut = in_array(str_replace([' ', '-'], '_', mb_strtolower($raw['status'] ?? '')),
            ['do_not_contact', 'opted_out', 'dnc', 'unsubscribed'], true);

        DB::transaction(function () use ($data, $ownerId, $actorId, $optedOut, $line) {
            $p = Prospect::create($data + [
                'status' => $optedOut ? 'do_not_contact' : 'new',
                'owner_id' => $ownerId,
            ]);
            $p->log('imported', 'Imported from CSV (line '.$line.')'.($optedOut ? ' — marked do not contact' : ''), $actorId);
        });
        $stats['created']++;
    }

    /** Fill only the existing prospect's blank fields. Never status/notes/owner/dates. */
    private function fillBlanks(Prospect $p, array $data, ?int $actorId): bool
    {
        $filled = [];
        foreach (self::FIELDS as $f) {
            if (in_array($f, ['company', 'town'], true)) {
                continue;
            }
            if (blank($p->{$f}) && filled($data[$f] ?? null)) {
                $p->{$f} = $data[$f];
                $filled[] = $f;
            }
        }
        if (! $filled) {
            return false;
        }
        $p->save();
        $p->log('edited', 'Re-import filled blank fields: '.implode(', ', $filled), $actorId);

        return true;
    }

    private function clean(array $raw): array
    {
        $data = [];
        foreach (self::FIELDS as $f) {
            $v = trim((string) ($raw[$f] ?? ''));
            if ($v === '') {
                continue;
            }
            if (isset(self::MAX[$f])) {
                $v = mb_substr($v, 0, self::MAX[$f]);
            }
            $data[$f] = $v;
        }

        // Older layout: no segment/sector — fall back to the track letter.
        if (! isset($data['segment']) && filled($raw['track'] ?? null)) {
            $data['segment'] = match (strtoupper(trim($raw['track']))) {
                'A' => 'Corporate',
                'B' => 'Partner operator',
                default => 'Track '.trim($raw['track']),
            };
        }

        if (isset($data['fit_score'])) {
            $n = (int) preg_replace('/[^0-9]/', '', $data['fit_score']);
            $data['fit_score'] = $n >= 1 ? min(5, $n) : null;
        }
        if (isset($data['contact_email'])) {
            $email = preg_replace('/\s+/', '', $data['contact_email']);
            $data['contact_email'] = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        }
        if (isset($data['email_body'])) {
            $data['email_body'] = OutreachEmailComposer::normaliseBody($data['email_body']);
        }

        return array_filter($data, fn ($v) => $v !== null);
    }

    private function normaliseHeader(string $h): string
    {
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h); // UTF-8 BOM
        $h = mb_strtolower(trim($h));
        $h = preg_replace('/\s+/', '_', $h);

        return self::ALIASES[$h] ?? $h;
    }
}
