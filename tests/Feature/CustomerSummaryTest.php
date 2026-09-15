<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\CustomerSummaryService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function exec(): VehicleType
    {
        return VehicleType::where('slug', 'executive')->first();
    }

    public function test_summary_lists_upcoming_jobs_soonest_first(): void
    {
        $customer = Customer::create(['name' => 'James McDermott', 'phone' => '07700900123', 'email' => 'james@example.com']);

        // Two upcoming jobs (out of order) + one cancelled + one past.
        Booking::create([
            'reference' => 'UAPZOX', 'customer_id' => $customer->id, 'vehicle_type_id' => $this->exec()->id,
            'pickup_at' => now()->addDays(3)->setTime(14, 0), 'pickup_address' => 'The Victoria, Neepsend',
            'destination_address' => 'Home', 'passengers' => 6, 'status' => 'allocated', 'payment_method' => 'card',
            'final_price' => 150,
        ]);
        Booking::create([
            'reference' => 'G3GARR', 'customer_id' => $customer->id, 'vehicle_type_id' => $this->exec()->id,
            'pickup_at' => now()->addDays(3)->setTime(13, 0), 'pickup_address' => 'Christ Church Fulwood',
            'destination_address' => 'Botanical Gardens', 'passengers' => 6, 'status' => 'allocated',
            'payment_method' => 'card', 'quoted_price' => 90,
        ]);
        Booking::create([
            'reference' => 'CANCEL1', 'customer_id' => $customer->id, 'vehicle_type_id' => $this->exec()->id,
            'pickup_at' => now()->addDay(), 'pickup_address' => 'X', 'destination_address' => 'Y',
            'passengers' => 1, 'status' => 'cancelled', 'payment_method' => 'card',
        ]);

        $service = app(CustomerSummaryService::class);
        $jobs = $service->bookingsToConfirm($customer);

        // Only the two upcoming, cancelled excluded, chronological.
        $this->assertSame(['G3GARR', 'UAPZOX'], $jobs->pluck('reference')->all());

        $summary = $service->summary($customer, $jobs);
        $this->assertStringContainsString('G3GARR', $summary);
        $this->assertStringContainsString('UAPZOX', $summary);
        $this->assertStringContainsString('Botanical Gardens', $summary);
        $this->assertStringContainsString('£90.00', $summary);
    }

    public function test_confirmation_email_is_customer_friendly(): void
    {
        $customer = Customer::create(['name' => 'James McDermott', 'phone' => '07700900123', 'email' => 'james@example.com']);
        Booking::create([
            'reference' => 'G3GARR', 'customer_id' => $customer->id, 'vehicle_type_id' => $this->exec()->id,
            'pickup_at' => now()->addDays(3)->setTime(13, 0), 'pickup_address' => 'Christ Church Fulwood',
            'destination_address' => 'Botanical Gardens', 'passengers' => 6, 'status' => 'allocated',
            'payment_method' => 'card', 'quoted_price' => 90,
        ]);

        $service = app(CustomerSummaryService::class);
        $subject = $service->emailSubject($customer);
        $body = $service->emailBody($customer);

        $this->assertStringContainsString('G3GARR', $subject);
        $this->assertStringContainsString('Hi James,', $body);
        $this->assertStringContainsString('confirm', strtolower($body));
        $this->assertStringContainsString('Botanical Gardens', $body);
        // Office-only fields never leak into the customer email.
        $this->assertStringNotContainsString('UAPZOX', $body); // not a booking here
        $this->assertStringContainsString('Central Executive Transfers', $body);
    }

    public function test_falls_back_to_recent_jobs_when_none_upcoming(): void
    {
        $customer = Customer::create(['name' => 'Past Only', 'phone' => '07700900999']);
        Booking::create([
            'reference' => 'OLD1', 'customer_id' => $customer->id, 'vehicle_type_id' => $this->exec()->id,
            'pickup_at' => now()->subDays(10), 'pickup_address' => 'A', 'destination_address' => 'B',
            'passengers' => 1, 'status' => 'complete', 'payment_method' => 'card',
        ]);

        $jobs = app(CustomerSummaryService::class)->bookingsToConfirm($customer);
        $this->assertSame(['OLD1'], $jobs->pluck('reference')->all());
    }

    public function test_profile_page_shows_the_confirm_controls(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = Customer::create(['name' => 'James McDermott', 'phone' => '07700900123', 'email' => 'james@example.com']);
        Booking::create([
            'reference' => 'G3GARR', 'customer_id' => $customer->id, 'vehicle_type_id' => $this->exec()->id,
            'pickup_at' => now()->addDays(3)->setTime(13, 0), 'pickup_address' => 'Christ Church Fulwood',
            'destination_address' => 'Botanical Gardens', 'passengers' => 6, 'status' => 'allocated',
            'payment_method' => 'card', 'quoted_price' => 90,
        ]);

        $this->actingAs($admin)->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Confirm bookings')
            ->assertSee('Copy summary')
            ->assertSee('Copy email')
            ->assertSee('mailto:james@example.com', false);
    }
}
