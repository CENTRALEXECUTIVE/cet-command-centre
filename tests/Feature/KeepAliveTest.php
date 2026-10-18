<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeepAliveTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_user_can_ping_keep_alive_and_gets_a_token(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('keep-alive'))
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonStructure(['ok', 'token']);
    }

    public function test_a_guest_cannot_use_keep_alive(): void
    {
        $this->get(route('keep-alive'))->assertRedirect(route('login'));
    }
}
