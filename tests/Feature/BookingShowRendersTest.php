<?php
namespace Tests\Feature;
use App\Models\Booking; use App\Models\User; use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder; use Illuminate\Foundation\Testing\RefreshDatabase; use Tests\TestCase;
class BookingShowRendersTest extends TestCase {
  use RefreshDatabase;
  public function test_show_renders_payment_history(): void {
    $this->seed(VehicleTypeSeeder::class);
    $admin = User::factory()->admin()->create();
    $exec = VehicleType::where('slug','executive')->first();
    $b = Booking::factory()->forVehicleType($exec)->create(['quoted_price'=>105]);
    $b->payments()->create(['method'=>'card','amount'=>105,'status'=>'paid','paid_at'=>now(),'meta'=>['name'=>'Full amount']]);
    $this->actingAs($admin)->get(route('bookings.show',$b))->assertOk()
      ->assertSee('Payment history')->assertSee('Add new transaction')->assertSee('Full amount');
  }
}
