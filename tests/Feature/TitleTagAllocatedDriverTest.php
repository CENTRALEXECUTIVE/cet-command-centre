<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\CalendarEventBuilder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bracket tag in the calendar/board title is the ALLOCATED driver, the
 * vehicle type before allocation, and never a stale imported driver tag.
 */
class TitleTagAllocatedDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function title(Booking $b): string
    {
        return trim(app(CalendarEventBuilder::class)->previewTitle($b), '* ');
    }

    public function test_allocated_driver_wins_over_a_stale_import_tag(): void
    {
        $vclass = VehicleType::where('slug', 'v-class')->first() ?? VehicleType::first();
        $kash = User::factory()->create(['name' => 'Kash Ali', 'email' => 'kash@centralexecutivetransfers.co.uk']);

        $b = Booking::factory()->create([
            'vehicle_type_id' => $vclass->id,
            'driver_id' => $kash->id,
            'meta' => ['lead_name' => 'Mark Haran', 'driver_tag' => 'MAJID'],
        ]);

        // Tag is the allocated driver (KASH), not the stale "MAJID".
        $this->assertStringContainsString('(KASH)', $this->title($b));
        $this->assertStringNotContainsString('MAJID', $this->title($b));
    }

    public function test_vehicle_type_shows_until_a_driver_is_allocated(): void
    {
        $vclass = VehicleType::where('slug', 'v-class')->first() ?? VehicleType::first();
        $b = Booking::factory()->create(['vehicle_type_id' => $vclass->id, 'driver_id' => null, 'meta' => ['lead_name' => 'Mark Haran']]);

        $this->assertStringContainsString('('.strtoupper($vclass->name).')', $this->title($b));
    }

    public function test_an_imported_named_driver_shows_when_no_account_is_linked(): void
    {
        $b = Booking::factory()->create(['driver_id' => null, 'meta' => ['lead_name' => 'Mark Haran', 'driver_tag' => 'HAMZA']]);

        $this->assertStringContainsString('(HAMZA)', $this->title($b));
    }
}
