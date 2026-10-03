<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Inbox\GraphMailClient;
use App\Services\Inbox\OutlookBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The "Resync with email" button runs the ETO email ingest on demand, reports
 * what it did, and is a clear no-op (with a message) when the mailbox isn't
 * connected. Admin-only.
 */
class EmailResyncButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_imports_page_shows_the_resync_button(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('imports.index'))
            ->assertOk()
            ->assertSee('Resync with email', false);
    }

    public function test_resync_runs_the_ingest_and_reports_counts_when_connected(): void
    {
        $admin = User::factory()->admin()->create();

        $outlook = Mockery::mock(OutlookBookingService::class);
        $outlook->shouldReceive('ingest')->once()
            ->andReturn(['processed' => 5, 'created' => 1, 'updated' => 2, 'cancelled' => 0, 'skipped' => 2]);
        $this->app->instance(OutlookBookingService::class, $outlook);

        $mail = Mockery::mock(GraphMailClient::class);
        $mail->shouldReceive('configured')->andReturn(true);
        $this->app->instance(GraphMailClient::class, $mail);

        $this->actingAs($admin)->post(route('imports.resync-email'), ['days' => 30])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, '2 updated'));
    }

    public function test_resync_is_a_clear_no_op_when_email_not_connected(): void
    {
        $admin = User::factory()->admin()->create();

        $outlook = Mockery::mock(OutlookBookingService::class);
        $outlook->shouldNotReceive('ingest');  // must not run with no mailbox
        $this->app->instance(OutlookBookingService::class, $outlook);

        $mail = Mockery::mock(GraphMailClient::class);
        $mail->shouldReceive('configured')->andReturn(false);
        $this->app->instance(GraphMailClient::class, $mail);

        $this->actingAs($admin)->post(route('imports.resync-email'))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'isn’t connected'));
    }

    public function test_resync_is_admin_only(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->post(route('imports.resync-email'))->assertForbidden();
    }
}
