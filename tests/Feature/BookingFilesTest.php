<?php
namespace Tests\Feature;
use App\Models\Booking; use App\Models\User; use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder; use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile; use Illuminate\Support\Facades\Storage; use Tests\TestCase;
class BookingFilesTest extends TestCase {
  use RefreshDatabase;
  protected function setUp(): void { parent::setUp(); $this->seed(VehicleTypeSeeder::class); Storage::fake('local'); }
  private function booking(): Booking { $exec = VehicleType::where('slug','executive')->first(); return Booking::factory()->forVehicleType($exec)->create(); }
  public function test_admin_uploads_lists_downloads_and_deletes_a_file(): void {
    $admin = User::factory()->admin()->create();
    $b = $this->booking();
    $this->actingAs($admin)->post(route('bookings.files.store',$b), [
      'file' => UploadedFile::fake()->create('flight.pdf', 20, 'application/pdf'), 'label' => 'Flight confirmation',
    ])->assertRedirect();
    $b->refresh();
    $files = $b->meta['files'] ?? [];
    $this->assertCount(1, $files);
    $id = $files[0]['id'];
    Storage::disk('local')->assertExists($files[0]['path']);
    $this->actingAs($admin)->get(route('bookings.files.download',[$b,$id]))->assertOk();
    $this->actingAs($admin)->delete(route('bookings.files.destroy',[$b,$id]))->assertRedirect();
    $this->assertCount(0, $b->fresh()->meta['files'] ?? []);
    Storage::disk('local')->assertMissing($files[0]['path']);
  }
  public function test_drivers_cannot_upload_or_download(): void {
    $driver = User::factory()->create(['role'=>'driver']);
    $b = $this->booking();
    $this->actingAs($driver)->post(route('bookings.files.store',$b), [
      'file' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf'),
    ])->assertForbidden();
  }
  public function test_oversize_file_is_rejected(): void {
    $admin = User::factory()->admin()->create();
    $b = $this->booking();
    $this->actingAs($admin)->post(route('bookings.files.store',$b), [
      'file' => UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf'),
    ])->assertSessionHasErrors('file');
  }
}
