<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Messaging\NotificationTemplates;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The office can edit notification wording; the edited template is used (with tokens
 * filled), and can be reset to the built-in default.
 */
class MessageTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function booking(): Booking
    {
        $exec = VehicleType::where('slug', 'executive')->first();
        $c = Customer::create(['name' => 'Jane Traveller', 'phone' => '07700900123']);

        return Booking::factory()->forVehicleType($exec)->create(['customer_id' => $c->id, 'quoted_price' => 105]);
    }

    public function test_the_default_template_fills_tokens(): void
    {
        $body = app(NotificationTemplates::class)->body($this->booking(), 'new_confirmed');
        $this->assertStringContainsString('Jane', $body);           // {first}
        $this->assertStringContainsString('£105.00', $body);        // {fare}
        $this->assertStringContainsString('Central Executive Transfers', $body); // {sign}
    }

    public function test_an_admin_can_edit_a_template_and_it_is_used(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('message-templates.update', 'new_confirmed'), [
            'text' => 'Yo {first}! Car booked, ref {ref}. {sign}',
        ])->assertRedirect();

        $body = app(NotificationTemplates::class)->body($this->booking(), 'new_confirmed');
        $this->assertStringStartsWith('Yo Jane! Car booked, ref', $body);
    }

    public function test_reset_restores_the_default(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('message-templates.update', 'new_confirmed'), ['text' => 'custom {ref}'])->assertRedirect();
        $this->assertStringContainsString('custom', NotificationTemplates::rawTemplate('new_confirmed'));

        $this->actingAs($admin)->put(route('message-templates.update', 'new_confirmed'), ['reset' => '1'])->assertRedirect();
        $this->assertSame(NotificationTemplates::DEFAULTS['new_confirmed'], NotificationTemplates::rawTemplate('new_confirmed'));
    }

    public function test_a_driver_cannot_edit_templates(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('message-templates.index'))->assertForbidden();
        $this->actingAs($driver)->put(route('message-templates.update', 'new_confirmed'), ['text' => 'x'])->assertForbidden();
    }
}
