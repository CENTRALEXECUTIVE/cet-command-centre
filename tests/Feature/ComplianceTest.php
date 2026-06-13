<?php

namespace Tests\Feature;

use App\Models\ComplianceItem;
use App\Models\DriverProfile;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\ComplianceService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplianceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_sync_creates_and_classifies_items_from_fleet_and_drivers(): void
    {
        $vt = VehicleType::where('slug', 'executive')->first();
        Vehicle::create([
            'vehicle_type_id' => $vt->id, 'registration' => 'CET1',
            'mot_expiry' => now()->addDays(10),      // due soon
            'insurance_expiry' => now()->subDays(2),  // expired
            'phv_licence_expiry' => now()->addYear(), // valid
        ]);

        $driver = User::factory()->driver()->create();
        DriverProfile::create([
            'user_id' => $driver->id, 'is_third_party' => true,
            'dbs_expiry' => now()->addDays(5), 'phv_badge_expiry' => now()->addMonths(6),
        ]);

        app(ComplianceService::class)->sync();

        $this->assertEquals('due_soon', ComplianceItem::where('item_type', 'mot')->first()->status);
        $this->assertEquals('expired', ComplianceItem::where('item_type', 'insurance')->first()->status);
        $this->assertEquals('valid', ComplianceItem::where('item_type', 'phv_vehicle')->first()->status);
        $this->assertEquals('due_soon', ComplianceItem::where('item_type', 'dbs')->first()->status);
    }

    public function test_due_reminders_are_sent_and_marked(): void
    {
        Setting::set('ops_whatsapp', '07700900001');

        $vt = VehicleType::where('slug', 'executive')->first();
        Vehicle::create(['vehicle_type_id' => $vt->id, 'registration' => 'CET2', 'mot_expiry' => now()->subDay()]);

        $service = app(ComplianceService::class);
        $service->sync();
        $sent = $service->sendDueReminders();

        $this->assertGreaterThanOrEqual(1, $sent);
        $this->assertDatabaseHas('messages', ['channel' => 'whatsapp', 'to_address' => '07700900001']);
        $this->assertNotNull(ComplianceItem::where('item_type', 'mot')->first()->reminder_sent_at);

        // Running again does not re-send (reminded within 7 days).
        $this->assertEquals(0, $service->sendDueReminders());
    }

    public function test_check_compliance_command_runs(): void
    {
        $this->artisan('cet:check-compliance')->assertSuccessful();
    }
}
