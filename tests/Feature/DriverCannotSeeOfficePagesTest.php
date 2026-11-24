<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Drivers only get their own jobs: no office pages, even by typing the address (24 Nov 2026). */
class DriverCannotSeeOfficePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_driver_is_refused_every_office_page(): void
    {
        $driver = User::factory()->create(['role' => UserRole::Driver]);

        foreach (['jobs.day', 'invoices.index', 'cover-invoices.index', 'despatch.board', 'fleet.map',
            'calendar.index', 'customers.index', 'payments.index', 'payroll.index', 'users.index'] as $route) {
            $this->actingAs($driver)->get(route($route))->assertForbidden();
        }
    }

    public function test_the_office_still_sees_them(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get(route('jobs.day'))->assertOk();
        $this->actingAs($admin)->get(route('invoices.index'))->assertOk();
    }
}
