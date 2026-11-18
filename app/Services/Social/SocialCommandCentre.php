<?php

namespace App\Services\Social;

use App\Models\Setting;
use App\Models\SocialPost;
use App\Services\Growth\GrowthDashboard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The numbers behind the Social Media Command Centre: followers per platform,
 * views by week, best day to post, posting consistency against the weekly goal,
 * top posts, what's working per platform, and a per-post audit against the
 * platform average. Everything comes from what the office types in (post
 * numbers from each app's insights, follower counts in the weekly log), so it
 * costs nothing and needs no platform API.
 */
class SocialCommandCentre
{
    public const GOAL_KEY = 'social_weekly_goal';

    public const DEFAULT_GOAL = 10;

    /** Platform → key in the growth dashboard's weekly follower log. */
    public const FOLLOWER_FIELDS = [
        'tiktok' => 'tiktok',
        'instagram' => 'instagram',
        'facebook' => 'facebook',
        'linkedin' => 'linkedin',
        'gbp' => 'google_reviews',
    ];

    public static function weeklyGoal(): int
    {
        $g = (int) Setting::get(self::GOAL_KEY, self::DEFAULT_GOAL);

        return $g > 0 ? min($g, 100) : self::DEFAULT_GOAL;
    }

    public function weekStart(?Carbon $at = null): Carbon
    {
        return ($at ?? now())->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    /** @return Collection<int, SocialPost> */
    private function posted(): Collection
    {
        return SocialPost::where('status', 'posted')->whereNotNull('posted_at')->orderByDesc('posted_at')->get();
    }

    /**
     * Followers per platform: latest logged figure and the change over the
     * previous four logged weeks.
     *
     * @return array<string, array{label:string, now:?int, change:?int, unit:string}>
     */
    public function followers(): array
    {
        $log = GrowthDashboard::log();
        ksort($log);
        $weeks = array_keys($log);
        $out = [];
        foreach (self::FOLLOWER_FIELDS as $platform => $field) {
            $series = [];
            foreach ($weeks as $w) {
                if (isset($log[$w][$field]) && $log[$w][$field] !== '') {
                    $series[$w] = (int) $log[$w][$field];
                }
            }
            $now = $series ? end($series) : null;
            $vals = array_values($series);
            $base = count($vals) > 1 ? $vals[max(0, count($vals) - 5)] : null;
            $out[$platform] = [
                'label' => SocialPost::PLATFORMS[$platform],
                'now' => $now,
                'change' => $now !== null && $base !== null ? $now - $base : null,
                'unit' => $platform === 'gbp' ? 'reviews' : 'followers',
            ];
        }

        return $out;
    }

    /**
     * Posts out this week vs the goal, per platform, plus the run of weeks in a
     * row the goal was hit.
     *
     * @return array{goal:int, done:int, pct:int, by_platform:array<string,int>, streak:int, planned_left:int}
     */
    public function consistency(): array
    {
        $goal = self::weeklyGoal();
        $start = $this->weekStart();
        $end = $start->copy()->addWeek();
        $week = SocialPost::where('status', 'posted')->whereBetween('posted_at', [$start, $end])->get();

        $by = [];
        foreach (array_keys(SocialPost::PLATFORMS) as $p) {
            $by[$p] = $week->where('platform', $p)->count();
        }

        $streak = 0;
        for ($i = 1; $i <= 52; $i++) {
            $s = $start->copy()->subWeeks($i);
            $n = SocialPost::where('status', 'posted')->whereBetween('posted_at', [$s, $s->copy()->addWeek()])->count();
            if ($n < $goal) {
                break;
            }
            $streak++;
        }
        if ($week->count() >= $goal) {
            $streak++;
        }

        return [
            'goal' => $goal,
            'done' => $week->count(),
            'pct' => (int) min(100, round($week->count() / max(1, $goal) * 100)),
            'by_platform' => $by,
            'streak' => $streak,
            'planned_left' => SocialPost::whereIn('status', ['planned', 'ready'])
                ->whereBetween('scheduled_at', [now(), $end])->count(),
        ];
    }

    /**
     * Total views per week (by the day a post went out) for the last $weeks
     * weeks, oldest first, split by platform.
     *
     * @return array<int, array{week:string, label:string, total:int, by:array<string,int>}>
     */
    public function viewsByWeek(int $weeks = 8): array
    {
        $first = $this->weekStart()->subWeeks($weeks - 1);
        $posts = SocialPost::where('status', 'posted')->where('posted_at', '>=', $first)->get();
        $out = [];
        for ($i = 0; $i < $weeks; $i++) {
            $s = $first->copy()->addWeeks($i);
            $e = $s->copy()->addWeek();
            $in = $posts->filter(fn ($p) => $p->posted_at->gte($s) && $p->posted_at->lt($e));
            $by = [];
            foreach (array_keys(SocialPost::PLATFORMS) as $p) {
                $by[$p] = (int) $in->where('platform', $p)->sum('views');
            }
            $out[] = ['week' => $s->toDateString(), 'label' => $s->format('j M'), 'total' => array_sum($by), 'by' => $by];
        }

        return $out;
    }

    /**
     * Average views by day of the week (Mon..Sun), and the best day. Only
     * posts with views typed in count.
     *
     * @return array{days:array<string, array{avg:int, posts:int}>, best:?string, enough:bool}
     */
    public function bestDay(?string $platform = null): array
    {
        $posts = $this->posted()->whereNotNull('views');
        if ($platform) {
            $posts = $posts->where('platform', $platform);
        }
        $days = [];
        foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d) {
            $on = $posts->filter(fn ($p) => $p->posted_at->format('D') === $d);
            $days[$d] = ['avg' => $on->count() ? (int) round($on->avg('views')) : 0, 'posts' => $on->count()];
        }
        $withData = array_filter($days, fn ($d) => $d['posts'] > 0);
        $best = null;
        if ($withData) {
            uasort($withData, fn ($a, $b) => $b['avg'] <=> $a['avg']);
            $best = array_key_first($withData);
        }

        return ['days' => $days, 'best' => $best, 'enough' => $posts->count() >= 6];
    }

    /** @return Collection<int, SocialPost> */
    public function topPosts(int $n = 5, ?string $platform = null): Collection
    {
        $q = SocialPost::where('status', 'posted')->whereNotNull('views');
        if ($platform) {
            $q->where('platform', $platform);
        }

        return $q->orderByDesc('views')->limit($n)->get();
    }

    /** @return Collection<int, SocialPost> */
    public function upcoming(int $days = 7): Collection
    {
        return SocialPost::whereIn('status', ['planned', 'ready'])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>=', now()->startOfDay())
            ->where('scheduled_at', '<', now()->startOfDay()->addDays($days))
            ->orderBy('scheduled_at')->get();
    }

    /** Planned/ready posts whose time has passed without being marked posted. */
    public function overdue(): Collection
    {
        return SocialPost::whereIn('status', ['planned', 'ready'])
            ->whereNotNull('scheduled_at')->where('scheduled_at', '<', now()->startOfDay())
            ->orderBy('scheduled_at')->get();
    }

    /** Posts out for 2+ days with no numbers typed in yet. */
    public function needsNumbers(): Collection
    {
        return SocialPost::where('status', 'posted')
            ->where('posted_at', '<=', now()->subDays(2))
            ->where('posted_at', '>=', now()->subDays(30))
            ->whereNull('views')
            ->orderBy('posted_at')->get();
    }

    /**
     * Totals and averages for one platform (or all), over posts with views.
     *
     * @return array{posts:int, views:int, avg_views:int, avg_eng_rate:?float, follows:int, enquiries:int}
     */
    public function summary(?string $platform = null): array
    {
        $posts = $this->posted();
        if ($platform) {
            $posts = $posts->where('platform', $platform);
        }
        $withViews = $posts->whereNotNull('views');
        $rates = $withViews->map(fn ($p) => $p->engagementRate())->filter(fn ($r) => $r !== null);

        return [
            'posts' => $posts->count(),
            'views' => (int) $withViews->sum('views'),
            'avg_views' => $withViews->count() ? (int) round($withViews->avg('views')) : 0,
            'avg_eng_rate' => $rates->count() ? round($rates->avg(), 1) : null,
            'follows' => (int) $posts->sum('follows'),
            'enquiries' => (int) $posts->sum('enquiries'),
        ];
    }

    /**
     * What's working and what's not on a platform: average views by content
     * pillar and by format, best first. Needs at least two posts in a group.
     *
     * @return array{working:array<int,string>, not:array<int,string>}
     */
    public function working(string $platform): array
    {
        $posts = $this->posted()->where('platform', $platform)->whereNotNull('views');
        $avg = $posts->count() ? $posts->avg('views') : 0;
        $working = [];
        $not = [];
        foreach (['pillar' => fn ($k) => SocialPost::PILLARS[$k] ?? $k, 'format' => fn ($k) => $k] as $field => $label) {
            foreach ($posts->whereNotNull($field)->groupBy($field) as $key => $group) {
                if ($group->count() < 2 || $avg <= 0) {
                    continue;
                }
                $diff = (int) round(($group->avg('views') - $avg) / $avg * 100);
                $line = $label($key).': '.number_format((int) round($group->avg('views'))).' avg views ('.($diff >= 0 ? '+' : '').$diff.'% vs your average, '.$group->count().' posts)';
                if ($diff >= 15) {
                    $working[] = $line;
                } elseif ($diff <= -15) {
                    $not[] = $line;
                }
            }
        }

        return ['working' => $working, 'not' => $not];
    }

    /**
     * Audit one post against the average of the OTHER posts on the same
     * platform: each metric's % difference and a plain verdict.
     *
     * @return array{baseline:int, rows:array<int, array{metric:string, value:?int, avg:?int, diff:?int}>, verdict:array<int,string>}
     */
    public function audit(SocialPost $post): array
    {
        $others = SocialPost::where('status', 'posted')->where('platform', $post->platform)
            ->where('id', '!=', $post->id)->get();
        $rows = [];
        $verdict = [];
        foreach (['views', 'likes', 'comments', 'shares', 'saves', 'follows'] as $m) {
            $have = $others->whereNotNull($m);
            $avg = $have->count() ? (int) round($have->avg($m)) : null;
            $val = $post->{$m};
            $diff = ($val !== null && $avg) ? (int) round(($val - $avg) / $avg * 100) : null;
            $rows[] = ['metric' => ucfirst($m), 'value' => $val, 'avg' => $avg, 'diff' => $diff];
        }

        $byMetric = collect($rows)->keyBy('metric');
        $v = $byMetric['Views']['diff'];
        if ($post->views === null) {
            $verdict[] = 'Type in this post\'s numbers from the app\'s insights to audit it.';
        } elseif ($others->whereNotNull('views')->count() < 3) {
            $verdict[] = 'Too early to compare: post a few more on '.$post->platformLabel().' first (needs 3 others with views).';
        } else {
            if ($v >= 50) {
                $verdict[] = 'Strong: views well above your usual. Make another post on the same idea or with the same hook.';
            } elseif ($v <= -40) {
                $verdict[] = 'Under par on reach. Try a stronger first two seconds (hook text on screen), a trending sound, or a better time of day.';
            } else {
                $verdict[] = 'About your usual reach.';
            }
            $rate = $post->engagementRate();
            $avgRates = $others->map(fn ($p) => $p->engagementRate())->filter(fn ($r) => $r !== null);
            if ($rate !== null && $avgRates->count()) {
                $ar = round($avgRates->avg(), 1);
                if ($rate >= $ar * 1.3) {
                    $verdict[] = "People who saw it engaged more than usual ({$rate}% vs {$ar}%): the content landed, so push reach on the next one.";
                } elseif ($rate <= $ar * 0.7) {
                    $verdict[] = "Lower engagement than usual ({$rate}% vs {$ar}%): ask a question or add a clear reason to comment or save.";
                }
            }
            if (($byMetric['Saves']['diff'] ?? null) !== null && $byMetric['Saves']['diff'] >= 50) {
                $verdict[] = 'Lots of saves: useful content. Turn it into a series.';
            }
            if (($byMetric['Shares']['diff'] ?? null) !== null && $byMetric['Shares']['diff'] >= 50) {
                $verdict[] = 'Shared more than usual: this is the kind of post that finds new people.';
            }
        }
        if ($post->enquiries) {
            $verdict[] = 'Brought '.$post->enquiries.' enquir'.($post->enquiries === 1 ? 'y' : 'ies').': the best result a post can have.';
        }

        return ['baseline' => $others->whereNotNull('views')->count(), 'rows' => $rows, 'verdict' => $verdict];
    }
}
