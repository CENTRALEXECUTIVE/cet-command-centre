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
            'metaConnected' => app(\App\Services\Social\MetaInsightsService::class)->configured(),
            'metaPageId' => (string) Setting::get(\App\Services\Social\MetaInsightsService::PAGE_ID, ''),
            'metaLastSync' => Setting::get(\App\Services\Social\MetaInsightsService::LAST_SYNC),
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
            'followers' => $this->scc->followers()[$platform]
                ?? ['label' => SocialPost::PLATFORMS[$platform], 'now' => null, 'change' => null, 'unit' => 'contacts'],
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

    /** Save the Facebook Page ID / Page access token (blank token keeps the saved one), then test. */
    public function saveMeta(Request $request, \App\Services\Social\MetaInsightsService $meta): RedirectResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
        $data = $request->validate([
            'page_id' => ['required', 'regex:/^\d{5,25}$/'],
            'token' => ['nullable', 'string', 'max:1000'],
        ]);
        Setting::set(\App\Services\Social\MetaInsightsService::PAGE_ID, $data['page_id'], 'string', 'social');
        if (trim((string) ($data['token'] ?? '')) !== '') {
            Setting::set(\App\Services\Social\MetaInsightsService::TOKEN, trim($data['token']), 'string', 'social');
        }
        $t = $meta->test();

        return back()->with('status', $t['ok']
            ? 'Connected to '.$t['page'].($t['instagram'] ? ' and Instagram @'.$t['instagram'] : ' (no Instagram business account linked to this Page yet)').'. Numbers update every night, or tap Sync now.'
            : 'Not connected: '.$t['error']);
    }

    public function syncMeta(Request $request, \App\Services\Social\MetaInsightsService $meta): RedirectResponse
    {
        $this->admin($request);
        $r = $meta->sync();

        return back()->with('status', $r['ok']
            ? "Synced {$r['facebook']} Facebook and {$r['instagram']} Instagram posts".($r['created'] ? " ({$r['created']} added that were posted straight in the app)" : '').'.'
            : 'Sync failed: '.($r['error'] ?? 'unknown'));
    }

    /** Stream (or with ?dl=1 download) one of a post's photos/videos. Admin only. */
    public function media(Request $request, SocialPost $post, int $index)
    {
        $this->admin($request);
        $key = $post->mediaKeys()[$index] ?? null;
        $path = $key ? SocialPost::mediaPath($key) : null;
        abort_unless($path, 404);
        $name = \Illuminate\Support\Str::slug(\Illuminate\Support\Str::limit($post->title, 40, '')).'-'.($index + 1).'.'.pathinfo($path, PATHINFO_EXTENSION);

        return $request->boolean('dl')
            ? response()->download($path, $name)
            : response()->file($path, ['Cache-Control' => 'private, max-age=86400']);
    }

    /** Add the office's own photos/videos to a post. */
    public function uploadMedia(Request $request, SocialPost $post): RedirectResponse
    {
        $this->admin($request);
        $request->validate([
            'files' => ['required', 'array', 'max:10'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,mp4,mov', 'max:102400'],
        ]);
        $media = $post->mediaKeys();
        foreach ($request->file('files') as $file) {
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $name = \Illuminate\Support\Str::random(16).'.'.($ext === 'jpeg' ? 'jpg' : $ext);
            $file->storeAs(SocialPost::UPLOAD_DIR.'/'.$post->id, $name, 'local');
            $media[] = 'up:'.$post->id.'/'.$name;
        }
        $post->update(['media' => $media]);

        return back()->with('status', 'Added '.count($request->file('files')).' file(s).');
    }

    /** Take a file off a post (an uploaded file is deleted; a library file is just unlinked). */
    public function removeMedia(Request $request, SocialPost $post, int $index): RedirectResponse
    {
        $this->admin($request);
        $media = $post->mediaKeys();
        abort_unless(isset($media[$index]), 404);
        $key = $media[$index];
        if (str_starts_with($key, 'up:') && ($path = SocialPost::mediaPath($key))) {
            @unlink($path);
        }
        array_splice($media, $index, 1);
        $post->update(['media' => $media]);

        return back()->with('status', 'Removed.');
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
        $import = app(\App\Services\Social\SocialPlanImport::class);
        $n = $import->available() ? $import->load() : $pack->load();

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

    /**
     * "Why did this go viral?" The office pastes a competitor's (or any) post:
     * what it showed and said, plus its numbers. Claude breaks down the hook,
     * format and topic and writes a CET version, saved to the ideas bank.
     * Nothing is scraped: only what the office pastes is used.
     */
    public function breakdown(Request $request, AnthropicService $ai): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'source_platform' => ['nullable', Rule::in(array_keys(SocialPost::PLATFORMS))],
            'link' => ['nullable', 'url', 'max:500'],
            'content' => ['required', 'string', 'min:10', 'max:4000'],
            'stats' => ['nullable', 'string', 'max:200'],
        ]);
        if (! $ai->configured()) {
            return back()->withInput()->with('status', 'Claude is not connected (no AI key set), so it could not break the post down.');
        }

        $prompt = 'A social post did well. Break down why, then write a version for Central Executive Transfers (Sheffield executive chauffeur and airport transfers).'
            ."\nPlatform: ".($data['source_platform'] ?? 'unknown')
            ."\nNumbers: ".($data['stats'] ?? 'not given')
            ."\nWhat the post shows and says:\n".$data['content']
            ."\n\nRules for the CET version: no prices, never show customers' faces, names or flight details, never say the directors drive or that it is owner-run, nothing invented (only claims like licensed DBS-checked chauffeurs, flight tracking, meet and greet, fixed price quoted up front, phone answered 24/7, VAT invoices, Mercedes E-Class and V-Class). British English."
            .' JSON only: {"hook":"what grabs attention in the first 2 seconds","format":"e.g. talking head, POV, before/after, slideshow","topic":"what it is really about","why":"2-3 short sentences on why it worked","cet":{"title":"one line","platform":"tiktok|instagram|facebook|linkedin|gbp","hook":"on-screen text for the first 2 seconds","shot":"what to film, shot by shot","caption":"ready-to-post caption"}}';
        $out = $ai->completeJson($prompt, 'You are a short-form video strategist. Reply with only valid JSON.', ['max_tokens' => 1500, 'timeout' => 40]);
        $cet = is_array($out['cet'] ?? null) ? $out['cet'] : null;
        if (! $cet || empty($cet['title'])) {
            return back()->withInput()->with('status', 'Claude did not return a breakdown this time. Try again in a minute.');
        }

        $post = SocialPost::create([
            'platform' => isset(SocialPost::PLATFORMS[$cet['platform'] ?? '']) ? $cet['platform'] : ($data['source_platform'] ?? 'tiktok'),
            'status' => 'idea',
            'title' => mb_substr('CET version: '.$cet['title'], 0, 160),
            'caption' => trim('Hook: '.($cet['hook'] ?? '')."\n\n".($cet['caption'] ?? '')),
            'media_note' => mb_substr((string) ($cet['shot'] ?? ''), 0, 2000) ?: null,
            'notes' => mb_substr(implode("\n", array_filter([
                'Learned from: '.($data['link'] ?? 'a pasted post').($data['stats'] ? ' ('.$data['stats'].')' : ''),
                'Hook: '.($out['hook'] ?? '?'),
                'Format: '.($out['format'] ?? '?'),
                'Topic: '.($out['topic'] ?? '?'),
                'Why it worked: '.($out['why'] ?? '?'),
            ])), 0, 2000),
        ]);

        return redirect()->route('social.posts.edit', $post)->with('status', 'Broken down and saved to the ideas bank. The breakdown is in Notes.');
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
