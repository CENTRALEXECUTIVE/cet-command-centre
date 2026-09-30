<?php
namespace Tests\Feature;
use App\Models\Booking; use App\Models\Customer; use App\Models\User; use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder; use Illuminate\Foundation\Testing\RefreshDatabase; use Tests\TestCase;
class BookingNotificationTest extends TestCase {
  use RefreshDatabase;
  protected function setUp(): void { parent::setUp(); $this->seed(VehicleTypeSeeder::class); }
  private function booking(): Booking {
    $exec = VehicleType::where('slug','executive')->first();
    $c = Customer::create(['name'=>'Jane Traveller','phone'=>'07700900123','email'=>'jane@example.com']);
    return Booking::factory()->forVehicleType($exec)->create(['customer_id'=>$c->id,'quoted_price'=>105]);
  }
  public function test_admin_sends_a_templated_notification_and_gets_a_link(): void {
    $admin = User::factory()->admin()->create();
    $b = $this->booking();
    $this->actingAs($admin)->post(route('bookings.notify',$b), [
      'template'=>'new_confirmed','recipient'=>'customer','channel'=>'whatsapp',
    ])->assertRedirect()->assertSessionHas('notify_link');
    $this->assertDatabaseHas('messages', ['booking_id'=>$b->id,'type'=>'notify_new_confirmed','channel'=>'whatsapp']);
  }
  public function test_email_channel_without_an_email_errors(): void {
    $admin = User::factory()->admin()->create();
    $exec = VehicleType::where('slug','executive')->first();
    $c = Customer::create(['name'=>'No Email','phone'=>'07700900123']);
    $b = Booking::factory()->forVehicleType($exec)->create(['customer_id'=>$c->id]);
    $this->actingAs($admin)->post(route('bookings.notify',$b), [
      'template'=>'cancelled','recipient'=>'customer','channel'=>'email',
    ])->assertRedirect()->assertSessionHas('error');
  }
  public function test_a_driver_cannot_send_notifications(): void {
    $driver = User::factory()->create(['role'=>'driver']);
    $b = $this->booking();
    $this->actingAs($driver)->post(route('bookings.notify',$b), [
      'template'=>'new_confirmed','recipient'=>'customer','channel'=>'whatsapp',
    ])->assertForbidden();
  }
}
