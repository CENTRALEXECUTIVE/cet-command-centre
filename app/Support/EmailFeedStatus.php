<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\Ai\AnthropicService;
use App\Services\Inbox\GraphMailClient;
use Illuminate\Support\Carbon;

/**
 * Answers "is the ETO email feed actually working?" in plain terms, so the office
 * can see at a glance why new bookings are (or aren't) auto-adding — instead of
 * wondering. The feed reads ETO confirmation emails and turns them into bookings;
 * it does NOTHING until every requirement below is in place, so this lists each
 * one and whether it's met, plus when it last ran.
 *
 * Every read is wrapped so this panel can never itself take a page down.
 */
class EmailFeedStatus
{
    /**
     * Each requirement for the feed to work: label, whether it's met, and a short
     * line of what to do when it isn't.
     *
     * @return array<int, array{key:string, label:string, ok:bool, detail:string}>
     */
    public function requirements(): array
    {
        $graphOk = $this->graphConfigured();
        $mailbox = (string) config('services.microsoft_graph.mailbox');
        // The default mailbox is the WRONG inbox — ETO emails arrive at admin@.
        $mailboxOk = $graphOk && $mailbox !== '' && ! str_starts_with(strtolower($mailbox), 'bookings@');

        return [
            [
                'key' => 'credentials',
                'label' => 'Microsoft 365 connection',
                'ok' => $graphOk,
                'detail' => $graphOk
                    ? 'Azure app credentials are set.'
                    : 'Add MS_GRAPH_CLIENT_ID, MS_GRAPH_CLIENT_SECRET and MS_GRAPH_TENANT_ID (Azure app with Mail.Read).',
            ],
            [
                'key' => 'mailbox',
                'label' => 'Reading the right inbox',
                'ok' => $mailboxOk,
                'detail' => $mailboxOk
                    ? 'Reading '.$mailbox.'.'
                    : 'Set MS_GRAPH_MAILBOX to admin@centralexecutivetransfers.co.uk'.($mailbox ? ' (currently '.$mailbox.').' : '.'),
            ],
            [
                'key' => 'parser',
                'label' => 'Email reader (AI parser)',
                'ok' => $this->aiConfigured(),
                'detail' => $this->aiConfigured()
                    ? 'The parser that reads each email is connected.'
                    : 'Add the Anthropic API key so emails can be read into bookings.',
            ],
            [
                'key' => 'scheduler',
                'label' => 'Background jobs running',
                'ok' => ! $this->schedulerStale(),
                'detail' => ! $this->schedulerStale()
                    ? 'The scheduler is running, so the feed checks every 2 minutes.'
                    : 'The schedule:run cron is not running — nothing is being pulled in.',
            ],
        ];
    }

    /** True only when every requirement is met — the feed is live. */
    public function connected(): bool
    {
        foreach ($this->requirements() as $r) {
            if (! $r['ok']) {
                return false;
            }
        }

        return true;
    }

    /** The first unmet requirement's short label, for a one-line summary. */
    public function firstProblem(): ?string
    {
        foreach ($this->requirements() as $r) {
            if (! $r['ok']) {
                return $r['label'];
            }
        }

        return null;
    }

    /** When the feed last ran (any run, even one that found nothing), or null. */
    public function lastRun(): ?Carbon
    {
        try {
            $v = Setting::get('outlook_ingest_last_run');

            return $v ? Carbon::parse($v) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The stats from the last run: processed / created / updated / cancelled /
     * skipped. Empty array when it's never run.
     *
     * @return array<string, int>
     */
    public function lastStats(): array
    {
        try {
            $v = Setting::get('outlook_ingest_last_stats');
            $decoded = is_string($v) ? json_decode($v, true) : (is_array($v) ? $v : null);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** A short human summary for a badge/row, e.g. "Live" or "Not connected". */
    public function summary(): string
    {
        return $this->connected() ? 'Live' : 'Not connected';
    }

    private function graphConfigured(): bool
    {
        try {
            return app(GraphMailClient::class)->configured();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function aiConfigured(): bool
    {
        try {
            return app(AnthropicService::class)->configured();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function schedulerStale(): bool
    {
        try {
            return Heartbeat::schedulerStale();
        } catch (\Throwable $e) {
            return false; // don't claim "stopped" if we can't tell
        }
    }
}
