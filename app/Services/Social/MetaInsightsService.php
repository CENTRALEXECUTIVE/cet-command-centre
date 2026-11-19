<?php

namespace App\Services\Social;

use App\Models\Setting;
use App\Models\SocialPost;
use App\Services\Growth\GrowthDashboard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * READ-ONLY pull of Facebook Page and Instagram (Business/Creator) numbers via
 * Meta's free Graph API: follower counts into the weekly growth log, and each
 * recent post's views/likes/comments/shares/saves into the Social Media Command
 * Centre. Planned posts that went out are matched and marked Posted; posts made
 * straight in the app are added. Never posts, edits or deletes anything on Meta.
 * Silent no-op until a Page ID and Page access token are saved. Raw HTTP, no SDK.
 */
class MetaInsightsService
{
    public const PAGE_ID = 'meta_page_id';

    public const TOKEN = 'meta_page_token';

    public const IG_ID = 'meta_ig_user_id';

    public const LAST_SYNC = 'meta_last_sync';

    public function configured(): bool
    {
        return filled(Setting::get(self::PAGE_ID)) && filled(Setting::get(self::TOKEN));
    }

    private function base(): string
    {
        return 'https://graph.facebook.com/'.config('services.meta.graph_version', 'v23.0').'/';
    }

    /** @return array<string, mixed>|null */
    private function get(string $path, array $query = []): ?array
    {
        try {
            $r = Http::timeout(20)->get($this->base().ltrim($path, '/'), $query + ['access_token' => Setting::get(self::TOKEN)]);
            if ($r->successful()) {
                return $r->json();
            }
            Log::info('Meta Graph call failed', ['path' => Str::before($path, '?'), 'status' => $r->status(), 'error' => $r->json('error.message')]);
        } catch (\Throwable $e) {
            Log::info('Meta Graph call error', ['path' => Str::before($path, '?'), 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Check the saved details work. Returns what was found, for the settings
     * card: page name, followers, and the linked Instagram account (if any).
     *
     * @return array{ok:bool, page?:string, page_followers?:int, instagram?:?string, ig_followers?:?int, error?:string}
     */
    public function test(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'error' => 'Add the Page ID and Page access token first.'];
        }
        $page = $this->get((string) Setting::get(self::PAGE_ID), ['fields' => 'name,followers_count,fan_count,instagram_business_account{id,username,followers_count}']);
        if (! $page) {
            return ['ok' => false, 'error' => 'Meta refused the details. Check the Page ID and that the token is a Page access token with read permissions.'];
        }
        $ig = $page['instagram_business_account'] ?? null;
        if ($ig['id'] ?? null) {
            Setting::set(self::IG_ID, (string) $ig['id'], 'string', 'social');
        }

        return [
            'ok' => true,
            'page' => $page['name'] ?? '?',
            'page_followers' => (int) ($page['followers_count'] ?? $page['fan_count'] ?? 0),
            'instagram' => $ig['username'] ?? null,
            'ig_followers' => isset($ig['followers_count']) ? (int) $ig['followers_count'] : null,
        ];
    }

    /**
     * Pull everything. Returns a short summary for the log/flash message.
     *
     * @return array{ok:bool, facebook:int, instagram:int, created:int, error?:string}
     */
    public function sync(): array
    {
        $out = ['ok' => false, 'facebook' => 0, 'instagram' => 0, 'created' => 0];
        $info = $this->test();
        if (! $info['ok']) {
            return $out + ['error' => $info['error'] ?? 'Not connected.'];
        }

        // Followers → this week's row in the shared growth log.
        $monday = now()->startOfWeek(Carbon::MONDAY)->toDateString();
        $row = GrowthDashboard::log()[$monday] ?? [];
        $row['facebook'] = $info['page_followers'];
        if ($info['ig_followers'] !== null) {
            $row['instagram'] = $info['ig_followers'];
        }
        GrowthDashboard::saveWeek($monday, $row);

        [$fb, $c1] = $this->syncFacebookPosts();
        [$ig, $c2] = $this->syncInstagramMedia();
        Setting::set(self::LAST_SYNC, now()->toIso8601String(), 'string', 'social');

        return ['ok' => true, 'facebook' => $fb, 'instagram' => $ig, 'created' => $c1 + $c2];
    }

    /** @return array{0:int,1:int} [updated, created] */
    private function syncInstagramMedia(): array
    {
        $igId = Setting::get(self::IG_ID);
        if (! $igId) {
            return [0, 0];
        }
        $list = $this->get($igId.'/media', ['fields' => 'id,caption,media_type,media_product_type,permalink,timestamp,like_count,comments_count', 'limit' => 50]);
        $done = 0;
        $created = 0;
        foreach (($list['data'] ?? []) as $m) {
            if (empty($m['timestamp']) || Carbon::parse($m['timestamp'])->lt(now()->subDays(60))) {
                continue;
            }
            $ins = $this->insights($m['id'], ['views', 'reach', 'saved', 'shares']) ?: $this->insights($m['id'], ['reach', 'saved', 'shares']);
            $format = ($m['media_product_type'] ?? '') === 'REELS' ? 'Reel'
                : (($m['media_type'] ?? '') === 'CAROUSEL_ALBUM' ? 'Carousel' : (($m['media_product_type'] ?? '') === 'STORY' ? 'Story' : 'Photo'));
            $wasNew = $this->upsert('instagram', $m['permalink'] ?? null, Carbon::parse($m['timestamp']), (string) ($m['caption'] ?? ''), $format, [
                'views' => $ins['views'] ?? $ins['reach'] ?? null,
                'likes' => $m['like_count'] ?? null,
                'comments' => $m['comments_count'] ?? null,
                'shares' => $ins['shares'] ?? null,
                'saves' => $ins['saved'] ?? null,
            ]);
            $done++;
            $created += $wasNew ? 1 : 0;
        }

        return [$done, $created];
    }

    /** @return array{0:int,1:int} [updated, created] */
    private function syncFacebookPosts(): array
    {
        $list = $this->get(Setting::get(self::PAGE_ID).'/posts', [
            'fields' => 'id,message,created_time,permalink_url,shares,reactions.summary(true).limit(0),comments.summary(true).limit(0)',
            'limit' => 50,
        ]);
        $done = 0;
        $created = 0;
        foreach (($list['data'] ?? []) as $p) {
            if (empty($p['created_time']) || Carbon::parse($p['created_time'])->lt(now()->subDays(60))) {
                continue;
            }
            $views = null;
            foreach (['post_media_view', 'post_impressions_unique'] as $metric) {
                $ins = $this->insights($p['id'], [$metric]);
                if (isset($ins[$metric])) {
                    $views = $ins[$metric];
                    break;
                }
            }
            $wasNew = $this->upsert('facebook', $p['permalink_url'] ?? null, Carbon::parse($p['created_time']), (string) ($p['message'] ?? ''), null, [
                'views' => $views,
                'likes' => $p['reactions']['summary']['total_count'] ?? null,
                'comments' => $p['comments']['summary']['total_count'] ?? null,
                'shares' => $p['shares']['count'] ?? 0,
            ]);
            $done++;
            $created += $wasNew ? 1 : 0;
        }

        return [$done, $created];
    }

    /** @return array<string,int> metric => lifetime value; empty when Meta refuses */
    private function insights(string $id, array $metrics): array
    {
        $res = $this->get($id.'/insights', ['metric' => implode(',', $metrics)]);
        $out = [];
        foreach (($res['data'] ?? []) as $row) {
            $v = $row['values'][0]['value'] ?? $row['total_value']['value'] ?? null;
            if (is_numeric($v)) {
                $out[$row['name']] = (int) $v;
            }
        }

        return $out;
    }

    /**
     * Match the live post to one in the Command Centre (same link; else a planned
     * post on that platform within 12h, or a posted one within 36h, of when it
     * went out) and write its numbers. Unmatched posts are added as Posted.
     * Returns true when a new row was created.
     */
    private function upsert(string $platform, ?string $url, Carbon $at, string $text, ?string $format, array $numbers): bool
    {
        $at = $at->copy()->setTimezone(config('app.timezone'));
        $post = $url ? SocialPost::where('platform', $platform)->where('url', $url)->first() : null;
        $post ??= SocialPost::where('platform', $platform)->where('status', 'posted')->whereNull('url')
            ->whereBetween('posted_at', [$at->copy()->subHours(36), $at->copy()->addHours(36)])->orderBy('posted_at')->first();
        $post ??= SocialPost::where('platform', $platform)->whereIn('status', ['planned', 'ready'])
            ->whereBetween('scheduled_at', [$at->copy()->subHours(12), $at->copy()->addHours(12)])->orderBy('scheduled_at')->first();

        $numbers = array_filter($numbers, fn ($v) => $v !== null);
        if ($post) {
            $post->update($numbers + [
                'status' => 'posted',
                'posted_at' => $post->posted_at ?? $at,
                'url' => $post->url ?: $url,
            ]);

            return false;
        }

        $first = trim(Str::before(trim($text), "\n")) ?: ucfirst($platform).' post';
        SocialPost::create($numbers + [
            'platform' => $platform,
            'status' => 'posted',
            'format' => $format,
            'title' => Str::limit($first, 150),
            'caption' => $text !== '' ? Str::limit($text, 4900) : null,
            'posted_at' => $at,
            'url' => $url,
            'notes' => 'Added from '.SocialPost::PLATFORMS[$platform].' by the nightly sync',
        ]);

        return true;
    }
}
