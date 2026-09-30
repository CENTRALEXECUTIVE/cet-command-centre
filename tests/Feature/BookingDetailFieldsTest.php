<?php
namespace Tests\Feature;
use App\Models\Booking; use App\Models\User; use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder; use Illuminate\Foundation\Testing\RefreshDatabase; use Tests\TestCase;
class BookingDetailFieldsTest extends TestCase {
  use RefreshDatabase;
  protected function setUp(): void { parent::setUp(); $this->seed(VehicleTypeSeeder::class); }
  public function test_distance_and_duration_from_cached_coords(): void {
    $exec = VehicleType::where('slug','executive')->first();
    // Sheffield centre → Manchester Airport, roughly.
    $b = Booking::factory()->forVehicleType($exec)->create(['meta'=>['geo'=>['pickup'=>[53.3811,-1.4701],'dropoff'=>[53.3537,-2.2750]]]]);
    $this->assertNotNull($b->estimatedDistanceMiles());
    $this->assertGreaterThan(20, $b->estimatedDistanceMiles());
    $this->assertNotNull($b->estimatedDurationLabel());
  }
  public function test_no_distance_without_coords(): void {
    $exec = VehicleType::where('slug','executive')->first();
    $b = Booking::factory()->forVehicleType($exec)->create();
    $this->assertNull($b->estimatedDistanceMiles());
  }
  public function test_admin_sets_notification_language(): void {
    $admin = User::factory()->admin()->create();
    $exec = VehicleType::where('slug','executive')->first();
    $b = Booking::factory()->forVehicleType($exec)->create();
    $this->assertSame('English', $b->notificationLanguage());
    $this->actingAs($admin)->post(route('bookings.notification-language',$b), ['notification_language'=>'Polski'])->assertRedirect();
    $this->assertSame('Polski', $b->fresh()->notificationLanguage());
    // Back to English clears it.
    $this->actingAs($admin)->post(route('bookings.notification-language',$b), ['notification_language'=>'English'])->assertRedirect();
    $this->assertSame('English', $b->fresh()->notificationLanguage());
  }
}
