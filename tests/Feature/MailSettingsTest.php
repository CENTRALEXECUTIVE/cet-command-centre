<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Email can be switched on from the Settings page (stored in the DB like the
 * Square/Stripe keys) — the operator never has to touch the server .env.
 */
class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['is_super_admin' => true]);
    }

    public function test_saving_smtp_settings_switches_the_mailer_to_smtp(): void
    {
        $this->actingAs($this->admin())->put(route('settings.update'), [
            'mail_host' => 'mail.example.co.uk',
            'mail_port' => '465',
            'mail_scheme' => 'smtps',
            'mail_username' => 'admin@example.co.uk',
            'mail_password' => 'secret-pass',
            'mail_from_address' => 'admin@example.co.uk',
            'mail_from_name' => 'CET',
        ])->assertRedirect();

        $this->assertSame('mail.example.co.uk', Setting::get('mail_host'));
        $this->assertSame('secret-pass', Setting::get('mail_password'));

        // Applying the DB settings (as AppServiceProvider does at boot) flips the
        // live mailer to SMTP with the saved host/credentials.
        MailSettings::apply();
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('mail.example.co.uk', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('admin@example.co.uk', config('mail.mailers.smtp.username'));
        $this->assertSame('admin@example.co.uk', config('mail.from.address'));
    }

    public function test_a_blank_password_keeps_the_saved_one(): void
    {
        Setting::set('mail_password', 'keepme', 'string', 'mail');

        $this->actingAs($this->admin())->put(route('settings.update'), [
            'mail_host' => 'mail.example.co.uk', 'mail_username' => 'a@b.co', 'mail_password' => '',
        ])->assertRedirect();

        $this->assertSame('keepme', Setting::get('mail_password'));
    }

    public function test_saving_the_email_form_does_not_wipe_other_settings(): void
    {
        Setting::set('google_maps_key', 'MAPSKEY123', 'string', 'integrations');

        // The Email form posts only mail_* fields — it must not clear the maps key.
        $this->actingAs($this->admin())->put(route('settings.update'), [
            'mail_host' => 'mail.example.co.uk',
        ])->assertRedirect();

        $this->assertSame('MAPSKEY123', Setting::get('google_maps_key'));
    }

    public function test_no_host_leaves_the_default_mailer_untouched(): void
    {
        config(['mail.default' => 'log']);
        MailSettings::apply(); // nothing saved
        $this->assertSame('log', config('mail.default'));
    }

    public function test_test_email_returns_a_clear_json_result_when_still_on_log(): void
    {
        config(['mail.default' => 'log']);

        $this->actingAs($this->admin())
            ->postJson(route('settings.test-email'), ['to' => 'me@example.com'])
            ->assertOk()
            ->assertJson(['ok' => false]);
    }

    public function test_localhost_host_disables_peer_verification(): void
    {
        Setting::set('mail_host', 'localhost', 'string', 'mail');
        MailSettings::apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('localhost', config('mail.mailers.smtp.host'));
        // Self-signed/mismatched cert on the same box must not block sending.
        $this->assertSame(0, config('mail.mailers.smtp.verify_peer'));
    }

    public function test_a_real_host_keeps_peer_verification(): void
    {
        Setting::set('mail_host', 'mail.example.co.uk', 'string', 'mail');
        MailSettings::apply();

        $this->assertNull(config('mail.mailers.smtp.verify_peer'));
    }
}
