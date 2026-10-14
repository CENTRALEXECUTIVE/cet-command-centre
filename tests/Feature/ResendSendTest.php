<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Setting;
use App\Services\Mail\ResendClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PVT LTD can send over the Resend HTTPS API (the host blocks SMTP) with just an
 * API key — no Google Cloud. It's preferred over the Gmail/SMTP routes when set.
 */
class ResendSendTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_posts_the_message_to_resend(): void
    {
        Setting::set('mail_pvt_resend_key', 're_test_123', 'string', 'mail');
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'abc'], 200)]);

        $ok = app(ResendClient::class)->send(
            from: 'admin@centralexecutivetransferspvt.co.uk',
            to: ['customer@x.com'], subject: 'Hi', html: '<p>Body</p>',
        );

        $this->assertTrue($ok);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.resend.com/emails')
            && $r->hasHeader('Authorization', 'Bearer re_test_123')
            && data_get($r->data(), 'from') === 'admin@centralexecutivetransferspvt.co.uk'
            && data_get($r->data(), 'to.0') === 'customer@x.com');
    }

    public function test_it_surfaces_a_resend_error(): void
    {
        Setting::set('mail_pvt_resend_key', 're_test_123', 'string', 'mail');
        Http::fake(['api.resend.com/*' => Http::response(['message' => 'Domain is not verified'], 403)]);

        $client = app(ResendClient::class);
        $this->assertFalse($client->send('admin@pvt.co.uk', ['x@y.com'], 'Hi', '<p>x</p>'));
        $this->assertStringContainsString('Domain is not verified', (string) $client->lastError);
    }

    public function test_a_pvt_booking_prefers_resend(): void
    {
        Setting::set('mail_pvt_enabled', '1', 'string', 'mail');
        Setting::set('mail_pvt_resend_key', 're_test_123', 'string', 'mail');
        // Even with an SMTP host also set, Resend wins.
        Setting::set('mail_pvt_host', 'smtp.example.com', 'string', 'mail');
        $booking = Booking::factory()->create(['meta' => ['billing_entity' => 'chauffeurs']]);

        $this->assertSame('resend', $booking->pvtMailer());
        $this->assertSame('resend', (new \App\Mail\BookingConfirmationMail($booking))->mailer);
    }

    public function test_no_key_means_no_resend_route(): void
    {
        Setting::set('mail_pvt_enabled', '1', 'string', 'mail');
        $booking = Booking::factory()->create(['meta' => ['billing_entity' => 'chauffeurs']]);

        $this->assertNotSame('resend', (string) $booking->pvtMailer());
    }
}
