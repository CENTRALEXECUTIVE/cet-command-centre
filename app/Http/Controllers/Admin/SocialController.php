<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SocialPost;
use App\Services\Ai\AnthropicService;
use App\Services\Growth\GrowthDashboard;
use App\Services\Social\SocialCommandCentre;
use App\Services\Social\SocialStarterPack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Social Media Command Centre (admin only): overview, per-platform tabs, the
 * posts list with an audit per post, a week planner and an ideas bank. Nothing
 * is posted anywhere from here: the office posts from each app, then marks the
 * post done and types in its numbers.
 */
class SocialController extends Controller
{
    public function __construct(private readonly SocialCommandCentre $scc) {}

    public function index(Request $request): View
    {
        $this->admin($request);

        return view('admin.social.index', [
            'followers' => $this->scc->followers(),
            'consistency' => $this->scc->consistency(),
            'weeks' => $this->scc->viewsByWeek(8),
            'bestDay' => $this->scc->bestDay(),
            'top' => $this->scc->topPosts(5),
            'upcoming' => $this->scc->upcoming(7),
            'overdue' => $this->scc->overdue(),
            'needsNumbers' => $this->scc->needsNumbers(),
            'summary' => $this->scc->summary(),
            'notes' => (string) Setting::get('social_notes', ''),
            'starterLoaded' => app(SocialStarterPack::class)->alreadyLoaded(),
            'anyPosts' => SocialPost::exists(),
        ]);
    }

    public function platform(Request $request, string $platform): View
    {
        $this->admin($request);
        abort_unless(isset(SocialPost::PLATFORMS[$platform]), 404);

        return view('admin.social.platform', [
            'platform' => $platform,
            'label' => SocialPost::PLATFORMS[$platform],
            'summary' => $this->scc->summary($platform),
            'followers' => $this->scc->followers()[$platform],
            'bestDay' => $this->scc->bestDay($platform),
            'top' => $this->scc->topPosts(5, $platform),
            'working' => $this->scc->working($platform),
            'next' => SocialPost::where('platform', $platform)->whereIn('status', ['planned', 'ready'])
                ->orderBy('scheduled_at')->limit(5)->get(),
        ]);
    }

    public function posts(Request $request): View
    {
        $this->admin($request);
        $q = SocialPost::query();
        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $q->where(fn ($w) => $w->where('title', 'like', "%{$search}%")
                ->orWhere('caption', 'like', "%{$search}%")
                ->orWhere('hashtags', 'like', "%{$search}%"));
        }
        foreach (['platform', 'status', 'pillar'] as $f) {
            if ($v = $request->query($f)) {
                $q->where($f, $v);
            }
        }
        $sort = $request->query('sort', 'newest');
        match ($sort) {
            'views' => $q->orderByRaw('views IS NULL')->orderByDesc('views'),
            'oldest' => $q->orderByRaw('COALESCE(posted_at, scheduled_at, created_at) asc'),
            default => $q->orderByRaw('COALESCE(posted_at, scheduled_at, created_at) desc'),
        };

        return view('admin.social.posts', [
            'posts' => $q->paginate(30)->withQueryString(),
            'filters' => $request->only(['q', 'platform', 'status', 'pillar', 'sort']),
            'averages' => collect(SocialPost::PLATFORMS)->map(fn ($l, $p) => $this->scc->summary($p)['avg_views']),
        ]);
    }

    public function planner(Request $request): View
    {
        $this->admin($request);
        $start = $this->scc->weekStart($request->query('week') ? Carbon::parse((string) $request->query('week')) : null);
        $end = $start->copy()->addWeek();
        $posts = SocialPost::where(fn ($w) => $w->whereBetween('scheduled_at', [$start, $end])
            ->orWhereBetween('posted_at', [$start, $end]))
            ->where('status', '!=', 'idea')->get()
            ->sortBy(fn ($p) => $p->when());

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $start->copy()->addDays($i);
            $days[] = ['date' => $d, 'posts' => $posts->filter(fn ($p) => $p->when()?->isSameDay($d))->values()];
        }

        return view('admin.social.planner', [
            'start' => $start,
            'days' => $days,
            'ideas' => SocialPost::where('status', 'idea')->latest()->get(),
            'consistency' => $this->scc->consistency(),
            'aiReady' => app(AnthropicService::class)->configured(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->admin($request);
        $post = new SocialPost([
            'platform' => $request->query('platform', 'instagram'),
            'status' => $request->query('date') ? 'planned' : 'idea',
            'scheduled_at' => $request->query('date') ? Carbon::parse($request->query('date').' 19:00') : null,
        ]);

        return view('admin.social.edit', ['post' => $post, 'audit' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->admin($request);
        $data = $this->validated($request);
        $platforms = $request->input('also', []);
        $post = SocialPost::create($data);
        // "Post everywhere": copy to the other platforms ticked.
        foreach (array_intersect((array) $platforms, array_keys(SocialPost::PLATFORMS)) as $p) {
            if ($p !== $post->platform) {
                SocialPost::create(['platform' => $p] + $data);
            }
        }

        return redirect()->route('social.posts.edit', $post)->with('status', 'Saved.');
    }

    public function edit(Request $request, SocialPost $post): View
    {
        $this->admin($request);

        return view('admin.social.edit', [
            'post' => $post,
            'audit' => $post->status === 'posted' ? $this->scc->audit($post) : null,
        ]);
    }

    public function update(Request $request, SocialPost $post): RedirectResponse
    {
        $this->admin($request);
        $post->update($this->validated($request));

        return redirect()->route('social.posts.edit', $post)->with('status', 'Saved.');
    }

    /** One tap from the planner/overview: it's gone out now. */
    public function markPosted(Request $request, SocialPost $post): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate(['url' => ['nullable', 'url', 'max:500']]);
        $post->update([
            'status' => 'posted',
            'posted_at' => $post->posted_at ?? now(),
            'url' => $data['url'] ?? $post->url,
        ]);

        return back()->with('status', 'Marked as posted on '.$post->platformLabel().'. Add its numbers in a day or two.');
    }

    public function destroy(Request $request, SocialPost $post): RedirectResponse
    {
        $this->admin($request);
        $post->delete();

        return redirect()->route('social.planner')->with('status', 'Deleted.');
    }

    public function loadStarter(Request $request, SocialStarterPack $pack): RedirectResponse
    {
        $this->admin($request);
        if ($pack->alreadyLoaded()) {
            return back()->with('status', 'The starter plan is already loaded.');
        }
        $n = $pack->load();

        return redirect()->route('social.planner')->with('status', "Loaded the starter plan: {$n} posts and ideas. Nothing has been posted; post from each app and tap Posted.");
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'goal' => ['nullable', 'integer', 'min:1', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        if (isset($data['goal'])) {
            Setting::set(SocialCommandCentre::GOAL_KEY, (string) $data['goal'], 'string', 'growth');
        }
        if ($request->has('notes')) {
            Setting::set('social_notes', (string) ($data['notes'] ?? ''), 'string', 'growth');
        }

        return back()->with('status', 'Saved.');
    }

    /** This week's follower counts (shared with the Growth dashboard log). */
    public function saveFollowers(Request $request): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'instagram' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'facebook' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'linkedin' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'tiktok' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'google_reviews' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        $monday = $this->scc->weekStart()->toDateString();
        $existing = GrowthDashboard::log()[$monday] ?? [];
        GrowthDashboard::saveWeek($monday, array_merge($existing, array_filter($data, fn ($v) => $v !== null)));

        return back()->with('status', 'Follower counts saved for this week.');
    }

    /** Ask Claude for fresh post ideas; they land in the ideas bank. */
    public function ideas(Request $request, AnthropicService $ai): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate(['topic' => ['nullable', 'string', 'max:200']]);
        if (! $ai->configured()) {
            return back()->with('status', 'Claude is not connected (no AI key set), so no ideas were generated.');
        }

        $top = $this->scc->topPosts(5)->map(fn ($p) => $p->platformLabel().': '.$p->title.' ('.number_format((int) $p->views).' views)')->implode('; ');
        $prompt = 'Suggest 6 short-form social post ideas for Central Executive Transfers, a Sheffield executive chauffeur and airport transfer company. Today is '.now()->format('j F Y').'.'
            .($data['topic'] ?? null ? ' Focus: "'.$data['topic'].'".' : '')
            .($top ? ' Best performers so far: '.$top.'.' : ' The account has not posted yet, so favour simple ideas that can be filmed on a normal working day.')
            .' Rules: no prices, never show customers\' faces, names or flight details, never say the directors drive or that it is owner-run, nothing invented (no fake reviews or claims).'
            .' Pillars: behind_scenes, cars, airport, weddings, trust, sheffield, business, seasonal. Platforms: tiktok, instagram, facebook, linkedin, gbp.'
            .' JSON only: {"ideas":[{"title":"one line","platform":"tiktok","pillar":"airport","hook":"first 2 seconds text","shot":"what to film"}]}';
        $out = $ai->completeJson($prompt, 'You are a social media strategist for a premium UK chauffeur company. British English. Reply with only valid JSON.', ['max_tokens' => 1500, 'timeout' => 40]);

        $n = 0;
        foreach (($out['ideas'] ?? []) as $idea) {
            if (! is_array($idea) || empty($idea['title'])) {
                continue;
            }
            SocialPost::create([
                'platform' => isset(SocialPost::PLATFORMS[$idea['platform'] ?? '']) ? $idea['platform'] : 'instagram',
                'pillar' => isset(SocialPost::PILLARS[$idea['pillar'] ?? '']) ? $idea['pillar'] : null,
                'status' => 'idea',
                'title' => mb_substr((string) $idea['title'], 0, 160),
                'caption' => isset($idea['hook']) ? 'Hook: '.$idea['hook'] : null,
                'media_note' => $idea['shot'] ?? null,
                'notes' => 'Idea from Claude, '.now()->format('j M Y'),
            ]);
            $n++;
        }

        return back()->with('status', $n ? "Added {$n} new ideas to the ideas bank." : 'Claude did not return any ideas this time. Try again in a minute.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(array_keys(SocialPost::PLATFORMS))],
            'status' => ['required', Rule::in(array_keys(SocialPost::STATUSES))],
            'format' => ['nullable', 'string', 'max:40'],
            'pillar' => ['nullable', Rule::in(array_keys(SocialPost::PILLARS))],
            'title' => ['required', 'string', 'max:160'],
            'caption' => ['nullable', 'string', 'max:5000'],
            'hashtags' => ['nullable', 'string', 'max:500'],
            'media_note' => ['nullable', 'string', 'max:2000'],
            'scheduled_at' => ['nullable', 'date'],
            'posted_at' => ['nullable', 'date'],
            'url' => ['nullable', 'url', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'views' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'likes' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'comments' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'shares' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'saves' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'follows' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'enquiries' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        if ($data['status'] === 'posted' && empty($data['posted_at'])) {
            $data['posted_at'] = $data['scheduled_at'] ?? now();
        }

        return $data;
    }

    private function admin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403);
    }
}
