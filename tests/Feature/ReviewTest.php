<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class]);
        config(['services.anthropic.key' => null]); // use the built-in summary, no API call
    }

    public function test_admin_sees_review_with_revenue_vehicle_and_recommendations(): void
    {
        // Pin to mid-month so the "this month" default window is deterministic
        // (a booking 2 days ago can't fall into the previous month).
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-07-15 12:00:00'));

        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->first();
        $customer = Customer::create(['name' => 'Acme Travel', 'phone' => '07700900111']);

        Booking::create([
            'reference' => Booking::generateReference(), 'customer_id' => $customer->id,
            'vehicle_type_id' => $exec->id, 'pickup_at' => now()->subDays(2),
            'pickup_address' => 'Sheffield', 'destination_address' => 'Manchester Airport (MAN)',
            'status' => BookingStatus::Complete->value, 'final_price' => 180, 'payment_method' => 'card',
        ]);

        $this->actingAs($admin)->get(route('review.index'))
            ->assertOk()
            ->assertSee('Business Review')
            ->assertSee('Review &amp; recommendations', false)
            ->assertSee('Acme Travel')          // top customer
            ->assertSee('Executive')            // vehicle mix
            ->assertSee('Next steps');
    }

    public function test_all_time_preset_includes_old_bookings_that_last_30_days_excludes(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-07-15 12:00:00'));
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->first();
        $customer = Customer::create(['name' => 'Old Client Ltd', 'phone' => '07700900222']);

        Booking::create([
            'reference' => Booking::generateReference(), 'customer_id' => $customer->id,
            'vehicle_type_id' => $exec->id, 'pickup_at' => now()->subMonths(8),
            'pickup_address' => 'Sheffield', 'destination_address' => 'Manchester Airport (MAN)',
            'status' => BookingStatus::Complete->value, 'final_price' => 250, 'payment_method' => 'card',
        ]);

        // Default (last 30 days) → the 8-month-old job is out of range.
        $this->actingAs($admin)->get(route('review.index'))
            ->assertOk()->assertDontSee('Old Client Ltd');

        // "All time" preset → it's included.
        $this->actingAs($admin)->get(route('review.index', ['preset' => 'all']))
            ->assertOk()->assertSee('Old Client Ltd');
    }

    public function test_review_is_admin_only(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('review.index'))->assertForbidden();
    }

    public function test_fix_missing_prices_recovers_fares_from_meta(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = \App\Models\VehicleType::where('slug', 'executive')->first();

        $customer = \App\Models\Customer::create(['name' => 'Priced Cust', 'phone' => '07700900099']);
        // A booking that ran with no price column set but a fare in meta.
        $booking = \App\Models\Booking::create([
            'reference' => \App\Models\Booking::generateReference(),
            'customer_id' => $customer->id,
            'vehicle_type_id' => $exec->id, 'pickup_at' => now()->subDays(2),
            'pickup_address' => 'A', 'destination_address' => 'B', 'passengers' => 1,
            'status' => 'complete', 'payment_method' => 'cash',
            'meta' => ['total_amount' => 120],
        ]);

        $this->actingAs($admin)->post(route('review.backfill-prices'))->assertRedirect();

        $this->assertEquals('120.00', (string) $booking->fresh()->quoted_price);
    }

    public function test_fix_missing_prices_is_admin_only(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->post(route('review.backfill-prices'))->assertForbidden();
    }

    public function test_reserved_includes_upcoming_jobs_but_earned_does_not(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = \App\Models\VehicleType::where('slug', 'executive')->first();
        $customer = \App\Models\Customer::create(['name' => 'Res Cust', 'phone' => '07700900098']);

        $make = fn ($when, $price) => \App\Models\Booking::create([
            'reference' => \App\Models\Booking::generateReference(), 'customer_id' => $customer->id,
            'vehicle_type_id' => $exec->id, 'pickup_at' => $when,
            'pickup_address' => 'A', 'destination_address' => 'B', 'passengers' => 1,
            'status' => 'pending', 'payment_method' => 'card', 'quoted_price' => $price,
        ]);
        $make(now()->subDays(2), 100);  // run → earned + reserved
        $make(now()->addDays(2), 150);  // upcoming → reserved only

        $res = $this->actingAs($admin)->get(route('review.index', ['preset' => 'this_year']))->assertOk();

        // Earned counts only the job that has run; reserved counts both.
        $this->assertEquals(100.0, $res->viewData('comparison')['current']['revenue']);
        $this->assertEquals(250.0, $res->viewData('reserved')['revenue']);
        $this->assertEquals(2, $res->viewData('reserved')['jobs']);
    }

    public function test_admin_can_manually_request_a_review_overriding_the_once_rule(): void
    {
        $admin = User::factory()->admin()->create();
        $vt = VehicleType::where('slug', 'executive')->first();
        $cust = Customer::create(['name' => 'Repeat Rita', 'phone' => '07700900555']);

        // An earlier booking already got its review request → the once-per-customer
        // rule would normally skip a new one.
        $old = Booking::create([
            'reference' => Booking::generateReference(), 'customer_id' => $cust->id,
            'vehicle_type_id' => $vt->id, 'pickup_at' => now()->subMonth(),
            'pickup_address' => 'A', 'destination_address' => 'B', 'passengers' => 1,
            'status' => 'complete', 'payment_method' => 'card',
        ]);
        \App\Models\Message::create([
            'booking_id' => $old->id, 'customer_id' => $cust->id, 'channel' => 'whatsapp',
            'direction' => 'outbound', 'type' => 'review_request', 'to_address' => '07700900555',
            'body' => 'review', 'status' => 'sent',
        ]);

        $today = Booking::create([
            'reference' => Booking::generateReference(), 'customer_id' => $cust->id,
            'vehicle_type_id' => $vt->id, 'pickup_at' => now()->subHour(),
            'pickup_address' => 'A', 'destination_address' => 'B', 'passengers' => 1,
            'status' => 'complete', 'payment_method' => 'card',
        ]);

        $this->actingAs($admin)->post(route('bookings.request-review', $today))->assertRedirect();

        $this->assertDatabaseHas('messages', [
            'booking_id' => $today->id, 'type' => 'review_request', 'status' => 'queued',
        ]);
    }
}
