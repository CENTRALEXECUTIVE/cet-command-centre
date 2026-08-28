<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BusinessReviewTest extends TestCase
{
    use RefreshDatabase;

    private function ranJob(array $attrs): Booking
    {
        return Booking::factory()->create(array_merge([
            'pickup_at' => now()->subDays(2), // has run
        ], $attrs));
    }

    public function test_top_entities_rolls_corporate_customers_up_under_their_business(): void
    {
        $jeldwen = CorporateAccount::create(['name' => 'JELD-WEN', 'slug' => 'jw', 'account_code' => 'JW', 'is_active' => true]);
        $tj = Customer::factory()->create(['name' => 'TJ Curran', 'corporate_account_id' => $jeldwen->id]);
        $bill = Customer::factory()->create(['name' => 'Bill', 'corporate_account_id' => $jeldwen->id]);
        $this->ranJob(['customer_id' => $tj->id, 'corporate_account_id' => $jeldwen->id, 'final_price' => 200]);
        $this->ranJob(['customer_id' => $bill->id, 'corporate_account_id' => $jeldwen->id, 'final_price' => 150]);

        // A private customer stays separate.
        $priv = Customer::factory()->create(['name' => 'Jane Private']);
        $this->ranJob(['customer_id' => $priv->id, 'final_price' => 300]);

        $rows = app(\App\Services\Reporting\ReportService::class)
            ->topEntities(now()->subMonth(), now());

        $jw = $rows->firstWhere('name', 'JELD-WEN');
        $this->assertNotNull($jw);
        $this->assertSame('business', $jw['type']);
        $this->assertSame(2, $jw['jobs']);      // TJ + Bill under one line
        $this->assertSame(2, $jw['customers']);
        $this->assertSame(350.0, $jw['revenue']);

        // No individual corporate names appear as their own row.
        $this->assertNull($rows->firstWhere('name', 'TJ Curran'));
        $this->assertNull($rows->firstWhere('name', 'Bill'));

        // The private customer is its own row.
        $jane = $rows->firstWhere('name', 'Jane Private');
        $this->assertSame('customer', $jane['type']);
    }

    public function test_the_review_page_shows_businesses_grouped_and_links_to_the_breakdown(): void
    {
        $admin = User::factory()->admin()->create();
        $jeldwen = CorporateAccount::create(['name' => 'JELD-WEN', 'slug' => 'jw', 'account_code' => 'JW', 'is_active' => true]);
        $tj = Customer::factory()->create(['name' => 'TJ Curran', 'corporate_account_id' => $jeldwen->id]);
        $this->ranJob(['customer_id' => $tj->id, 'corporate_account_id' => $jeldwen->id, 'final_price' => 200]);

        $this->actingAs($admin)->get(route('review.index', ['preset' => 'all']))
            ->assertOk()
            ->assertSee('JELD-WEN')
            ->assertSee(route('reports.business', $jeldwen->id), false);
    }

    public function test_the_business_breakdown_shows_each_customers_rebooking_count(): void
    {
        $admin = User::factory()->admin()->create();
        $account = CorporateAccount::create(['name' => 'LB Foster', 'slug' => 'lb', 'account_code' => 'LB', 'is_active' => true]);
        $tj = Customer::factory()->create(['name' => 'TJ Curran', 'corporate_account_id' => $account->id]);
        // TJ has re-booked 3 times.
        Booking::factory()->count(3)->create(['customer_id' => $tj->id, 'corporate_account_id' => $account->id, 'final_price' => 90]);

        $this->actingAs($admin)->get(route('reports.business', $account))
            ->assertOk()
            ->assertSee('LB Foster')
            ->assertSee('TJ Curran')
            ->assertSee('repeat');
    }

    public function test_non_admins_cannot_view_the_business_breakdown(): void
    {
        $account = CorporateAccount::create(['name' => 'JELD-WEN', 'slug' => 'jw', 'account_code' => 'JW', 'is_active' => true]);
        $driver = User::factory()->driver()->create();
        $this->actingAs($driver)->get(route('reports.business', $account))->assertForbidden();
    }
}
