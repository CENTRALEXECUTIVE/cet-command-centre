<?php

namespace Tests\Feature;

use App\Models\Keyword;
use App\Models\SeoPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_and_delete_a_keyword(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('marketing.keywords.store'), [
            'keyword' => 'sheffield airport transfers', 'match_type' => 'phrase',
            'campaign' => 'Airport Transfers', 'status' => 'enabled', 'max_cpc' => 1.80,
        ])->assertRedirect();

        $keyword = Keyword::first();
        $this->assertEquals('sheffield airport transfers', $keyword->keyword);

        $this->actingAs($admin)->delete(route('marketing.keywords.destroy', $keyword))->assertRedirect();
        $this->assertEquals(0, Keyword::count());
    }

    public function test_keyword_ctr_and_cpa_calculations(): void
    {
        $k = Keyword::create([
            'keyword' => 'test', 'match_type' => 'exact', 'clicks' => 50,
            'impressions' => 1000, 'conversions' => 5, 'cost' => 100,
        ]);

        $this->assertEquals(5.0, $k->ctr);  // 50/1000
        $this->assertEquals(20.0, $k->cpa); // 100/5
    }

    public function test_admin_can_save_an_seo_page(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('marketing.seo.store'), [
            'path' => '/airport-transfers-sheffield',
            'title' => 'Sheffield Airport Transfers',
            'target_keyword' => 'sheffield airport transfers',
            'current_rank' => 4, 'monthly_searches' => 1900,
        ])->assertRedirect();

        $this->assertDatabaseHas('seo_pages', ['path' => '/airport-transfers-sheffield', 'current_rank' => 4]);

        // Re-saving the same path updates rather than duplicating.
        $this->actingAs($admin)->post(route('marketing.seo.store'), [
            'path' => '/airport-transfers-sheffield', 'title' => 'Updated', 'current_rank' => 2,
        ]);
        $this->assertEquals(1, SeoPage::where('path', '/airport-transfers-sheffield')->count());
        $this->assertEquals(2, SeoPage::first()->current_rank);
    }

    public function test_marketing_pages_load_for_admin_only(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('marketing.keywords'))->assertOk()->assertSee('Keywords');
        $this->actingAs($admin)->get(route('marketing.seo'))->assertOk()->assertSee('SEO');

        $this->actingAs(User::factory()->driver()->create())
            ->get(route('marketing.keywords'))->assertForbidden();
    }
}
