<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class VehiclePhotoTest extends TestCase
{
    use RefreshDatabase;

    /** A throwaway slug so the upload test never touches a real fleet photo. */
    private const SLUG = 'test-fleet-photo-zzz';

    protected function tearDown(): void
    {
        foreach (['webp', 'png', 'jpg', 'jpeg'] as $ext) {
            $p = public_path('images/fleet/'.self::SLUG.'.'.$ext);
            if (is_file($p)) {
                @unlink($p);
            }
        }
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_the_page_lists_vehicles(): void
    {
        $this->seed(VehicleTypeSeeder::class);

        $this->actingAs($this->admin())->get(route('fleet-photos.index'))
            ->assertOk()
            ->assertSee('Executive')
            ->assertSee('Rolls Royce Ghost');
    }

    public function test_admin_can_upload_and_remove_a_vehicle_photo(): void
    {
        $type = VehicleType::create(['name' => 'Test Fleet', 'slug' => self::SLUG, 'passenger_capacity' => 4]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('fleet-photos.store', $type), ['photo' => UploadedFile::fake()->image('car.jpg', 800, 500)])
            ->assertRedirect();

        $this->assertTrue(is_file(public_path('images/fleet/'.self::SLUG.'.jpg')));
        $this->assertNotNull($type->fresh()->photoUrl());

        $this->actingAs($admin)
            ->delete(route('fleet-photos.destroy', $type))
            ->assertRedirect();

        $this->assertFalse(is_file(public_path('images/fleet/'.self::SLUG.'.jpg')));
        $this->assertNull($type->fresh()->photoUrl());
    }

    public function test_a_non_image_is_rejected(): void
    {
        $type = VehicleType::create(['name' => 'Test Fleet', 'slug' => self::SLUG, 'passenger_capacity' => 4]);

        $this->actingAs($this->admin())
            ->post(route('fleet-photos.store', $type), ['photo' => UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf')])
            ->assertSessionHasErrors('photo');

        $this->assertNull($type->fresh()->photoUrl());
    }

    public function test_a_driver_cannot_manage_vehicle_photos(): void
    {
        $type = VehicleType::create(['name' => 'Test Fleet', 'slug' => self::SLUG, 'passenger_capacity' => 4]);

        $this->actingAs(User::factory()->driver()->create())
            ->get(route('fleet-photos.index'))->assertForbidden();
    }

    public function test_vehicle_capacities_match_eto(): void
    {
        $this->seed(VehicleTypeSeeder::class);

        $exec = VehicleType::where('slug', 'executive')->first();
        $this->assertSame(4, $exec->passenger_capacity);
        $this->assertSame(2, $exec->luggage_capacity);

        $mini = VehicleType::where('slug', 'minibus-8')->first();
        $this->assertSame(7, $mini->passenger_capacity);
        $this->assertSame(5, $mini->luggage_capacity);

        $xl = VehicleType::where('slug', 'minibus-8-xl')->first();
        $this->assertSame(8, $xl->passenger_capacity);
        $this->assertSame(8, $xl->luggage_capacity);
    }
}
