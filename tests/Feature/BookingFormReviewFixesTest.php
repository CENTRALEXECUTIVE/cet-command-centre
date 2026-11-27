<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** 27 Nov 2026 booking-form review: return stops, PO number, VAT pre-fill, notice message. */
class BookingFormReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private function ret(array $o = []): array
    {
        return array_merge([
            'journey_type' => 'return',
            'pickup_address' => 'Sheffield S1 2HH', 'pickup_postcode' => 'S1 2HH',
            'destination_address' => 'Leeds LS1 4DY', 'destination_postcode' => 'LS1 4DY',
            'pickup_at' => now()->addDays(10)->setTime(9, 0)->format('Y-m-d\TH:i'),
            'return_pickup_at' => now()->addDays(11)->setTime(17, 0)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'), 'passengers' => 1,
            'customer_first_name' => 'Stop', 'customer_surname' => 'Tester', 'customer_phone' => '07700900888', 'customer_email' => uniqid().'@example.com',
            'payment_method' => 'card',
            'stops' => ['Meadowhall, Sheffield S9 1EP'],
        ], $o);
    }

    public function test_a_return_without_the_outbound_stop_is_not_charged_for_it(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
        // Return page left with NO stops.
        $this->post(route('widget.book.store'), $this->ret(['return_stops_set' => '1', 'return_stops' => []]))->assertOk()->assertSessionHasNoErrors();
        $outA = Booking::where('is_return_leg', false)->latest('id')->first();
        $retA = Booking::where('is_return_leg', true)->latest('id')->first();
        $this->assertSame(['Meadowhall, Sheffield S9 1EP'], $outA->viaStops());
        $this->assertSame([], $retA->viaStops());

        // Same trip, return keeps the stop (the pre-filled default) → £10 more on the return.
        $this->post(route('widget.book.store'), $this->ret(['return_stops_set' => '1', 'return_stops' => ['Meadowhall, Sheffield S9 1EP']]))->assertOk();
        $retB = Booking::where('is_return_leg', true)->latest('id')->first();
        $this->assertSame(['Meadowhall, Sheffield S9 1EP'], $retB->viaStops());
        $this->assertEqualsWithDelta(10.0, (float) $retB->quoted_price - (float) $retA->quoted_price, 0.01, 'one stop = £10 on the leg that has it');

        // The price shown matches.
        $common = ['pickup' => 'Sheffield S1 2HH', 'destination' => 'Leeds LS1 4DY', 'journey_type' => 'return',
            'pickup_at' => now()->addDays(10)->format('Y-m-d\TH:i'), 'return_pickup_at' => now()->addDays(11)->format('Y-m-d\TH:i'), 'stops' => 1];
        $exec = VehicleType::where('slug', 'executive')->value('id');
        $none = collect($this->postJson(route('widget.prices'), $common + ['return_stops' => 0])->json('options'))->firstWhere('id', $exec);
        $one = collect($this->postJson(route('widget.prices'), $common + ['return_stops' => 1])->json('options'))->firstWhere('id', $exec);
        $this->assertEqualsWithDelta(10.0, $one['total'] - $none['total'], 0.01);
    }

    public function test_the_po_number_is_saved_on_both_legs_and_shown(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        Mail::fake();
        $this->post(route('widget.book.store'), $this->ret([
            'vat_invoice' => '1', 'business_name' => 'Example Ltd', 'business_address' => '1 Test St', 'business_vat_number' => 'GB1',
            'po_number' => 'PO-4471',
        ]))->assertOk()->assertSessionHasNoErrors();
        foreach (Booking::all() as $b) {
            $this->assertSame('PO-4471', $b->cost_code);
        }
        $this->actingAs(User::factory()->admin()->create())->get(route('bookings.show', Booking::first()))
            ->assertSee('PO / cost code')->assertSee('PO-4471');
    }

    public function test_vat_details_prefill_for_a_repeat_company_email_only(): void
    {
        $c = Customer::create(['name' => 'Rep', 'email' => 'buyer@acme-works.co.uk', 'phone' => '07700900999']);
        Booking::factory()->create(['customer_id' => $c->id, 'meta' => ['vat_details' => ['name' => 'Acme Works Ltd', 'address' => '2 Mill Rd', 'vat_number' => 'GB777']]]);
        $this->getJson(route('widget.vat-details', ['email' => 'buyer@acme-works.co.uk']))->assertJson(['name' => 'Acme Works Ltd', 'vat_number' => 'GB777']);
        // A free-mail address never reveals anything.
        $g = Customer::create(['name' => 'G', 'email' => 'someone@gmail.com', 'phone' => '07700900998']);
        Booking::factory()->create(['customer_id' => $g->id, 'meta' => ['vat_details' => ['name' => 'Secret Ltd']]]);
        $this->getJson(route('widget.vat-details', ['email' => 'someone@gmail.com']))->assertExactJson([]);
        // Office form endpoint (admins) works for any email.
        $this->actingAs(User::factory()->admin()->create())->getJson(route('customers.vat-details', ['email' => 'someone@gmail.com']))->assertJson(['name' => 'Secret Ltd']);
    }

    public function test_short_notice_message_offers_whatsapp_and_email(): void
    {
        $this->seed(VehicleTypeSeeder::class);
        $html = $this->get(route('widget.book'))->getContent();
        $this->assertStringContainsString('WhatsApp or call us on +44 7405 172435, or email admin@centralexecutivetransfers.co.uk', $html);
        $this->assertStringContainsString('name="return_stops_set"', $html);
        $this->assertStringContainsString('name="po_number"', $html);
    }
}
