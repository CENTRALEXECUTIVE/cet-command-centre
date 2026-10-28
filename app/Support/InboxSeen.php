<?php

namespace App\Support;

/**
 * Remembers inbox emails the AI has already judged NOT relevant (not a booking
 * / not an enquiry), so the every-few-minutes inbox scans don't pay to ask the
 * AI about the same newsletter or receipt hundreds of times a day.
 *
 * Kept in a small file under storage/app (one per pipeline) rather than the
 * cache, because every deploy runs optimize:clear, which wipes the cache. Only
 * a clear "not relevant" answer is remembered — an AI error is never stored, so
 * a real booking can't be skipped because one call failed.
 */
class InboxSeen
{
    private const KEEP_DAYS = 40; // longer than the longest scan window (30 days)

    /** @var array<string, array<string, int>> */
    private static array $loaded = [];

    public static function has(string $pipeline, ?string $messageId): bool
    {
        return $messageId !== null && $messageId !== ''
            && isset(self::load($pipeline)[$messageId]);
    }

    public static function remember(string $pipeline, ?string $messageId): void
    {
        if ($messageId === null || $messageId === '') {
            return;
        }
        $seen = self::load($pipeline);
        $seen[$messageId] = time();
        $cutoff = time() - self::KEEP_DAYS * 86400;
        $seen = array_filter($seen, fn (int $at) => $at >= $cutoff);
        self::$loaded[$pipeline] = $seen;

        try {
            @file_put_contents(self::path($pipeline), json_encode($seen), LOCK_EX);
        } catch (\Throwable $e) {
            // Never break the inbox scan over this; worst case the AI is asked again.
        }
    }

    /** @return array<string, int> */
    private static function load(string $pipeline): array
    {
        if (! isset(self::$loaded[$pipeline])) {
            $raw = @file_get_contents(self::path($pipeline));
            $data = $raw ? json_decode($raw, true) : null;
            self::$loaded[$pipeline] = is_array($data) ? $data : [];
        }

        return self::$loaded[$pipeline];
    }

    private static function path(string $pipeline): string
    {
        return storage_path('app/inbox-ai-seen-'.preg_replace('/[^a-z0-9_-]/i', '', $pipeline).'.json');
    }

    /** Test helper: forget the in-memory copy (and optionally the file). */
    public static function reset(?string $pipeline = null, bool $deleteFile = false): void
    {
        foreach ($pipeline ? [$pipeline] : array_keys(self::$loaded) as $p) {
            unset(self::$loaded[$p]);
            if ($deleteFile) {
                @unlink(self::path($p));
            }
        }
    }
}
