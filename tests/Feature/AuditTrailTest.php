<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_creating_a_booking_writes_a_created_audit_log(): void
    {
        $booking = Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created',
            'auditable_type' => Booking::class,
            'auditable_id' => $booking->id,
        ]);
    }

    public function test_updating_a_booking_records_before_and_after_values_with_user(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $booking = Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create([
            'special_requests' => 'Original',
        ]);

        $booking->update(['special_requests' => 'Updated request']);

        $log = AuditLog::where('action', 'updated')
            ->where('auditable_id', $booking->id)
            ->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertEquals($admin->id, $log->user_id);
        $this->assertEquals('Original', $log->old_values['special_requests']);
        $this->assertEquals('Updated request', $log->new_values['special_requests']);
    }

    public function test_booking_exposes_its_audit_logs(): void
    {
        $booking = Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create();

        $this->assertGreaterThanOrEqual(1, $booking->auditLogs()->count());
    }
}
