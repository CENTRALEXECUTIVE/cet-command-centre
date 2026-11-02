<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * THE OFFICE IS THE BOSS: a name the office types on Edit booking (e.g. the passenger
 * whose name goes on the meet & greet board) must stick after Save — on the booking
 * page, the edit form, the driver sheet and the emails — even for a repeat customer
 * whose record other bookings share, and even when only the name (not the phone)
 * changes. It used to snap back to the previous name.
 */
class EditedNameSticksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
        Http::fake();
    }

    private function save(Booking $b, string $name, string $phone): void
    {
        $exec = VehicleType::where('slug', 'executive')->first();
        $this->actingAs(User::factory()->admin()->create())->put(route('bookings.update', $b), [
            'customer_name' => $name, 'customer_phone' => $phone, 'customer_email' => 'debbie@example.com',
            'journey_type' => 'one_way',
            'pickup_address' => $b->pickup_address, 'destination_address' => $b->destination_address,
            'pickup_at' => $b->pickup_at->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $exec->id, 'passengers' => 1, 'payment_method' => 'card',
            'quoted_price' => 180,
        ])->assertRedirect();
    }

    private function booking(Customer $c, array $meta = []): Booking
    {
        return Booking::factory()->create([
            'customer_id' => $c->id, 'vehicle_type_id' => VehicleType::where('slug', 'executive')->first()->id,
            'pickup_address' => 'Kidderminster DY11 7RA', 'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDays(5), 'payment_method' => 'card', 'quoted_price' => 180,
            'meta' => $meta,
        ]);
    }

    private function assertShows(Booking $b, string $name): void
    {
        $f = $b->fresh();
        $this->assertSame($name, $f->displayName(), 'booking/driver name');
        $this->assertSame($name, $f->displayCustomerName(), 'edit form name');
        $this->actingAs(User::factory()->admin()->create())->get(route('bookings.edit', $b))
            ->assertOk()->assertSee('value="'.$name.'"', false);
    }

    public function test_renaming_a_repeat_customers_booking_sticks_when_only_the_name_changes(): void
    {
        $c = Customer::create(['name' => 'Debbie Corlett', 'phone' => '07624460475', 'email' => 'debbie@example.com']);
        $this->booking($c); // another booking shares this customer
        $b = $this->booking($c, ['lead_name' => 'Debbie Corlett']);

        $this->save($b, 'Mr Dane Limosnero', '07624460475');

        $this->assertShows($b, 'Mr Dane Limosnero');
        // The shared customer record (their other bookings) is NOT renamed.
        $this->assertSame('Debbie Corlett', $c->fresh()->name);
    }

    public function test_renaming_a_one_off_booking_sticks(): void
    {
        $c = Customer::create(['name' => 'Debbie Corlett', 'phone' => '07624460475']);
        $b = $this->booking($c, ['lead_name' => 'Debbie Corlett']);

        $this->save($b, 'Mr Dane Limosnero', '07624460475');

        $this->assertShows($b, 'Mr Dane Limosnero');
    }

    public function test_the_edited_name_survives_a_second_save(): void
    {
        $c = Customer::create(['name' => 'Debbie Corlett', 'phone' => '07624460475']);
        $this->booking($c);
        $b = $this->booking($c, ['lead_name' => 'Debbie Corlett']);

        $this->save($b, 'Mr Dane Limosnero', '07624460475');
        $this->save($b->fresh(), 'Mr Dane Limosnero', '07624460475'); // e.g. saving another change later

        $this->assertShows($b, 'Mr Dane Limosnero');
    }
}
