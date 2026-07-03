<?php

namespace Tests\Feature;

use App\Models\DriverDocument;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminDriverDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function driverWithVehicle(): User
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $vehicle = Vehicle::create([
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->first()->id,
            'registration' => 'AB12 CDE', 'make' => 'Mercedes', 'model' => 'E-Class',
            'colour' => 'Black', 'year' => 2022, 'is_active' => true,
        ]);
        DriverProfile::create(['user_id' => $driver->id, 'is_third_party' => false, 'default_vehicle_id' => $vehicle->id]);

        return $driver;
    }

    public function test_admin_index_lists_drivers_with_status(): void
    {
        $admin = User::factory()->admin()->create();
        $this->driverWithVehicle();

        $this->actingAs($admin)->get(route('driver-documents.index'))
            ->assertOk()->assertSee('AB12 CDE')->assertSee('missing');
    }

    public function test_non_admin_cannot_access(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('driver-documents.index'))->assertForbidden();
    }

    public function test_admin_approves_a_pending_document_and_syncs_expiry(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $driver = $this->driverWithVehicle();
        $expiry = now()->addDays(200)->startOfDay();

        $doc = DriverDocument::create([
            'user_id' => $driver->id, 'type' => 'mot', 'file_path' => 'x/mot.pdf',
            'file_name' => 'mot.pdf', 'expiry_date' => $expiry, 'status' => 'pending',
        ]);

        $this->actingAs($admin)->post(route('driver-documents.review', $doc), ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertEquals('approved', $doc->fresh()->status);
        // Expiry synced onto the vehicle's MOT date.
        $this->assertEquals($expiry->toDateString(), $driver->driverProfile->defaultVehicle->fresh()->mot_expiry->toDateString());
    }

    public function test_admin_uploads_on_behalf_auto_approved(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $driver = $this->driverWithVehicle();

        $this->actingAs($admin)->post(route('driver-documents.store', $driver), [
            'type' => 'badge',
            'file' => UploadedFile::fake()->create('badge.pdf', 100, 'application/pdf'),
            'expiry_date' => now()->addYear()->toDateString(),
        ])->assertRedirect();

        $doc = DriverDocument::where('user_id', $driver->id)->where('type', 'badge')->first();
        $this->assertEquals('approved', $doc->status);
        $this->assertEquals($admin->id, $doc->reviewed_by);
        $this->assertNotNull($driver->driverProfile->fresh()->phv_badge_expiry);
    }
}
