<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_ranks_businesses_by_bookings_and_counts_repeat_customers(): void
    {
        $admin = User::factory()->admin()->create();

        $jeldwen = CorporateAccount::create(['name' => 'JELD-WEN', 'slug' => 'jw', 'account_code' => 'JW', 'is_active' => true]);
        $lbFoster = CorporateAccount::create(['name' => 'LB Foster', 'slug' => 'lb', 'account_code' => 'LB', 'is_active' => true]);

        // JELD-WEN: one customer with 3 trips (a repeat), one with 1 → 4 bookings.
        $alice = Customer::factory()->create(['name' => 'Alice', 'corporate_account_id' => $jeldwen->id]);
        $bob = Customer::factory()->create(['name' => 'Bob', 'corporate_account_id' => $jeldwen->id]);
        Booking::factory()->count(3)->create(['corporate_account_id' => $jeldwen->id, 'customer_id' => $alice->id, 'final_price' => 100]);
        Booking::factory()->create(['corporate_account_id' => $jeldwen->id, 'customer_id' => $bob->id, 'final_price' => 50]);

        // LB Foster: 1 booking.
        $carol = Customer::factory()->create(['name' => 'Carol', 'corporate_account_id' => $lbFoster->id]);
        Booking::factory()->create(['corporate_account_id' => $lbFoster->id, 'customer_id' => $carol->id, 'final_price' => 80]);

        // A cancelled JELD-WEN booking must NOT count.
        Booking::factory()->create(['corporate_account_id' => $jeldwen->id, 'customer_id' => $bob->id, 'status' => BookingStatus::Cancelled, 'final_price' => 999]);

        $data = app(\App\Services\Reporting\ReportService::class)->businessBreakdown();

        // Ranked: JELD-WEN (4) first, LB Foster (1) second.
        $this->assertSame('JELD-WEN', $data[0]['name']);
        $this->assertSame(4, $data[0]['bookings']);
        $this->assertSame(2, $data[0]['customers']);
        $this->assertSame(1, $data[0]['repeat_customers']);   // only Alice booked 2+
        $this->assertSame('Alice', $data[0]['top_customer']);
        $this->assertSame(3, $data[0]['top_customer_count']);
        $this->assertSame(350.0, $data[0]['revenue']);        // cancelled £999 excluded

        $this->assertSame('LB Foster', $data[1]['name']);
        $this->assertSame(1, $data[1]['bookings']);
    }

    public function test_a_bookings_business_falls_back_to_the_customers_account(): void
    {
        // Booking has no corporate_account_id, but its customer belongs to one.
        $account = CorporateAccount::create(['name' => 'Forged Solutions', 'slug' => 'fs', 'account_code' => 'FS', 'is_active' => true]);
        $cust = Customer::factory()->create(['corporate_account_id' => $account->id]);
        Booking::factory()->create(['corporate_account_id' => null, 'customer_id' => $cust->id, 'final_price' => 120]);

        $data = app(\App\Services\Reporting\ReportService::class)->businessBreakdown();

        $this->assertSame('Forged Solutions', $data[0]['name']);
        $this->assertSame(1, $data[0]['bookings']);
    }

    public function test_the_business_review_pages_render_for_an_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $account = CorporateAccount::create(['name' => 'JELD-WEN', 'slug' => 'jw', 'account_code' => 'JW', 'is_active' => true]);
        $cust = Customer::factory()->create(['name' => 'Dave', 'corporate_account_id' => $account->id]);
        Booking::factory()->count(2)->create(['corporate_account_id' => $account->id, 'customer_id' => $cust->id, 'final_price' => 90]);

        $this->actingAs($admin)->get(route('reports.businesses'))
            ->assertOk()
            ->assertSee('Business review')
            ->assertSee('JELD-WEN')
            ->assertSee('View customers →');

        $this->actingAs($admin)->get(route('reports.business', $account))
            ->assertOk()
            ->assertSee('JELD-WEN')
            ->assertSee('Dave')
            ->assertSee('repeat');
    }

    public function test_non_admins_cannot_view_the_business_review(): void
    {
        $driver = User::factory()->driver()->create();
        $this->actingAs($driver)->get(route('reports.businesses'))->assertForbidden();
    }
}
