<?php
namespace Tests\Feature;
use App\Mail\QuoteMail;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
class QuoteEmailTest extends TestCase {
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->seed(VehicleTypeSeeder::class); }
    public function test_quote_page_renders(): void {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('quotes.create'))->assertOk()
            ->assertSee('Swap pickup', false)->assertSee('Show +20% VAT', false)
            ->assertSee('Customer email', false)->assertDontSee('Distance (miles)', false);
    }
    public function test_generating_with_email_sends_the_quote(): void {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug','executive')->first();
        $this->actingAs($admin)->post(route('quotes.store'), [
            'vehicle_type_id' => $exec->id,
            'pickup_address' => 'Sheffield S20 1AA',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDays(2)->format('Y-m-d H:i'),
            'customer_name' => 'Jane McGuinness',
            'customer_email' => 'jane@example.com',
            'apply_vat' => '1',
        ])->assertRedirect();
        Mail::assertSent(QuoteMail::class, fn ($m) => $m->hasTo('jane@example.com'));
    }
    public function test_generating_without_email_sends_nothing(): void {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $exec = VehicleType::where('slug','executive')->first();
        $this->actingAs($admin)->post(route('quotes.store'), [
            'vehicle_type_id' => $exec->id,
            'pickup_address' => 'Sheffield',
            'destination_address' => 'Leeds',
            'pickup_at' => now()->addDays(2)->format('Y-m-d H:i'),
        ])->assertRedirect();
        Mail::assertNothingSent();
    }
}
