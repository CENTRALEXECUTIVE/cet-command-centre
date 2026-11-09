<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The Edit booking form carries the same options as New booking (Nov 2026):
 * vehicles (2 × V Class), meet & greet with name board / meeting point,
 * wheelchair, corporate account + optional cost code / PO, and how it came in.
 */
class EditBookingMoreOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
        $this->admin = User::factory()->admin()->create();
    }

    private function form(array $o = []): array
    {
        return array_merge([
            'customer_name' => 'Nicola Wright', 'customer_phone' => '07387109751', 'customer_email' => 'nicola.wright@example.com',
            'vehicle_type_id' => VehicleType::where('slug', 'v-class')->value('id'),
            'journey_type' => 'one_way',
            'pickup_at' => now()->addDays(4)->setTime(18, 15)->format('Y-m-d\TH:i'),
            'pickup_address' => 'Manchester Airport Terminal 2, Manchester M90 1QX',
            'destination_address' => 'Magna Science Adventure Centre, Magna Way, Rotherham S60 1FD',
            'passengers' => 4, 'suitcases' => 0, 'hand_luggage' => 0,
            'payment_method' => 'card', 'quoted_price' => 120.00,
            'privacy_consent' => '1',
        ], $o);
    }

    private function booking(): Booking
    {
        $this->actingAs($this->admin)->post(route('bookings.store'), $this->form())->assertSessionHasNoErrors();

        return Booking::firstOrFail();
    }

    public function test_edit_page_shows_the_new_options(): void
    {
        CorporateAccount::create(['name' => 'Forged Solutions Group', 'slug' => 'fsg', 'account_code' => 'FSG', 'is_active' => true]);
        $b = $this->booking();

        $this->actingAs($this->admin)->get(route('bookings.edit', $b))->assertOk()
            ->assertSee('name="vehicle_count"', false)
            ->assertSee('name="meet_greet"', false)
            ->assertSee('name="meeting_board"', false)
            ->assertSee('name="wheelchair"', false)
            ->assertSee('name="booking_source"', false)
            ->assertSee('name="corporate_account_id"', false)
            ->assertSee('Forged Solutions Group');
    }

    public function test_editing_saves_vehicles_meet_greet_wheelchair_account_and_source(): void
    {
        $acc = CorporateAccount::create(['name' => 'Forged Solutions Group', 'slug' => 'fsg', 'account_code' => 'FSG', 'is_active' => true]);
        $b = $this->booking();

        $this->actingAs($this->admin)->put(route('bookings.update', $b), $this->form([
            'passengers' => 10, 'vehicle_count' => 2,
            'meet_greet' => '1', 'meeting_board' => 'Forged Solutions', 'meeting_point' => 'Costa, arrivals hall',
            'wheelchair' => '1', 'booking_source' => 'email',
            'corporate_account_id' => $acc->id, 'cost_code' => '', 'corporate_reference' => 'PO-4471',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $b = $b->fresh();
        $this->assertSame(10, $b->passengers);
        $this->assertSame('2 × V Class', $b->vehicleLabel());
        $this->assertTrue((bool) $b->meta['meet_greet']);
        $this->assertSame('Forged Solutions', $b->meta['meeting_board']);
        $this->assertSame('Costa, arrivals hall', $b->meta['meeting_point']);
        $this->assertTrue((bool) $b->meta['wheelchair']);
        $this->assertSame('email', $b->meta['enquiry_source']);
        $this->assertSame($acc->id, $b->corporate_account_id);
        $this->assertNull($b->cost_code, 'cost code stays optional');
        $this->assertSame('PO-4471', $b->corporate_reference);

        // The edit page reloads with everything ticked / filled.
        $this->actingAs($this->admin)->get(route('bookings.edit', $b))->assertOk()
            ->assertSee('value="2"', false)->assertSee('Costa, arrivals hall')->assertSee('PO-4471');
    }

    public function test_unticking_clears_and_back_to_one_vehicle(): void
    {
        $b = $this->booking();
        $this->actingAs($this->admin)->put(route('bookings.update', $b), $this->form([
            'passengers' => 10, 'vehicle_count' => 2, 'meet_greet' => '1', 'wheelchair' => '1',
        ]))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->put(route('bookings.update', $b), $this->form([
            'passengers' => 4, 'vehicle_count' => 1, 'meet_greet' => '0', 'wheelchair' => '0',
        ]))->assertSessionHasNoErrors();

        $b = $b->fresh();
        $this->assertSame('V Class', $b->vehicleLabel());
        $this->assertEmpty($b->meta['meet_greet'] ?? null);
        $this->assertEmpty($b->meta['wheelchair'] ?? null);
    }

    public function test_one_vehicle_still_refuses_ten_people_on_edit(): void
    {
        $b = $this->booking();
        $this->actingAs($this->admin)->put(route('bookings.update', $b), $this->form(['passengers' => 10, 'vehicle_count' => 1]))
            ->assertSessionHasErrors('passengers');
    }

    public function test_an_edit_without_the_new_fields_leaves_them_alone(): void
    {
        $b = $this->booking();
        $b->forceFill(['meta' => array_merge($b->meta ?? [], ['wheelchair' => true, 'vehicle_count' => 2])])->save();

        $this->actingAs($this->admin)->put(route('bookings.update', $b), $this->form(['passengers' => 9]))->assertSessionHasNoErrors();

        $b = $b->fresh();
        $this->assertTrue((bool) $b->meta['wheelchair']);
        $this->assertSame(2, $b->vehicleCount());
    }
}
