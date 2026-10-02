<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The global activity log — a read-only "who did what" feed for admins.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_log_shows_recent_actions(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Abdi Boss']);
        $booking = Booking::factory()->create();
        AuditLog::create([
            'user_id' => $admin->id, 'action' => 'deleted',
            'auditable_type' => Booking::class, 'auditable_id' => $booking->id,
            'created_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('activity-log.index'))->assertOk()
            ->assertSee('Activity log')
            ->assertSee('Abdi Boss')
            ->assertSee('Deleted')
            ->assertSee('Booking #'.$booking->id);
    }

    public function test_it_can_filter_by_action(): void
    {
        $admin = User::factory()->admin()->create();
        AuditLog::create(['user_id' => $admin->id, 'action' => 'created', 'created_at' => now()]);
        AuditLog::create(['user_id' => $admin->id, 'action' => 'exported', 'created_at' => now()]);

        $res = $this->actingAs($admin)->get(route('activity-log.index', ['action' => 'exported']))->assertOk();
        $res->assertSee('Exported');
    }

    public function test_a_driver_cannot_view_the_activity_log(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('activity-log.index'))->assertForbidden();
    }
}
