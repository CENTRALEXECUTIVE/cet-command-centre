<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AirportSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingFormRendersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, AirportSeeder::class]);
    }

    public function test_booking_form_renders_with_autocomplete_and_quote(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('bookings.create'))
            ->assertOk()
            ->assertSee('data-places', false)          // address autocomplete hooks
            ->assertSee('js/cet-forms.js', false)       // shared helper loaded
            ->assertSee('id="quote-note"', false)       // live quote display
            ->assertSee(route('pricing.estimate'), false);
    }

    public function test_an_email_enquiry_prefills_the_booking_form(): void
    {
        $admin = User::factory()->admin()->create();
        $executive = \App\Models\VehicleType::where('slug', 'executive')->first();

        $enquiry = \App\Models\EmailEnquiry::create([
            'from_email' => 'caller@example.com', 'from_name' => 'Pat Caller',
            'subject' => 'Airport transfer', 'body' => 'Need a car',
            'status' => 'quoted', 'quote_amount' => 129.00,
            'extracted' => [
                'is_enquiry' => true, 'customer_name' => 'Pat Caller',
                'pickup' => '12 Test Road, Sheffield', 'destination' => 'Manchester Airport',
                'pickup_datetime' => now()->addDay()->setTime(9, 0)->toIso8601String(),
                'passengers' => 2, 'vehicle' => 'executive', 'notes' => 'Two large cases',
            ],
        ]);

        $this->actingAs($admin)->get(route('bookings.create', ['enquiry' => $enquiry->id]))
            ->assertOk()
            ->assertSee('Prefilled from the email enquiry', false)
            ->assertSee('12 Test Road, Sheffield', false)
            ->assertSee('Manchester Airport', false)
            ->assertSee('Pat Caller', false)
            ->assertSee('value="129', false); // the emailed quote amount
    }

    public function test_booking_created_from_an_enquiry_marks_it_booked(): void
    {
        $admin = User::factory()->admin()->create();
        $executive = \App\Models\VehicleType::where('slug', 'executive')->first();
        $enquiry = \App\Models\EmailEnquiry::create([
            'from_email' => 'caller@example.com', 'from_name' => 'Pat Caller',
            'subject' => 'Transfer', 'body' => 'x', 'status' => 'quoted', 'extracted' => ['is_enquiry' => true],
        ]);

        $this->actingAs($admin)->post(route('bookings.store'), [
            'customer_name' => 'Pat Caller', 'customer_phone' => '07700900000',
            'journey_type' => 'one_way',
            'pickup_address' => '12 Test Road, Sheffield', 'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $executive->id, 'passengers' => 2,
            'payment_method' => 'card', 'privacy_consent' => '1',
            'enquiry_id' => $enquiry->id,
        ])->assertRedirect();

        $this->assertSame('booked', $enquiry->fresh()->status);
    }

    public function test_quote_form_renders_with_autocomplete(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('quotes.create'))
            ->assertOk()
            ->assertSee('data-places', false)
            ->assertSee('js/cet-forms.js', false)
            ->assertSee('id="quote-note"', false);
    }
}
