<?php

namespace App\Services\Outreach;

use App\Mail\OutreachMail;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The approved daily outreach batch (directors, 6 Nov 2026). Each weekday the
 * Command Centre lines up up to `outreach.batch.daily_limit` emails: follow-ups
 * that are due first, then first emails to the best-fit prospects that have a
 * published address. A director reviews them on /outreach/batch and taps
 * Approve; only then are they sent, from the app's normal mailer (admin@), and
 * each prospect moves on exactly as "Mark emailed" would (status, dates,
 * weekday-only next follow-up, history).
 *
 * Never on a timer, never at weekends, never to do-not-contact or closed
 * prospects, and never more than the daily limit (counted across the day).
 */
class OutreachBatch
{
    public const SENT_PREFIX = 'Sent from the Command Centre';

    public function __construct(private OutreachEmailComposer $composer) {}

    public static function limit(): int
    {
        return max(1, (int) config('outreach.batch.daily_limit', 10));
    }

    /** Follow-ups allowed in a day's batch, so most of it is new emails. */
    public static function maxFollowUps(): int
    {
        return max(0, (int) config('outreach.batch.max_follow_ups', 3));
    }

    public static function isWeekend(?Carbon $now = null): bool
    {
        return ($now ?? Carbon::now(config('app.timezone')))->isWeekend();
    }

    /** How many batch emails have already gone today (UK day). */
    public function sentToday(): int
    {
        $tz = config('app.timezone', 'Europe/London');
        $start = Carbon::now($tz)->startOfDay()->utc();

        return ProspectActivity::where('type', 'emailed')
            ->where('body', 'like', self::SENT_PREFIX.'%')
            ->where('created_at', '>=', $start)
            ->count();
    }

    /** How many of today's batch emails were follow-ups (the log names the stage). */
    public function followUpsSentToday(): int
    {
        $start = Carbon::now(config('app.timezone', 'Europe/London'))->startOfDay()->utc();

        return ProspectActivity::where('type', 'emailed')
            ->where('body', 'like', self::SENT_PREFIX.'%')
            ->where('body', 'like', '%follow-up, approved by%')
            ->where('created_at', '>=', $start)
            ->count();
    }

    public function remainingToday(): int
    {
        return max(0, self::limit() - $this->sentToday());
    }

    /** Today's batch, in send order. */
    public function candidates(?int $limit = null): Collection
    {
        $limit ??= max(0, $this->remainingToday() - count($this->queued()));
        $queuedIds = array_column($this->queued(), 'id');
        if ($limit <= 0) {
            return collect();
        }
        $hasEmail = fn ($q) => $q->whereNotNull('contact_email')->where('contact_email', '!=', '');

        $due = Prospect::query()->where($hasEmail)->whereNotIn('id', $queuedIds)
            ->whereIn('status', ['emailed', 'follow_up_1'])
            ->whereNotNull('next_action_at')
            ->whereDate('next_action_at', '<=', Carbon::today()->toDateString())
            ->orderBy('next_action_at')->orderByDesc('fit_score')->orderBy('id')
            ->limit(min($limit, max(0, self::maxFollowUps() - $this->followUpsSentToday()
                - count(array_filter($this->queued(), fn ($q) => ($q['status'] ?? '') !== 'new')))))->get();

        $new = $due->count() < $limit
            ? Prospect::query()->where($hasEmail)->whereNotIn('id', $queuedIds)->where('status', 'new')
                ->orderByDesc('fit_score')->orderBy('id')
                ->limit($limit - $due->count())->get()
            : collect();

        return $due->concat($new)->values();
    }

    /** ['stage' => label, 'subject' => …, 'body' => …] for this prospect's next email. */
    public function compose(Prospect $p, ?User $sender = null): ?array
    {
        if ($p->status === 'new') {
            return ['stage' => 'First email'] + $this->composer->firstEmail($p, $sender);
        }
        $key = ['emailed' => 'day_4', 'follow_up_1' => 'day_10'][$p->status] ?? null;
        if (! $key) {
            return null;
        }
        $f = $this->composer->followUps($p, $sender)[$key] ?? null;

        return $f ? ['stage' => $f['label'], 'subject' => $f['subject'], 'body' => $f['body']] : null;
    }

    /**
     * Send the approved prospects (ids from today's batch only). Returns
     * ['sent' => n, 'failed' => [company => reason], 'skipped' => n].
     */
    public function send(array $ids, User $approver): array
    {
        $result = ['sent' => 0, 'failed' => [], 'skipped' => 0];
        if (self::isWeekend()) {
            $result['failed']['(batch)'] = 'Batches only send Monday to Friday.';

            return $result;
        }

        $allowed = $this->candidates()->keyBy('id');
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($this->remainingToday() <= 0) {
                $result['skipped']++;

                continue;
            }
            $p = $allowed->get($id);
            if (! $p || $p->isDoNotContact() || ! ($next = $p->nextSend())) {
                $result['skipped']++;

                continue;
            }
            $why = $this->sendOne($p, $approver);
            if ($why !== null) {
                $result['failed'][$p->company] = $why;

                continue;
            }
            $result['sent']++;
        }

        return $result;
    }

    /** Send this prospect's next email and move the sequence on. Null = sent, else why not. */
    private function sendOne(Prospect $p, User $approver): ?string
    {
        $mail = $this->compose($p, $approver);
        if (! $mail || blank($mail['subject']) || blank($mail['body'])) {
            return 'No draft to send.';
        }

        try {
            $pending = Mail::to($p->contact_email);
            if (filled($p->cc_email)) {
                $pending->cc($p->cc_email);
            }
            $pending->send(new OutreachMail($mail['subject'], $mail['body']));
        } catch (\Throwable $e) {
            Log::warning('Outreach batch send failed', ['prospect' => $p->id, 'error' => $e->getMessage()]);
            return 'The mail server refused it; nothing was recorded.';
        }

        [$to, $days] = $p->nextSend();
        $nextDate = Prospect::workingDay(Carbon::today()->addDays($days));
        $p->forceFill([
            'status' => $to,
            'last_contacted_at' => now(),
            'next_action_at' => $nextDate->toDateString(),
            'owner_id' => $p->owner_id ?? $approver->id,
        ])->save();
        $p->log('emailed', self::SENT_PREFIX.' ('.$mail['stage'].', approved by '.$approver->name.'). '
            .($to === 'follow_up_2' ? 'Close as no reply if quiet by ' : 'Next follow-up due ').$nextDate->format('D j M').'.', $approver->id);

        return null;
    }

    /* ---- spaced sending: approve once, one email every few minutes -------------- */

    public const QUEUE_KEY = 'outreach_queue';

    /** Approved emails waiting for their slot: [{id, at (ISO), approver, status}]. */
    public function queued(): array
    {
        $q = \App\Models\Setting::get(self::QUEUE_KEY, []);
        if (is_string($q)) {
            $q = json_decode($q, true) ?: [];
        }

        return is_array($q) ? array_values($q) : [];
    }

    private function saveQueue(array $q): void
    {
        \App\Models\Setting::set(self::QUEUE_KEY, array_values($q), 'json', 'outreach');
    }

    /**
     * Approve the ticked emails to go out one at a time, every $minutes, the first
     * straight away (next scheduler minute). Only today's batch can be queued.
     */
    public function queue(array $ids, User $approver, int $minutes): array
    {
        $result = ['queued' => 0, 'skipped' => 0, 'times' => []];
        if (self::isWeekend()) {
            return $result;
        }
        $allowed = $this->candidates()->keyBy('id');
        $q = $this->queued();
        $at = now();
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $p = $allowed->get($id);
            if (! $p || $p->isDoNotContact() || ! $p->nextSend()) {
                $result['skipped']++;

                continue;
            }
            $q[] = ['id' => $p->id, 'at' => $at->toIso8601String(), 'approver' => $approver->id, 'status' => $p->status];
            $result['times'][$p->company] = $at->copy()->timezone(config('app.timezone'))->format('H:i');
            $result['queued']++;
            $at = $at->copy()->addMinutes($minutes);
        }
        $this->saveQueue($q);

        return $result;
    }

    /** Send any queued emails whose time has come (run every minute). */
    public function sendDue(): array
    {
        $result = ['sent' => 0, 'dropped' => 0, 'failed' => 0];
        $keep = [];
        foreach ($this->queued() as $item) {
            if (\Illuminate\Support\Carbon::parse($item['at'])->isFuture()) {
                $keep[] = $item;

                continue;
            }
            $p = Prospect::find($item['id']);
            $approver = User::find($item['approver']);
            // Re-check at send time: still the same step, not opted out, weekday, within today's limit.
            if (! $p || ! $approver || $p->status !== ($item['status'] ?? null) || $p->isDoNotContact()
                || ! $p->nextSend() || self::isWeekend() || $this->remainingToday() <= 0
                || \Illuminate\Support\Carbon::parse($item['at'])->lt(now()->subHours(6))) {
                $result['dropped']++;

                continue;
            }
            $why = $this->sendOne($p, $approver);
            if ($why === null) {
                $result['sent']++;
            } else {
                $result['failed']++;
                Log::warning('Queued outreach email not sent', ['prospect' => $p->id, 'why' => $why]);
            }
        }
        $this->saveQueue($keep);

        return $result;
    }
}
