<?php

namespace App\Console\Commands;

use App\Services\Inbox\GraphMailClient;
use App\Services\Inbox\OutlookBookingService;
use Illuminate\Console\Command;

/**
 * Dumps unread booking-looking emails exactly as the system receives them
 * (content type, the converted plain text, and whether the parser can read
 * them). Used to debug why a real ETO email was skipped. Marks nothing read.
 */
class DumpEmail extends Command
{
    protected $signature = 'cet:dump-email {match=booking}';

    protected $description = 'Show unread emails matching a subject keyword and whether they parse';

    public function handle(GraphMailClient $graph, OutlookBookingService $outlook): int
    {
        $match = strtolower((string) $this->argument('match'));
        $found = false;

        foreach ($graph->fetchUnreadRaw() as $m) {
            if ($match !== '' && ! str_contains(strtolower($m['subject']), $match)) {
                continue;
            }
            $found = true;

            $parsed = $outlook->parse($m['subject'], $m['text'], null);

            $this->line(str_repeat('=', 60));
            $this->line('SUBJECT: '.$m['subject']);
            $this->line('CONTENT TYPE: '.$m['contentType']);
            $this->line('PARSED: '.($parsed ? 'YES (ref '.($parsed['reference'] ?? '?').')' : 'NO — would be skipped'));
            $this->newLine();
            $this->line('--- TEXT AS PARSER SEES IT ---');
            $this->line(mb_substr($m['text'], 0, 2500));
            $this->newLine();
        }

        if (! $found) {
            $this->warn("No unread emails with '{$match}' in the subject.");
        }

        return self::SUCCESS;
    }
}
