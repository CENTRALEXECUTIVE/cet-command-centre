<?php

namespace App\Services\Assistant;

use App\Models\Booking;
use App\Models\Prospect;
use App\Models\Setting;
use App\Models\User;
use App\Services\Outreach\OutreachBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * "My assistant" (/assistant): one page with everything a director needs today.
 * Live from the system: today's and tomorrow's jobs, unallocated jobs, prepaid
 * future jobs and the drivers' share still to pay out (the cash cushion to
 * protect), outreach to send and replies. Typed in by the directors (stored in
 * settings, no migration): bank balances, money owed to us / by us, and the
 * shared to-do list. Nothing here sends anything.
 */
class AssistantBoard
{
    public const MONEY_KEY = 'pa_money';
    public const TODO_KEY = 'pa_todos';

    public function build(): array
    {
        $tz = config('app.timezone', 'Europe/London');
        $now = Carbon::now($tz);

        return [
            'now' => $now,
            'today' => $this->jobs($now->copy()->startOfDay(), $now->copy()->endOfDay()),
            'tomorrow' => $this->jobs($now->copy()->addDay()->startOfDay(), $now->copy()->addDay()->endOfDay()),
            'unallocatedSoon' => $this->unallocatedSoon($now),
            'prepaid' => $this->prepaid($now),
            'money' => self::money(),
            'todos' => self::todos(),
            'outreach' => $this->outreach(),
        ];
    }

    private function live()
    {
        return Booking::query()->whereNotIn('status', ['cancelled', 'no_show'])
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'demo'));
    }

    private function jobs(Carbon $from, Carbon $to)
    {
        return $this->live()->with(['driver', 'customer', 'vehicleType'])
            ->whereBetween('pickup_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->orderBy('pickup_at')->get();
    }

    private function unallocatedSoon(Carbon $now)
    {
        return $this->live()->with(['customer', 'vehicleType'])
            ->whereNull('driver_id')
            ->whereNotIn('status', ['complete'])
            ->whereBetween('pickup_at', [$now->copy()->utc(), $now->copy()->addDays(7)->utc()])
            ->orderBy('pickup_at')->get();
    }

    /**
     * Future jobs the customer has already paid for, and the drivers' share of
     * them. Jobs with a director (super admin) as driver are the directors' own
     * pay; everything else needs cash for an external driver.
     */
    private function prepaid(Carbon $now): array
    {
        $pct = (float) Setting::get('driver_pay_percent', 90) / 100;
        $directors = User::where('is_super_admin', true)->pluck('id')->all();
        $rows = $this->live()->whereNotIn('status', ['complete'])
            ->where('pickup_at', '>=', $now->copy()->utc())->get();

        $out = ['jobs' => 0, 'fares' => 0.0, 'external_share' => 0.0, 'director_share' => 0.0, 'next_30_days_external' => 0.0];
        foreach ($rows as $b) {
            if (! $b->fareIsPaid()) {
                continue;
            }
            $fare = (float) ($b->fareAmount() ?? 0);
            $share = round($fare * $pct, 2);
            $out['jobs']++;
            $out['fares'] += $fare;
            if ($b->driver_id && in_array($b->driver_id, $directors, true)) {
                $out['director_share'] += $share;
            } else {
                $out['external_share'] += $share;
                if ($b->pickup_at && $b->pickup_at->lte($now->copy()->addDays(30))) {
                    $out['next_30_days_external'] += $share;
                }
            }
        }

        return $out;
    }

    private function outreach(): array
    {
        $batch = app(OutreachBatch::class);

        return [
            'sent_today' => $batch->sentToday(),
            'limit' => OutreachBatch::limit(),
            'ready' => $batch->candidates()->count(),
            'follow_ups_due' => Prospect::followUpsDue()->count(),
            'replies_week' => Prospect::whereNotNull('replied_at')->where('replied_at', '>=', now()->subDays(7))->count(),
            'weekend' => OutreachBatch::isWeekend(),
        ];
    }

    /* ---- typed-in money board ------------------------------------------------ */

    public static function money(): array
    {
        $m = self::json(self::MONEY_KEY);

        return [
            'ltd' => $m['ltd'] ?? null,
            'pvt' => $m['pvt'] ?? null,
            'incoming' => $m['incoming'] ?? null,
            'owed_to_us' => array_values($m['owed_to_us'] ?? []),
            'we_owe' => array_values($m['we_owe'] ?? []),
            'updated_at' => $m['updated_at'] ?? null,
            'updated_by' => $m['updated_by'] ?? null,
        ];
    }

    public static function saveMoney(array $data, User $by): void
    {
        $lines = function (?string $text): array {
            $out = [];
            foreach (preg_split('/\R/', (string) $text) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                // "Name - 620" / "Name: £620" / "Name 620"
                if (preg_match('/^(.*?)[\s:\-–]*£?\s*([\d,]+(?:\.\d{1,2})?)\s*(.*)$/u', $line, $m) && $m[1] !== '') {
                    $out[] = ['label' => Str::limit(trim($m[1].' '.$m[3]), 80, ''), 'amount' => (float) str_replace(',', '', $m[2])];
                } else {
                    $out[] = ['label' => Str::limit($line, 80, ''), 'amount' => null];
                }
            }

            return array_slice($out, 0, 20);
        };

        Setting::set(self::MONEY_KEY, [
            'ltd' => $data['ltd'] !== null && $data['ltd'] !== '' ? (float) $data['ltd'] : null,
            'pvt' => $data['pvt'] !== null && $data['pvt'] !== '' ? (float) $data['pvt'] : null,
            'incoming' => $data['incoming'] !== null && $data['incoming'] !== '' ? (float) $data['incoming'] : null,
            'owed_to_us' => $lines($data['owed_to_us'] ?? ''),
            'we_owe' => $lines($data['we_owe'] ?? ''),
            'updated_at' => now()->toDateTimeString(),
            'updated_by' => $by->name,
        ], 'json', 'assistant');
    }

    /* ---- shared to-do list ----------------------------------------------------- */

    public static function todos(): array
    {
        return array_values(self::json(self::TODO_KEY));
    }

    public static function addTodo(string $text, ?string $due, User $by): void
    {
        $list = self::todos();
        $list[] = ['id' => (string) Str::uuid(), 'text' => Str::limit(trim($text), 200, ''), 'due' => $due ?: null,
            'done' => false, 'by' => $by->name, 'at' => now()->toDateTimeString()];
        Setting::set(self::TODO_KEY, array_slice($list, -100), 'json', 'assistant');
    }

    public static function toggleTodo(string $id): void
    {
        $list = array_map(fn ($t) => ($t['id'] ?? '') === $id ? ['done' => ! ($t['done'] ?? false)] + $t : $t, self::todos());
        Setting::set(self::TODO_KEY, $list, 'json', 'assistant');
    }

    public static function deleteTodo(string $id): void
    {
        Setting::set(self::TODO_KEY, array_values(array_filter(self::todos(), fn ($t) => ($t['id'] ?? '') !== $id)), 'json', 'assistant');
    }

    private static function json(string $key): array
    {
        $raw = Setting::get($key, []);
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }

        return is_array($raw) ? $raw : [];
    }
}
