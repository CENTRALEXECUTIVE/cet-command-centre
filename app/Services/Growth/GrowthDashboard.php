<?php

namespace App\Services\Growth;

use App\Models\Booking;
use App\Models\Message;
use App\Models\Prospect;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Numbers for the Growth dashboard (/marketing/growth): where bookings came
 * from week by week, new vs repeat customers, outreach progress, review
 * requests, and a hand-typed weekly log of social followers and Google reviews
 * (stored in the `growth_social_log` setting, so no migration is needed).
 *
 * Weeks run Monday to Sunday, UK time, and a booking counts in the week it
 * CAME IN (created_at), not the week it travels. Return legs are not counted
 * as extra bookings, but their fares are included in the revenue (ex-VAT).
 */
class GrowthDashboard
{
    public const CHANNELS = [
        'social' => 'Social media',
        'website' => 'Website (Google search / direct)',
        'ads' => 'Google Ads',
        'accounts' => 'Business accounts',
        'phone' => 'Phone / WhatsApp',
        'other' => 'Other',
    ];

    public const SOCIAL_FIELDS = [
        'instagram' => 'Instagram followers',
        'facebook' => 'Facebook followers',
        'linkedin' => 'LinkedIn followers',
        'tiktok' => 'TikTok followers',
        'google_reviews' => 'Google reviews',
        'google_rating' => 'Google rating',
        'posts' => 'Posts published',
    ];

    private const SOCIAL_SOURCES = [
        'facebook', 'fb', 'instagram', 'ig', 'linkedin', 'tiktok', 'youtube',
        'gbp', 'google_business', 'google-business', 'google-business-profile', 'googlebusiness',
    ];

    /** The Mondays (UK) of the last $weeks weeks, oldest first, ending with this week. */
    public function weeks(int $weeks = 8, ?Carbon $now = null): array
    {
        $tz = config('app.timezone', 'Europe/London');
        $monday = ($now ?? now())->copy()->setTimezone($tz)->startOfWeek(Carbon::MONDAY)->startOfDay();
        $out = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $out[] = $monday->copy()->subWeeks($i);
        }

        return $out;
    }

    public static function channelFor(Booking $b): string
    {
        $m = (array) (($b->meta ?? [])['marketing'] ?? []);
        $src = strtolower(trim((string) ($m['utm_source'] ?? '')));
        $medium = strtolower(trim((string) ($m['utm_medium'] ?? '')));

        if (in_array($src, self::SOCIAL_SOURCES, true) || in_array($medium, ['social', 'social-organic', 'organic-social'], true)) {
            return 'social';
        }
        if (filled($m['gclid'] ?? null) || filled($m['gbraid'] ?? null) || filled($m['wbraid'] ?? null)
            || in_array($medium, ['cpc', 'ppc', 'paid'], true)) {
            return 'ads';
        }
        if ($b->corporate_account_id || $b->source === 'portal') {
            return 'accounts';
        }
        if ($b->source === 'web') {
            return 'website';
        }
        if (in_array($b->source, ['phone', 'intake'], true)) {
            return 'phone';
        }

        return 'other';
    }

    public function build(int $weeks = 8, ?Carbon $now = null): array
    {
        $mondays = $this->weeks($weeks, $now);
        $from = $mondays[0]->copy()->utc();
        $to = end($mondays)->copy()->addWeek()->utc();
        $keys = array_map(fn (Carbon $d) => $d->toDateString(), $mondays);
        $tz = config('app.timezone', 'Europe/London');
        $weekOf = fn ($at) => Carbon::parse($at)->setTimezone($tz)->startOfWeek(Carbon::MONDAY)->toDateString();

        $blank = array_fill_keys($keys, 0);
        $channels = [];
        foreach (array_keys(self::CHANNELS) as $c) {
            $channels[$c] = ['count' => $blank, 'revenue' => $blank];
        }
        $totals = ['count' => $blank, 'revenue' => $blank, 'new' => $blank, 'repeat' => $blank];

        $bookings = Booking::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'demo'))
            ->get(['id', 'customer_id', 'corporate_account_id', 'source', 'meta', 'is_return_leg',
                'quoted_price', 'final_price', 'created_at']);

        $firstSeen = Booking::query()
            ->whereIn('customer_id', $bookings->pluck('customer_id')->filter()->unique())
            ->selectRaw('customer_id, MIN(created_at) as first_at')
            ->groupBy('customer_id')
            ->pluck('first_at', 'customer_id');

        $counted = [];
        foreach ($bookings as $b) {
            $wk = $weekOf($b->created_at);
            if (! isset($blank[$wk])) {
                continue;
            }
            $ch = self::channelFor($b);
            $fare = (float) ($b->final_price ?? $b->quoted_price ?? 0);
            $channels[$ch]['revenue'][$wk] += $fare;
            $totals['revenue'][$wk] += $fare;
            if ($b->is_return_leg) {
                continue;
            }
            $channels[$ch]['count'][$wk]++;
            $totals['count'][$wk]++;

            if ($b->customer_id && ! isset($counted[$wk][$b->customer_id])) {
                $counted[$wk][$b->customer_id] = true;
                $first = $firstSeen[$b->customer_id] ?? null;
                $isNew = $first && $weekOf($first) === $wk;
                $totals[$isNew ? 'new' : 'repeat'][$wk]++;
            }
        }

        return [
            'weeks' => $mondays,
            'keys' => $keys,
            'channels' => $channels,
            'totals' => $totals,
            'outreach' => $this->outreach($keys, $from, $to, $weekOf),
            'reviews' => $this->reviews($keys, $from, $to, $weekOf),
            'social' => $this->socialLog($keys),
        ];
    }

    private function outreach(array $keys, Carbon $from, Carbon $to, callable $weekOf): array
    {
        $byStatus = Prospect::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $total = (int) $byStatus->sum();
        $new = (int) ($byStatus['new'] ?? 0);
        $dnc = (int) ($byStatus['do_not_contact'] ?? 0);
        $contacted = $total - $new;
        $replied = (int) collect(Prospect::REPLIED)->sum(fn ($s) => (int) ($byStatus[$s] ?? 0));

        $blank = array_fill_keys($keys, 0);
        $contactedWk = $blank;
        $repliedWk = $blank;
        foreach (Prospect::whereBetween('last_contacted_at', [$from, $to])->pluck('last_contacted_at') as $at) {
            $wk = $weekOf($at);
            if (isset($contactedWk[$wk])) {
                $contactedWk[$wk]++;
            }
        }
        foreach (Prospect::whereBetween('replied_at', [$from, $to])->pluck('replied_at') as $at) {
            $wk = $weekOf($at);
            if (isset($repliedWk[$wk])) {
                $repliedWk[$wk]++;
            }
        }

        $funnel = [];
        foreach (Prospect::STATUSES as $key => $label) {
            $funnel[$key] = ['label' => $label, 'count' => (int) ($byStatus[$key] ?? 0)];
        }

        return [
            'total' => $total,
            'not_yet_emailed' => $new,
            'with_email_not_yet_emailed' => Prospect::where('status', 'new')->whereNotNull('contact_email')->where('contact_email', '!=', '')->count(),
            'contacted' => $contacted,
            'replied' => $replied,
            'reply_rate' => $contacted > 0 ? round(100 * $replied / $contacted, 1) : null,
            'accounts_opened' => (int) ($byStatus['account_opened'] ?? 0),
            'do_not_contact' => $dnc,
            'funnel' => $funnel,
            'contacted_by_week' => $contactedWk,
            'replied_by_week' => $repliedWk,
        ];
    }

    private function reviews(array $keys, Carbon $from, Carbon $to, callable $weekOf): array
    {
        $out = array_fill_keys($keys, 0);
        $rows = Message::where('type', 'review_request')->where('status', 'sent')
            ->whereBetween('sent_at', [$from, $to])->pluck('sent_at');
        foreach ($rows as $at) {
            $wk = $weekOf($at);
            if (isset($out[$wk])) {
                $out[$wk]++;
            }
        }

        return $out;
    }

    /** The hand-typed weekly social/review numbers, keyed by Monday date. */
    public static function log(): array
    {
        $raw = Setting::get('growth_social_log', []);
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }

        return is_array($raw) ? $raw : [];
    }

    private function socialLog(array $keys): array
    {
        $log = self::log();
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $log[$k] ?? [];
        }

        return $out;
    }

    /** Save one week's numbers. Blank fields are left out (never stored as 0). */
    public static function saveWeek(string $monday, array $values): void
    {
        $log = self::log();
        $row = [];
        foreach (array_keys(self::SOCIAL_FIELDS) as $f) {
            $v = $values[$f] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            $row[$f] = $f === 'google_rating' ? round((float) $v, 1) : (int) $v;
        }
        $log[$monday] = $row;
        ksort($log);
        Setting::set('growth_social_log', $log, 'json', 'growth');
    }
}
