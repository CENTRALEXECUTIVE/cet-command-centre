<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketingStudioTest extends TestCase
{
    use RefreshDatabase;

    public function test_studio_shows_a_notice_when_ai_is_off(): void
    {
        config(['services.anthropic.key' => null]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('marketing.studio'))
            ->assertOk()
            ->assertSee('Marketing Studio')
            ->assertSee('AI isn’t switched on yet', false);
    }

    public function test_admin_generates_social_posts(): void
    {
        config([
            'services.anthropic.key' => 'test-key',
            'services.anthropic.base_url' => 'https://api.anthropic.com',
        ]);
        Http::fake([
            '*/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode([
                    'posts' => [[
                        'caption' => 'Arrive in style with Central Executive Transfers.',
                        'hashtags' => '#Sheffield #AirportTransfers',
                        'image_idea' => 'Black Mercedes at Manchester Airport',
                    ]],
                ])]],
            ], 200),
        ]);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('marketing.studio.generate'), ['type' => 'social_posts', 'topic' => 'Manchester Airport'])
            ->assertOk()
            ->assertSee('Arrive in style with Central Executive Transfers.')
            ->assertSee('#Sheffield #AirportTransfers')
            ->assertSee('Black Mercedes at Manchester Airport');
    }

    public function test_generate_rejects_an_unknown_type(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('marketing.studio.generate'), ['type' => 'nonsense'])
            ->assertSessionHasErrors('type');
    }

    public function test_studio_is_admin_only(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('marketing.studio'))->assertForbidden();
    }
}
