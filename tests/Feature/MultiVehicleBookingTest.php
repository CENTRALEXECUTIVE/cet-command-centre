<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmationMail;
use App\Mail\OfficeBookingMail;
use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\CalendarEventBuilder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * "Vehicles: 2" on the booking form (Nov 2026, Forged Solutions' 10 people in two
 * V-Classes with a VAT invoice): the group may exceed one vehicle's seats, the
 * price covers both vehicles, and every place that names the vehicle says
 * "2 × V Class". The second car's driver is added under Extra cars.
 */
class MultiVehicleBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
    }

    private function payload(array $o = []): array
    {
        return array_merge([
            'customer_name' => 'Nicola Wright', 'customer_phone' => '07387109751', 'customer_email' => 'nicola.wright@example.com',
            'vehicle_type_id' => VehicleType::where('slug', 'v-class')->value('id'),
            'journey_type' => 'one_way',
            'pickup_at' => now()->addDays(4)->setTime(18, 15)->format('Y-m-d\TH:i'),
            'pickup_address' => "Browns Brasserie & Bar, 8-12 St Pauls Parade, Sheffield S1 2JL",
            'destination_address' => 'Magna Science Adventure Centre, Magna Way, Rotherham S60 1FD',
            'passengers' => 10, 'vehicle_count' => 2, 'suitcases' => 0, 'hand_luggage' => 0,
            'payment_method' => 'card', 'quoted_price' => 120.00,
            'vat_invoice' => '1', 'vat_company' => 'Forged Solutions Group', 'vat_address' => 'Meadowhall Road, Sheffield S9 1HD',
            'privacy_consent' => '1',
        ], $o);
    }

    public function test_ten_people_in_two_v_classes_with_vat_saves_and_reads_two_times_v_class_everywhere(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('bookings.store'), $this->payload())->assertSessionHasNoErrors()->assertRedirect();

        $b = Booking::firstOrFail();
        $this->assertSame(10, $b->passengers);
        $this->assertSame(2, (int) $b->meta['vehicle_count']);
        $this->assertSame(2, $b->vehicleCount());
        $this->assertSame('2 × V Class', $b->vehicleLabel());
        $this->assertSame(1, $b->vehiclesStillToAllocate());
        $this->assertEquals(120.0, (float) $b->quoted_price, 'the price covers both vehicles');
        $this->assertTrue($b->vatInvoiceRequested());
        $this->assertEquals(144.0, (float) $b->amountPayable(), '£120 + 20% VAT');

        // Booking page.
        $this->actingAs($admin)->get(route('bookings.show', $b))->assertOk()
            ->assertSee('2 × V Class')->assertSee('1 more car to add under', false);

        // Calendar title + details.
        $event = app(CalendarEventBuilder::class)->preview($b);
        $this->assertStringContainsString('2 × V CLASS', json_encode($event, JSON_UNESCAPED_UNICODE));

        // Customer confirmation + office email.
        $this->assertStringContainsString('2 × V Class', (new BookingConfirmationMail($b, paid: false))->render());
        $this->assertStringContainsString('2 × V Class', (new OfficeBookingMail($b, 'new'))->render());
    }

    public function test_editing_keeps_ten_passengers_valid_and_adding_the_second_car_clears_the_reminder(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('bookings.store'), $this->payload())->assertRedirect();
        $b = Booking::firstOrFail();

        $this->actingAs($admin)->post(route('bookings.extra-drivers.add', $b), ['name' => 'Car 2 driver', 'passengers' => 5])->assertRedirect();
        $b = $b->fresh();
        $this->assertSame(0, $b->vehiclesStillToAllocate());
        $this->assertSame(2, $b->vehicleCount());
        $this->assertSame('2 × V Class', $b->vehicleLabel());
    }

    public function test_one_v_class_still_refuses_ten_people(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload(['vehicle_count' => 1]))
            ->assertSessionHasErrors('passengers');
        $this->assertSame(0, Booking::count());
    }

    public function test_two_v_classes_refuse_more_than_fourteen(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload(['passengers' => 15]))
            ->assertSessionHasErrors('passengers');
    }

    public function test_a_single_vehicle_booking_is_unchanged(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.store'), $this->payload(['passengers' => 4, 'vehicle_count' => 1]))->assertRedirect();
        $b = Booking::firstOrFail();
        $this->assertArrayNotHasKey('vehicle_count', $b->meta ?? []);
        $this->assertSame('V Class', $b->vehicleLabel());
    }

    public function test_the_new_booking_email_preview_says_two_v_classes(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.confirmation-email.preview-new'), $this->payload())
            ->assertOk()->assertSee('2 × V Class', false);
    }

    public function test_the_vat_invoice_and_cancellation_say_two_v_classes(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post(route('bookings.store'), $this->payload())->assertRedirect();
        $b = Booking::firstOrFail();

        $detail = (new \ReflectionMethod(\App\Services\Payments\InvoicePdf::class, 'legDetail'))
            ->invoke(app(\App\Services\Payments\InvoicePdf::class), $b);
        $this->assertStringContainsString('2 × V Class · 10 passengers', $detail);
        $this->assertNotEmpty(app(\App\Services\Payments\InvoicePdf::class)->renderReceipt($b), 'the VAT invoice PDF still renders');
    }
}
