<?php

namespace Tests\Feature;

use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public, embeddable mini web-booking widget (ETO-style): a customer checks a
 * price with no login, using the existing CET fixed-price/free-roam engine.
 */
class BookingWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_the_mini_widget_page_is_public_and_embeddable(): void
    {
        $res = $this->get(route('widget.mini'))->assertOk()
            ->assertSee('Get my price')
            ->assertSee('CENTRAL');

        // It must allow embedding on the marketing site (frame-ancestors set,
        // and no blanket X-Frame-Options DENY).
        $csp = $res->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-ancestors', (string) $csp);
        $this->assertStringContainsString('centralexecutivetransfers.co.uk', (string) $csp);
        $this->assertNotSame('DENY', $res->headers->get('X-Frame-Options'));
    }

    public function test_it_returns_a_fixed_price_for_a_known_route(): void
    {
        $executive = VehicleType::where('slug', 'executive')->first();

        $this->postJson(route('widget.price'), [
            'pickup' => 'Sheffield S1 2HH',
            'destination' => 'Manchester Airport',
            'vehicle_type_id' => $executive->id,
        ])->assertOk()
            ->assertJson(['fixed' => true, 'vehicle' => $executive->name])
            ->assertJsonPath('price', 100)
            ->assertJsonPath('formatted', '£100');
    }

    public function test_it_validates_the_inputs(): void
    {
        $this->postJson(route('widget.price'), ['pickup' => 'Sheffield'])
            ->assertStatus(422);
    }
}
