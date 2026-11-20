<?php

namespace Tests\Feature;

use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Directors, 20 Nov 2026: an E-Class takes up to 5 carry-ons when there are no suitcases. */
class CarryOnLuggageFitTest extends TestCase
{
    use RefreshDatabase;

    private function eClass(): VehicleType
    {
        return VehicleType::create(['name' => 'Executive', 'slug' => 'executive', 'passenger_capacity' => 4, 'luggage_capacity' => 2, 'is_active' => true]);
    }

    public function test_e_class_carry_on_rules(): void
    {
        $e = $this->eClass();
        $this->assertSame(5.0, $e->luggageSpace());
        $this->assertTrue($e->fitsLuggage(0, 3));
        $this->assertTrue($e->fitsLuggage(0, 5));
        $this->assertFalse($e->fitsLuggage(0, 6));
        $this->assertTrue($e->fitsLuggage(1, 3));
        $this->assertFalse($e->fitsLuggage(1, 4));
        $this->assertTrue($e->fitsLuggage(2, 2));   // unchanged
        $this->assertFalse($e->fitsLuggage(2, 3));
        $this->assertFalse($e->fitsLuggage(3, 0));  // never more suitcases than the boot takes
    }

    public function test_booking_page_carries_the_space_figure_and_rule(): void
    {
        $this->eClass();
        $html = $this->get(route('widget.book'))->assertOk()->getContent();
        $this->assertStringContainsString('data-cap-space="5"', $html);
        $this->assertStringContainsString('(suit * 1.5 + hand) <= capSpace', $html);
    }
}
