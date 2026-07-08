<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_is_served_and_describes_the_app(): void
    {
        $this->get('/manifest.webmanifest')->assertOk();

        // The route serves the file from disk — validate that file's content.
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertSame('CET Command Centre', $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertNotEmpty($manifest['icons']);
        $this->assertTrue(
            collect($manifest['icons'])->contains(fn ($i) => ($i['purpose'] ?? '') === 'maskable'),
            'A maskable icon is required for Android install.'
        );
        // Every icon file referenced must actually exist.
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
    }

    public function test_service_worker_is_served_with_safe_caching_rules(): void
    {
        $this->get('/sw.js')
            ->assertOk()
            ->assertHeader('Service-Worker-Allowed', '/');

        $sw = file_get_contents(public_path('sw.js'));
        // Navigations must never be cached (no sensitive pages on disk) and the
        // offline fallback must be wired up.
        $this->assertStringContainsString("request.mode === 'navigate'", $sw);
        $this->assertStringContainsString('/offline.html', $sw);
    }

    public function test_offline_fallback_page_is_available(): void
    {
        $this->get('/offline.html')->assertOk();
        $this->assertStringContainsString("You're offline", file_get_contents(public_path('offline.html')));
    }

    public function test_app_layout_and_login_register_the_pwa(): void
    {
        // Login page (guests) carries the manifest + service worker registration.
        $this->get('/login')->assertOk()
            ->assertSee('manifest.webmanifest')
            ->assertSee('serviceWorker');

        // Authenticated app shell too.
        $admin = \App\Models\User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee('manifest.webmanifest')
            ->assertSee('apple-touch-icon');
    }
}
