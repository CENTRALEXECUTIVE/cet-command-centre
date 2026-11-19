<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Growth\GrowthDashboard;
use App\Services\Social\MetaInsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaInsightsSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));
    }

    private function connect(): void
    {
        Setting::set(MetaInsightsService::PAGE_ID, '1234567890');
        Setting::set(MetaInsightsService::TOKEN, 'page-token');
    }

    private function fakeGraph(): void
    {
        Http::fake([
            'graph.facebook.com/*/1234567890?*' => Http::response(['name' => 'Central Executive Transfers', 'followers_count' => 120,
                'instagram_business_account' => ['id' => '1789', 'username' => 'centralexecutivetransfers', 'followers_count' => 85]]),
            'graph.facebook.com/*/1789/media*' => Http::response(['data' => [
                ['id' => 'm1', 'caption' => 'Who is driving you to the airport?', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS',
                    'permalink' => 'https://www.instagram.com/reel/abc/', 'timestamp' => '2026-11-19T19:31:00+0000', 'like_count' => 40, 'comments_count' => 5],
                ['id' => 'm2', 'caption' => "Posted straight in the app\nmore", 'media_type' => 'IMAGE', 'media_product_type' => 'FEED',
                    'permalink' => 'https://www.instagram.com/p/xyz/', 'timestamp' => '2026-11-18T12:00:00+0000', 'like_count' => 3, 'comments_count' => 0],
            ]]),
            'graph.facebook.com/*/m1/insights*' => Http::response(['data' => [
                ['name' => 'views', 'values' => [['value' => 1500]]], ['name' => 'saved', 'values' => [['value' => 12]]], ['name' => 'shares', 'values' => [['value' => 7]]],
            ]]),
            'graph.facebook.com/*/m2/insights*' => Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 90]]]]]),
            'graph.facebook.com/*/1234567890/posts*' => Http::response(['data' => []]),
        ]);
    }

    public function test_not_connected_is_a_silent_no_op(): void
    {
        Http::fake();
        $this->artisan('cet:social-sync')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_sync_marks_the_planned_post_posted_adds_unplanned_ones_and_logs_followers(): void
    {
        $this->connect();
        $this->fakeGraph();
        $planned = SocialPost::create(['platform' => 'instagram', 'status' => 'ready', 'title' => 'Before every job',
            'scheduled_at' => Carbon::parse('2026-11-19 19:30', 'Europe/London')]);

        $r = app(MetaInsightsService::class)->sync();
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['instagram']);
        $this->assertSame(1, $r['created']);

        $planned->refresh();
        $this->assertSame('posted', $planned->status);
        $this->assertSame(1500, $planned->views);
        $this->assertSame(40, $planned->likes);
        $this->assertSame(12, $planned->saves);
        $this->assertSame('https://www.instagram.com/reel/abc/', $planned->url);

        $new = SocialPost::where('url', 'https://www.instagram.com/p/xyz/')->firstOrFail();
        $this->assertSame('Posted straight in the app', $new->title);
        $this->assertSame(90, $new->views); // falls back to reach

        $week = GrowthDashboard::log()['2026-11-16'];
        $this->assertSame(85, $week['instagram']);
        $this->assertSame(120, $week['facebook']);

        // A second run updates, never duplicates.
        app(MetaInsightsService::class)->sync();
        $this->assertSame(2, SocialPost::count());
        // Read-only: nothing but GETs ever goes to Meta.
        Http::assertNotSent(fn ($req) => $req->method() !== 'GET');
    }

    public function test_only_a_super_admin_can_save_the_connection_and_the_token_is_never_shown(): void
    {
        $this->fakeGraph();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('social.meta.save'), ['page_id' => '1234567890', 'token' => 'x'])->assertForbidden();

        $super = User::factory()->admin()->create(['is_super_admin' => true]);
        $this->actingAs($super)->post(route('social.meta.save'), ['page_id' => '1234567890', 'token' => 'page-token'])
            ->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains($s, 'Connected to Central Executive Transfers'));
        $this->assertSame('page-token', Setting::get(MetaInsightsService::TOKEN));
        $this->actingAs($super)->get(route('social.index'))->assertOk()->assertDontSee('page-token')->assertSee('connected');
    }
}
