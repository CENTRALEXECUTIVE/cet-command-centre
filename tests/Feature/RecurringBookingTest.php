<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\RecurringBooking;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Bookings\RecurringBookingGenerator;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Standing / recurring bookings — a template generates real bookings for upcoming
 * occurrences, without duplicating, and admins can manage them.
 */
class RecurringBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function template(array $overrides = []): RecurringBooking
    {
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();
        $customer = Customer::create(['name' => 'Regular Rita', 'phone' => '07700900001']);

        return RecurringBooking::create(array_merge([
            'customer_id' => $customer->id,
            'vehicle_type_id' => $exec->id,
            'frequency' => 'daily',
            'pickup_time' => '09:00',
            'pickup_address' => 'Sheffield S1 2HH',
            'destination_address' => 'Manchester Airport',
            'passengers' => 1,
            'payment_method' => 'cash',
            'lead_days' => 3,
            'is_active' => true,
        ], $overrides));
    }

    public function test_a_daily_template_generates_upcoming_bookings(): void
    {
        $this->template(['lead_days' => 3]);

        $made = app(RecurringBookingGenerator::class)->generateDue();

        // Today's 09:00 may be past in CI, so expect at least the next 3 days.
        $this->assertGreaterThanOrEqual(3, $made);
        $this->assertGreaterThanOrEqual(3, Booking::where('source', 'recurring')->count());
    }

    public function test_generation_is_idempotent(): void
    {
        $this->template(['lead_days' => 2]);
        app(RecurringBookingGenerator::class)->generateDue();
        $first = Booking::where('source', 'recurring')->count();

        app(RecurringBookingGenerator::class)->generateDue(); // run again
        $this->assertSame($first, Booking::where('source', 'recurring')->count());
    }

    public function test_a_weekly_template_only_generates_on_its_weekday(): void
    {
        // Monday-only, 7-day window → exactly one occurrence in the next week.
        $this->template(['frequency' => 'weekly', 'weekday' => Carbon::MONDAY, 'lead_days' => 7, 'pickup_time' => '23:30']);

        app(RecurringBookingGenerator::class)->generateDue();

        $count = Booking::where('source', 'recurring')->count();
        $this->assertSame(1, $count);
        $this->assertSame(Carbon::MONDAY, Booking::where('source', 'recurring')->first()->pickup_at->dayOfWeek);
    }

    public function test_admin_can_create_a_standing_booking(): void
    {
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug', 'executive')->firstOrFail();

        $this->actingAs($admin)->post(route('recurring.store'), [
            'customer_name' => 'Weekly Will', 'customer_phone' => '07700900222',
            'vehicle_type_id' => $exec->id, 'frequency' => 'weekly', 'weekday' => 1,
            'pickup_time' => '08:00', 'pickup_address' => 'Barnsley S70 1AA',
            'destination_address' => 'Leeds Bradford Airport', 'passengers' => 2,
            'payment_method' => 'card', 'lead_days' => 5,
        ])->assertRedirect();

        $this->assertDatabaseHas('recurring_bookings', ['frequency' => 'weekly', 'weekday' => 1]);
    }

    public function test_a_driver_cannot_manage_standing_bookings(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('recurring.index'))->assertForbidden();
    }
}
