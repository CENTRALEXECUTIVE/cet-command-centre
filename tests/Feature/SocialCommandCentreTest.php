<?php

namespace Tests\Feature;

use App\Models\SocialPost;
use App\Models\User;
use App\Services\Growth\GrowthDashboard;
use App\Services\Social\SocialCommandCentre;
use App\Services\Social\SocialStarterPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SocialCommandCentreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 18 Nov 2026 → this week starts Mon 16 Nov.
        $this->travelTo(Carbon::parse('2026-11-18 12:00:00'));
    }

    private function posted(string $platform, string $at, ?int $views, array $extra = []): SocialPost
    {
        return SocialPost::create($extra + [
            'platform' => $platform, 'status' => 'posted', 'title' => 'Post '.$at,
            'posted_at' => $at, 'views' => $views,
        ]);
    }

    public function test_pages_are_admin_only_and_render_empty(): void
    {
        $driver = User::factory()->create();
        $this->actingAs($driver)->get(route('social.index'))->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('social.index'))->assertOk()
            ->assertSee('Nothing posted yet')->assertSee('Load the 2-week starter plan');
        foreach (['social.planner', 'social.posts', 'social.posts.create'] as $r) {
            $this->actingAs($admin)->get(route($r))->assertOk();
        }
        foreach (array_keys(SocialPost::PLATFORMS) as $p) {
            $this->actingAs($admin)->get(route('social.platform', $p))->assertOk();
        }
        $this->actingAs($admin)->get(route('social.platform', 'myspace'))->assertNotFound();
    }

    public function test_starter_plan_loads_two_weeks_once_and_posts_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('social.starter'))->assertRedirect(route('social.planner'));

        $planned = SocialPost::where('status', 'planned')->get();
        $this->assertGreaterThan(10, $planned->count());
        $this->assertSame(0, SocialPost::where('status', 'posted')->count());
        // Starts tomorrow, runs two weeks.
        $this->assertTrue($planned->min('scheduled_at')->gte(Carbon::parse('2026-11-19')));
        $this->assertTrue($planned->max('scheduled_at')->lt(Carbon::parse('2026-12-03')));
        // The button loads the full prepared plan; the prep Reel goes to TikTok, Instagram and Facebook.
        $prep = $planned->where('title', 'Before every job (your own footage)');
        $this->assertEqualsCanonicalizing(['tiktok', 'instagram', 'facebook'], $prep->pluck('platform')->all());
        // House rules: no prices in any caption.
        foreach (SocialPost::all() as $p) {
            $this->assertDoesNotMatchRegularExpression('/£\s?\d/', (string) $p->caption);
        }
        $this->assertGreaterThan(0, SocialPost::where('status', 'idea')->count());

        // Second tap doesn't duplicate.
        $n = SocialPost::count();
        $this->actingAs($admin)->post(route('social.starter'));
        $this->assertSame($n, SocialPost::count());

        $this->actingAs($admin)->get(route('social.planner', ['week' => '2026-11-16']))->assertOk()
            ->assertSee('Before every job (your own footage)');
    }

    public function test_create_with_cross_post_then_mark_posted(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('social.posts.store'), [
            'platform' => 'tiktok', 'status' => 'planned', 'title' => 'Airport arrivals',
            'caption' => 'We track your flight.', 'scheduled_at' => '2026-11-19 19:00',
            'also' => ['instagram', 'facebook', 'tiktok'],
        ])->assertRedirect();

        $this->assertSame(3, SocialPost::where('title', 'Airport arrivals')->count());

        $post = SocialPost::where('platform', 'tiktok')->first();
        $this->actingAs($admin)->post(route('social.posts.posted', $post))->assertRedirect();
        $post->refresh();
        $this->assertSame('posted', $post->status);
        $this->assertTrue($post->posted_at->eq(now()));
    }

    public function test_overview_stats_consistency_best_day_and_top_posts(): void
    {
        \App\Models\Setting::set(SocialCommandCentre::GOAL_KEY, '2');
        // Last week: 2 posts (goal hit). This week: 2 posts (goal hit) → streak 2.
        $this->posted('tiktok', '2026-11-10 19:00', 1000, ['likes' => 100]); // Tue
        $this->posted('instagram', '2026-11-12 19:00', 300);                // Thu
        $this->posted('tiktok', '2026-11-17 19:00', 2000, ['likes' => 100]); // Tue
        $this->posted('facebook', '2026-11-18 09:00', null);                // no numbers yet

        $scc = app(SocialCommandCentre::class);
        $c = $scc->consistency();
        $this->assertSame(2, $c['done']);
        $this->assertSame(100, $c['pct']);
        $this->assertSame(2, $c['streak']);
        $this->assertSame(1, $c['by_platform']['tiktok']);

        $best = $scc->bestDay();
        $this->assertSame('Tue', $best['best']);
        $this->assertSame(1500, $best['days']['Tue']['avg']);

        $this->assertSame([2000, 1000, 300], $scc->topPosts()->pluck('views')->all());

        $weeks = $scc->viewsByWeek(8);
        $this->assertCount(8, $weeks);
        $this->assertSame(2000, end($weeks)['total']);
        $this->assertSame(1300, $weeks[6]['total']);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('social.index'))->assertOk()->assertSee('Top 5 posts')->assertDontSee('Nothing posted yet');
    }

    public function test_audit_compares_with_the_platform_average(): void
    {
        foreach ([1000, 1000, 1000] as $i => $v) {
            $this->posted('tiktok', '2026-11-0'.($i + 2).' 19:00', $v, ['likes' => 50]);
        }
        $this->posted('instagram', '2026-11-05 19:00', 99999); // other platform ignored
        $star = $this->posted('tiktok', '2026-11-09 19:00', 2500, ['likes' => 300, 'enquiries' => 2]);

        $audit = app(SocialCommandCentre::class)->audit($star);
        $this->assertSame(3, $audit['baseline']);
        $views = collect($audit['rows'])->firstWhere('metric', 'Views');
        $this->assertSame(1000, $views['avg']);
        $this->assertSame(150, $views['diff']);
        $this->assertStringContainsString('Strong', $audit['verdict'][0]);
        $this->assertStringContainsString('2 enquiries', implode(' ', $audit['verdict']));

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('social.posts.edit', $star))->assertOk()->assertSee('Audit')->assertSee('+150%');
    }

    public function test_follower_counts_feed_the_growth_log_and_cards(): void
    {
        GrowthDashboard::saveWeek('2026-11-09', ['instagram' => 40, 'tiktok' => 10]);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('social.followers'), ['instagram' => 55])->assertRedirect();

        $log = GrowthDashboard::log();
        $this->assertSame(55, $log['2026-11-16']['instagram']);
        $f = app(SocialCommandCentre::class)->followers();
        $this->assertSame(55, $f['instagram']['now']);
        $this->assertSame(15, $f['instagram']['change']);
        $this->assertSame(10, $f['tiktok']['now']);
    }

    public function test_ideas_without_ai_key_create_nothing(): void
    {
        config(['services.anthropic.key' => null]);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('social.ideas'))->assertRedirect();
        $this->assertSame(0, SocialPost::count());
    }

    public function test_prepared_plan_loads_from_tomorrow_without_doubling_the_starter(): void
    {
        $admin = User::factory()->admin()->create();
        app(SocialStarterPack::class)->load(); // tapped earlier, untouched
        $this->assertTrue(app(\App\Services\Social\SocialPlanImport::class)->available());

        $n = app(\App\Services\Social\SocialPlanImport::class)->load();
        $planned = SocialPost::where('status', 'planned')->get();
        $this->assertSame(34, $planned->count()); // 31 prepared + wedding Reel x3
        $this->assertSame($n, SocialPost::count());
        // First prepared post (LinkedIn, 10 Nov 08:00) moves to tomorrow 08:00, UK time.
        $first = $planned->sortBy('scheduled_at')->first();
        $this->assertSame('linkedin', $first->platform);
        $this->assertSame('2026-11-19 08:00', $first->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame(1, $planned->where('title', 'How a business account is set up')->count());
        $this->assertSame(3, $planned->where('title', 'Your wedding day, arriving in style')->count());
        $this->assertSame(4, $planned->where('platform', 'gbp')->count());
        $this->assertStringContainsString('cet-prep-reel-final.mp4', (string) $planned->firstWhere('title', 'Before every job (your own footage)')->media_note);
        $this->assertGreaterThan(5, SocialPost::where('status', 'idea')->where('title', 'like', 'Complaint:%')->count());
        foreach (SocialPost::all() as $p) {
            $this->assertDoesNotMatchRegularExpression('/£\s?\d/', (string) $p->caption);
        }
        // The button now does nothing more.
        $this->actingAs($admin)->post(route('social.starter'));
        $this->assertSame($n, SocialPost::count());
    }

    public function test_breakdown_saves_a_cet_version_to_the_ideas_bank(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['content' => [['text' => json_encode([
            'hook' => 'POV: flight 2 hours late', 'format' => 'POV', 'topic' => 'reliability', 'why' => 'Relatable fear, happy ending.',
            'cet' => ['title' => 'Late flight, driver still there', 'platform' => 'tiktok', 'hook' => 'Your flight is 2 hours late.', 'shot' => 'Arrivals board, then driver waiting.', 'caption' => 'We track every flight.'],
        ])]]])]);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('social.breakdown'), [
            'source_platform' => 'tiktok', 'stats' => '120K views',
            'content' => 'Driver holding a name board, text: POV your flight landed late.',
        ])->assertRedirect();

        $idea = SocialPost::firstOrFail();
        $this->assertSame('idea', $idea->status);
        $this->assertSame('CET version: Late flight, driver still there', $idea->title);
        $this->assertStringContainsString('Why it worked: Relatable fear', $idea->notes);
        $this->assertStringContainsString('120K views', $idea->notes);
    }

    public function test_media_library_attaches_files_marks_ready_and_adds_stories(): void
    {
        app(\App\Services\Social\SocialPlanImport::class)->load();
        SocialPost::create(['platform' => 'instagram', 'status' => 'ready', 'format' => 'Carousel', 'title' => 'Night pickup at Courtyard by Marriott Sheffield (carousel)']);
        $res = app(\App\Services\Social\SocialMediaLibrary::class)->apply();

        $this->assertGreaterThan(30, $res['attached']);
        $this->assertSame(18, $res['stories']); // 6 stories x WhatsApp/Instagram/Facebook
        // Every planned post from the plan now has its files and is Ready.
        $this->assertSame(0, SocialPost::whereIn('status', ['planned', 'ready'])->whereNull('media')->count());
        $carousel = SocialPost::where('title', 'Which car for which trip (7 slides)')->first();
        $this->assertCount(7, $carousel->mediaKeys());
        $this->assertSame('ready', $carousel->status);
        $this->assertStringNotContainsString('Illustrative image', SocialPost::where('title', 'Sheffield winter evening')->first()->caption);
        $this->assertSame(6, SocialPost::where('platform', 'whatsapp')->count());
        // Running again changes nothing.
        $this->assertSame(['attached' => 0, 'stories' => 0], app(\App\Services\Social\SocialMediaLibrary::class)->apply());

        // Admins can view and download; drivers can't; bad index is 404.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('social.media', [$carousel, 1]))->assertOk();
        $this->actingAs($admin)->get(route('social.media', [$carousel, 1]).'?dl=1')->assertOk()->assertDownload();
        $this->actingAs($admin)->get(route('social.media', [$carousel, 99]))->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('social.media', [$carousel, 1]))->assertForbidden();
        $this->actingAs($admin)->get(route('social.posts.edit', $carousel))->assertOk()->assertSee('Photos &amp; video (7)', false);
        $this->actingAs($admin)->get(route('social.planner', ['week' => '2026-11-16']))->assertOk()->assertSee('sc-thumb');
    }

    public function test_media_keys_cannot_escape_the_folders(): void
    {
        $this->assertNull(SocialPost::mediaPath('lib:../../.env'));
        $this->assertNull(SocialPost::mediaPath('lib:p01/../../../.env.jpg'));
        $this->assertNull(SocialPost::mediaPath('file:/etc/passwd'));
        $this->assertNotNull(SocialPost::mediaPath('lib:p01/1.jpg'));
    }

    public function test_office_can_upload_and_remove_their_own_photo(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $post = SocialPost::create(['platform' => 'instagram', 'status' => 'planned', 'title' => 'Own photo']);
        $this->actingAs($admin)->post(route('social.posts.media.upload', $post), [
            'files' => [\Illuminate\Http\UploadedFile::fake()->image('car.jpg', 1080, 1350)],
        ])->assertRedirect();
        $post->refresh();
        $this->assertCount(1, $post->media);
        $this->assertStringStartsWith('up:'.$post->id.'/', $post->media[0]);
        // A non-image is refused.
        $this->actingAs($admin)->post(route('social.posts.media.upload', $post), [
            'files' => [\Illuminate\Http\UploadedFile::fake()->create('x.php', 1, 'text/x-php')],
        ])->assertSessionHasErrors();
        $this->actingAs($admin)->delete(route('social.posts.media.remove', [$post, 0]))->assertRedirect();
        $this->assertSame([], $post->fresh()->media);
    }

    public function test_december_plan_loads_once_with_files_and_house_rules(): void
    {
        $n = app(\App\Services\Social\SocialDecemberPlan::class)->load();
        $this->assertSame(31, $n);
        $this->assertSame(0, app(\App\Services\Social\SocialDecemberPlan::class)->load());
        $posts = SocialPost::all();
        $this->assertTrue($posts->every(fn ($p) => $p->status === 'ready' && count($p->mediaKeys()) > 0));
        $this->assertSame('2026-12-02 19:00', $posts->sortBy('scheduled_at')->first()->scheduled_at->format('Y-m-d H:i'));
        $this->assertTrue($posts->max('scheduled_at')->lt(Carbon::parse('2026-12-16')));
        $this->assertSame(6, $posts->where('format', 'Story')->count());
        foreach ($posts as $p) {
            $this->assertDoesNotMatchRegularExpression('/£\s?\d|owner-run|directors drive/i', (string) $p->caption);
        }
    }
}
