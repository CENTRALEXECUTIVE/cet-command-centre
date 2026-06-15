<?php

namespace Tests\Feature;

use App\Models\Consent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CookieConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepting_cookies_records_consent(): void
    {
        $this->postJson(route('consent.cookies'), ['granted' => 1])->assertOk()->assertJson(['ok' => true]);

        $consent = Consent::where('type', 'cookies')->first();
        $this->assertNotNull($consent);
        $this->assertTrue((bool) $consent->granted);
        $this->assertNotNull($consent->granted_at);
    }

    public function test_rejecting_cookies_records_consent_without_grant(): void
    {
        $this->postJson(route('consent.cookies'), ['granted' => 0])->assertOk();

        $consent = Consent::where('type', 'cookies')->first();
        $this->assertFalse((bool) $consent->granted);
        $this->assertNull($consent->granted_at);
    }

    public function test_login_page_shows_the_cookie_banner(): void
    {
        $this->get('/login')->assertOk()->assertSee('essential cookies', false);
    }
}
