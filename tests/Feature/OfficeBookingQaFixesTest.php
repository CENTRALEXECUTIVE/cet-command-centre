<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Office booking findings from the full-system test (25 Nov 2026). */
class OfficeBookingQaFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function payload(array $o = []): array
    {
        return array_merge([
            'customer_name' => 'James Watson', 'customer_phone' => '07700900123',
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'),
            'journey_type' => 'one_way', 'pickup_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'pickup_address' => '12 Fargate, Sheffield S1 2HE', 'destination_address' => 'Leeds LS1 4DY',
            'passengers' => 2, 'luggage' => 2, 'payment_method' => 'cash', 'quoted_price' => 77.50, 'privacy_consent' => '1',
        ], $o);
    }

    public function test_switching_cash_to_card_on_an_edit_leaves_no_cash_for_the_driver(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('bookings.store'), $this->payload());
        $b = Booking::latest('id')->first();
        $this->assertTrue($b->hasCashToCollect());

        $this->actingAs($admin)->put(route('bookings.update', $b), $this->payload(['payment_method' => 'card']))->assertSessionHasNoErrors();
        $this->assertFalse($b->fresh()->hasCashToCollect(), 'the old cash line must not linger');
    }

    public function test_a_price_change_on_a_cash_job_updates_what_the_driver_collects(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('bookings.store'), $this->payload(['quoted_price' => 110]));
        $b = Booking::latest('id')->first();
        $this->actingAs($admin)->put(route('bookings.update', $b), $this->payload(['quoted_price' => 130, 'final_price' => 130]))->assertSessionHasNoErrors();
        $this->assertSame(130.0, (float) $b->fresh()->cashDueToDriver());
    }

    public function test_hourly_hours_can_be_edited(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('bookings.store'), $this->payload(['journey_type' => 'hourly', 'hours' => 4, 'destination_address' => '']));
        $b = Booking::latest('id')->first();
        $this->assertSame(4, $b->hourlyHours());
        $this->actingAs($admin)->put(route('bookings.update', $b), $this->payload(['journey_type' => 'hourly', 'hours' => 6, 'destination_address' => '']))->assertSessionHasNoErrors();
        $this->assertSame(6, $b->fresh()->hourlyHours());
    }

    public function test_a_company_client_booking_is_on_their_account_and_visible_to_them(): void
    {
        $acc = CorporateAccount::forceCreate(['name' => 'Acme Ltd', 'slug' => 'acme', 'account_code' => 'ACME', 'cost_code_required' => 0, 'payment_terms_days' => 30, 'is_active' => 1]);
        $client = User::factory()->create(['role' => UserRole::CorporateClient]);
        $client->corporateAccounts()->attach($acc->id);

        $this->actingAs($client)->post(route('bookings.store'), $this->payload(['payment_method' => 'account']));
        $b = Booking::latest('id')->first();
        $this->assertSame($acc->id, $b->corporate_account_id);
        $this->actingAs($client)->get(route('bookings.show', $b))->assertOk();
        $this->assertSame('transfers', $b->fresh()->billingEntity(), 'account jobs are invoiced with VAT by the Ltd');
    }
}
