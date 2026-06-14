<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Consent;
use App\Models\Customer;
use App\Models\DataErasureRequest;
use App\Models\Message;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Gdpr\DataErasureService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataErasureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function customerWithData(): Customer
    {
        $customer = Customer::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com', 'phone' => '07700900123']);
        $booking = Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create([
            'customer_id' => $customer->id,
            'pickup_address' => '12 Private Road, Sheffield',
            'destination_address' => 'Manchester Airport',
            'final_price' => 120,
        ]);
        Message::create(['booking_id' => $booking->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp',
            'type' => 'confirmation', 'to_address' => '07700900123', 'body' => 'Your booking is confirmed', 'status' => 'sent']);
        Consent::create(['subject_type' => 'customer', 'subject_id' => $customer->id, 'email' => 'jane@example.com',
            'type' => 'privacy_notice', 'granted' => true, 'granted_at' => now()]);

        return $customer;
    }

    public function test_erasure_removes_pii_but_keeps_booking_financials(): void
    {
        $customer = $this->customerWithData();
        $bookingId = $customer->bookings()->first()->id;

        app(DataErasureService::class)->eraseCustomer($customer);

        // Customer anonymised + soft-deleted.
        $fresh = Customer::withTrashed()->find($customer->id);
        $this->assertNotNull($fresh->deleted_at);
        $this->assertNull($fresh->email);
        $this->assertNull($fresh->phone);
        $this->assertStringContainsString('Erased customer', $fresh->name);

        // Booking kept, financials intact, PII stripped.
        $booking = Booking::find($bookingId);
        $this->assertEquals('120.00', $booking->final_price);
        $this->assertEquals('[erased]', $booking->pickup_address);
        $this->assertEquals('[erased]', $booking->destination_address);

        // Messages redacted, consent withdrawn.
        $this->assertEquals('[erased]', Message::where('customer_id', $customer->id)->first()->body);
        $this->assertFalse((bool) Consent::where('subject_id', $customer->id)->first()->granted);
    }

    public function test_erasure_purges_pii_audit_logs_and_records_one_clean_entry(): void
    {
        $customer = $this->customerWithData();

        // Creating the data above generated 'created' audit logs holding PII.
        $this->assertGreaterThan(0, AuditLog::where('auditable_type', Customer::class)->where('action', 'created')->count());

        app(DataErasureService::class)->eraseCustomer($customer);

        // No PII-bearing created/updated logs remain for this customer.
        $this->assertEquals(0, AuditLog::where('auditable_type', Customer::class)
            ->where('auditable_id', $customer->id)->where('action', '!=', 'erased')->count());
        // Exactly one clean erasure record.
        $this->assertEquals(1, AuditLog::where('action', 'erased')->where('auditable_id', $customer->id)->count());
    }

    public function test_admin_can_raise_and_process_an_erasure_request(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = $this->customerWithData();

        $this->actingAs($admin)->post(route('gdpr.erasure.store'), ['identifier' => 'jane@example.com'])
            ->assertRedirect();
        $req = DataErasureRequest::first();
        $this->assertEquals('pending', $req->status);

        $this->actingAs($admin)->post(route('gdpr.erasure.process', $req))->assertRedirect();

        $req->refresh();
        $this->assertEquals('completed', $req->status);
        $this->assertEquals($admin->id, $req->processed_by);
        $this->assertNull(Customer::find($customer->id)); // soft-deleted
    }

    public function test_non_admin_cannot_access_erasure(): void
    {
        $this->actingAs(User::factory()->corporateClient()->create())
            ->get(route('gdpr.erasure'))->assertForbidden();
    }
}
