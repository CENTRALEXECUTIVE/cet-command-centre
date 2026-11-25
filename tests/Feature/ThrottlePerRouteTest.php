<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** QA 25 Nov 2026: address lookups used up the booking form's own limit (429). */
class ThrottlePerRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_route_has_its_own_rate_limit_counter(): void
    {
        config(['cache.default' => 'file']);
        Route::middleware('throttle:3,1')->get('/__t/a', fn () => 'a')->name('t.a');
        Route::middleware('throttle:3,1')->get('/__t/b', fn () => 'b')->name('t.b');

        foreach (range(1, 3) as $i) {
            $this->get('/__t/a')->assertOk();
        }
        $this->get('/__t/a')->assertStatus(429);
        // A different route from the same visitor still works.
        $this->get('/__t/b')->assertOk();
    }
}
