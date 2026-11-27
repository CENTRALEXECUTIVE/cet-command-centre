<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Directors, 27 Nov 2026: a phone number that looks wrong must never block a booking.
 * Every other check stays as it was.
 */
class PhoneNeverBlocksBookingTest extends TestCase
{
    use RefreshDatabase;

    private function base(array $o = []): array
    {
        return array_merge([
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Rotherham S60 5WG', 'destination_postcode' => 'S60 5WG',
            'pickup_at' => now()->addDays(10)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'), 'passengers' => 1,
            'customer_first_name' => 'Claire', 'customer_surname' => 'Example',
            'customer_phone' => '+33 6 64', 'customer_email' => 'someone@jeld-wen.com',
        ], $o);
    }

    public function test_a_wrong_looking_phone_is_saved_and_flagged_not_refused(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
        $this->post(route('widget.book.store'), $this->base())->assertOk()->assertSessionHasNoErrors();

        $b = Booking::where('is_return_leg', false)->latest('id')->firstOrFail();
        $this->assertStringContainsString('Phone number looked wrong', implode(' ', $b->meta['passed_through'] ?? []));
        $this->actingAs(User::factory()->admin()->create())->get(route('bookings.show', $b))->assertOk()->assertSee('Phone number looked wrong');
        Mail::assertSent(\App\Mail\OfficeBookingMail::class, fn ($m) => str_contains($m->render(), 'Check before confirming'));
    }

    public function test_the_book_page_also_never_refuses_on_the_phone(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
        $this->post(route('public.book.store'), [
            'pickup_address' => '10 Division Street, Sheffield', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Manchester Airport (MAN)', 'pickup_at' => now()->addDays(10)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'), 'passengers' => 1,
            'customer_name' => 'Claire Example', 'customer_email' => 'someone@jeld-wen.com', 'customer_phone' => '12345',
        ])->assertSessionHasNoErrors();
        $this->assertNotNull(Booking::latest('id')->first());
    }

    public function test_every_other_check_is_unchanged_even_for_a_business(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        // Short notice is still refused.
        $this->post(route('widget.book.store'), $this->base(['customer_phone' => '07700900123', 'pickup_at' => now()->addHours(2)->format('Y-m-d\TH:i')]))
            ->assertSessionHasErrors('pickup_at');
        // VAT ticked with no company details is still refused.
        $this->post(route('widget.book.store'), $this->base(['customer_phone' => '07700900123', 'vat_invoice' => 1]))
            ->assertSessionHasErrors(['business_name', 'business_vat_number']);
        $this->assertSame(0, Booking::count());
    }
}
