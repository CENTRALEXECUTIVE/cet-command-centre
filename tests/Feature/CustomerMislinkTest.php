<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Services\Inbox\OutlookBookingService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Different customers must NEVER collapse onto one record, and a re-file must
 * survive the next ETO ingest.
 */
class CustomerMislinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function resolve(array $parsed): Customer
    {
        $svc = app(OutlookBookingService::class);
        $m = new \ReflectionMethod($svc, 'resolveCustomer');
        $m->setAccessible(true);

        return $m->invoke($svc, $parsed);
    }

    public function test_an_anonymous_booking_never_attaches_to_an_existing_customer(): void
    {
        $jack = Customer::factory()->create(['name' => 'Jack Chapman', 'phone' => '+447823416171']);

        // A booking with no phone and no usable email must NOT grab Jack (the old
        // code returned the first customer in the table).
        $c = $this->resolve(['customer_name' => 'Emma Glaves', 'customer_phone' => null, 'customer_email' => null]);

        $this->assertNotSame($jack->id, $c->id);
        $this->assertSame('Emma Glaves', $c->name);
    }

    public function test_the_eto_sender_email_never_merges_customers(): void
    {
        $first = $this->resolve(['customer_name' => 'Emma Glaves', 'customer_phone' => '+447403876370', 'customer_email' => 'notifications@eto.taxi']);
        $second = $this->resolve(['customer_name' => 'Bob Jones', 'customer_phone' => '+447111222333', 'customer_email' => 'notifications@eto.taxi']);

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_a_real_phone_match_still_reuses_the_customer(): void
    {
        $jack = Customer::factory()->create(['name' => 'Jack Chapman', 'phone' => '+447823416171']);
        $c = $this->resolve(['customer_name' => 'Jack Chapman', 'customer_phone' => '+447823416171', 'customer_email' => null]);

        $this->assertSame($jack->id, $c->id);
    }

    public function test_a_refile_is_protected_as_an_office_edit(): void
    {
        // Booking wrongly filed under Jack; office re-files under Emma and marks it.
        $jack = Customer::factory()->create(['name' => 'Jack Chapman', 'phone' => '+447823416171']);
        $emma = Customer::factory()->create(['name' => 'Emma Glaves', 'phone' => '+447403876370']);
        $b = Booking::factory()->create(['customer_id' => $jack->id, 'external_reference' => '65ARQWa']);
        $b->forceFill(['customer_id' => $emma->id])->save();
        $b->markFieldEdited('customer_name');

        // A routine ingest update trying to move it back to Jack is dropped by the
        // office-edit guard (customer_name owns the customer_id column).
        $guarded = $b->fresh()->applyOfficeEdits(['customer_id' => $jack->id, 'pickup_address' => 'x']);

        $this->assertArrayNotHasKey('customer_id', $guarded);
        $this->assertSame($emma->id, $b->fresh()->customer_id);
    }
}
