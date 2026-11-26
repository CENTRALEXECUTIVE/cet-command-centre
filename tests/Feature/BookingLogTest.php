<?php

namespace Tests\Feature;

use App\Models\BookingAttempt;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every website booking attempt is recorded silently (26 Nov 2026). */
class BookingLogTest extends TestCase
{
    use RefreshDatabase;

    private function base(array $o = []): array
    {
        return array_merge([
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Rotherham S60 5WG', 'destination_postcode' => 'S60 5WG',
            'pickup_at' => now()->addDays(10)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'), 'passengers' => 1,
            'customer_first_name' => 'Log', 'customer_surname' => 'Tester',
            'customer_phone' => '07700900777', 'customer_email' => 'log@example.com',
        ], $o);
    }

    public function test_booked_refused_and_on_request_are_all_recorded_and_shown_to_admins(): void
    {
        $this->seed(VehicleTypeSeeder::class);

        $this->post(route('widget.book.store'), $this->base())->assertOk();
        $this->assertSame('booked', BookingAttempt::latest('id')->first()->outcome);
        $this->assertNotNull(BookingAttempt::latest('id')->first()->reference);

        $this->post(route('widget.book.store'), $this->base(['customer_phone' => '', 'customer_email' => 'log@example.com', 'pickup_at' => now()->subDay()->format('Y-m-d\TH:i')]));
        $refused = BookingAttempt::latest('id')->first();
        $this->assertSame('refused', $refused->outcome);
        $this->assertNotEmpty($refused->details['errors']);

        $rr = VehicleType::where('slug', 'rolls-royce-ghost')->first();
        $this->postJson(route('widget.prices'), ['pickup' => 'Manchester Airport (MAN)', 'destination' => 'Sheffield S1 2HH', 'pickup_at' => now()->addDays(10)->format('Y-m-d\TH:i')])->assertOk();
        if ($rr) {
            $this->assertSame('price_on_request', BookingAttempt::latest('id')->first()->outcome);
        }

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('booking-log.index', ['show' => 'all']))->assertOk()->assertSee('Log Tester');
        $driver = User::factory()->create();
        $this->actingAs($driver)->get(route('booking-log.index'))->assertForbidden();
    }
}
