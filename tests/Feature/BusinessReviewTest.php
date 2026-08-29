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

    public function test_an_untagged_booking_is_grouped_by_its_booker(): void
    {
        // No corporate_account_id on the booking or customer — the only link is
        // that LB Foster's contact, Abi Atkin, booked it.
        $account = CorporateAccount::create(['name' => 'LB Foster', 'slug' => 'lb-foster', 'account_code' => 'LBFOSTER', 'is_active' => true]);
        $account->contacts()->create(['name' => 'Abi Atkin', 'is_primary' => true]);

        $traveller = Customer::factory()->create(['name' => 'TJ Curran']);
        $this->ranJob(['customer_id' => $traveller->id, 'final_price' => 120, 'meta' => ['booked_by' => 'Abi Atkin']]);

        $rows = app(\App\Services\Reporting\ReportService::class)->topEntities(now()->subMonth(), now());

        $lb = $rows->firstWhere('name', 'LB Foster');
        $this->assertNotNull($lb);
        $this->assertSame('business', $lb['type']);
        $this->assertSame(1, $lb['jobs']);
        // The traveller doesn't appear as their own row.
        $this->assertNull($rows->firstWhere('name', 'TJ Curran'));
    }

    public function test_an_untagged_booking_is_grouped_by_email_domain(): void
    {
        $account = CorporateAccount::create(['name' => 'JELD-WEN', 'slug' => 'jeld-wen', 'account_code' => 'JELDWEN', 'is_active' => true]);
        $traveller = Customer::factory()->create(['name' => 'Christian Michels', 'email' => 'christian.michels@jeldwen.com']);
        $this->ranJob(['customer_id' => $traveller->id, 'final_price' => 175]);

        $rows = app(\App\Services\Reporting\ReportService::class)->topEntities(now()->subMonth(), now());

        $jw = $rows->firstWhere('name', 'JELD-WEN');
        $this->assertNotNull($jw);
        $this->assertSame(1, $jw['jobs']);
        $this->assertNull($rows->firstWhere('name', 'Christian Michels'));
    }

    public function test_the_breakdown_includes_untagged_bookings_matched_by_booker(): void
    {
        $account = CorporateAccount::create(['name' => 'JELD-WEN', 'slug' => 'jeld-wen', 'account_code' => 'JELDWEN', 'is_active' => true]);
        $account->contacts()->create(['name' => 'Jackie Donoghue']);
        $traveller = Customer::factory()->create(['name' => 'Christian Michels']);
        Booking::factory()->count(2)->create([
            'customer_id' => $traveller->id, 'final_price' => 90,
            'pickup_at' => now()->subDays(2), 'meta' => ['booked_by' => 'Jackie Donoghue'],
        ]);

        $rows = app(\App\Services\Reporting\ReportService::class)->businessCustomers($account->id);

        $christian = $rows->firstWhere('name', 'Christian Michels');
        $this->assertNotNull($christian);
        $this->assertSame(2, $christian['bookings']);
        $this->assertTrue($christian['repeat']);
    }

    public function test_a_private_customer_is_not_dragged_into_a_business(): void
    {
        CorporateAccount::create(['name' => 'JELD-WEN', 'slug' => 'jeld-wen', 'account_code' => 'JELDWEN', 'is_active' => true]);
        $priv = Customer::factory()->create(['name' => 'Jane Private', 'email' => 'jane@gmail.com']);
        $this->ranJob(['customer_id' => $priv->id, 'final_price' => 300]);

        $rows = app(\App\Services\Reporting\ReportService::class)->topEntities(now()->subMonth(), now());

        $jane = $rows->firstWhere('name', 'Jane Private');
        $this->assertNotNull($jane);
        $this->assertSame('customer', $jane['type']);
    }

    public function test_the_review_page_counts_and_lists_repeat_customers(): void
    {
        $admin = User::factory()->admin()->create();

        // A repeat private customer (2 trips) and a one-off customer (1 trip).
        $rita = Customer::factory()->create(['name' => 'Repeat Rita']);
        $this->ranJob(['customer_id' => $rita->id, 'final_price' => 100]);
        $this->ranJob(['customer_id' => $rita->id, 'final_price' => 120]);
        $once = Customer::factory()->create(['name' => 'One-Off Ollie']);
        $this->ranJob(['customer_id' => $once->id, 'final_price' => 90]);

        $res = $this->actingAs($admin)->get(route('review.index', ['preset' => 'all']))->assertOk();

        // One repeat customer, and the page names them plus a 'See all' toggle.
        $this->assertCount(1, $res->viewData('repeatCustomers'));
        $res->assertSee('Repeat customer', false)
            ->assertSee('See all 1 repeat customer')
            ->assertSee('Repeat Rita');
    }

    public function test_non_admins_cannot_view_the_business_breakdown(): void
    {
        $account = CorporateAccount::create(['name' => 'JELD-WEN', 'slug' => 'jw', 'account_code' => 'JW', 'is_active' => true]);
        $driver = User::factory()->driver()->create();
        $this->actingAs($driver)->get(route('reports.business', $account))->assertForbidden();
    }
}
