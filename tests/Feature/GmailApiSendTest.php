<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Setting;
use App\Services\Mail\GmailApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PVT LTD sends over the Gmail API (HTTPS) because the host blocks SMTP. A Google
 * service account (domain-wide delegation) signs a JWT to send AS the PVT mailbox.
 */
class GmailApiSendTest extends TestCase
{
    use RefreshDatabase;

    private function fakeServiceAccount(): void
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);

        config([
            'services.google_gmail.credentials' => json_encode([
                'client_email' => 'cet-sa@proj.iam.gserviceaccount.com',
                'private_key' => $privateKey,
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ]),
            'cet.company_novat.email' => 'admin@centralexecutivetransferspvt.co.uk',
        ]);
    }

    public function test_it_sends_a_raw_message_as_the_pvt_mailbox(): void
    {
        $this->fakeServiceAccount();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'gmail.googleapis.com/*' => Http::response(['id' => 'msg1'], 200),
        ]);

        $ok = app(GmailApiClient::class)->sendRaw('admin@centralexecutivetransferspvt.co.uk', "Subject: Hi\r\n\r\nBody");

        $this->assertTrue($ok);
        // The send hit the PVT mailbox's Gmail endpoint with a base64url body, and
        // the token was requested with a signed JWT assertion.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'gmail.googleapis.com')
            && str_contains($r->url(), rawurlencode('admin@centralexecutivetransferspvt.co.uk').'/messages/send')
            && filled(data_get($r->data(), 'raw')));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com')
            && ($r['grant_type'] ?? null) === 'urn:ietf:params:oauth:grant-type:jwt-bearer');
    }

    public function test_it_surfaces_an_unauthorized_client_error(): void
    {
        $this->fakeServiceAccount();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['error' => 'unauthorized_client'], 401),
        ]);

        $client = app(GmailApiClient::class);
        $this->assertFalse($client->sendRaw('admin@centralexecutivetransferspvt.co.uk', 'x'));
        $this->assertStringContainsString('Domain-wide delegation', (string) $client->lastError);
    }

    public function test_a_pvt_booking_routes_to_the_gmail_mailer_when_set_up(): void
    {
        $this->fakeServiceAccount();
        Setting::set('mail_pvt_enabled', '1', 'string', 'mail');
        $booking = Booking::factory()->create(['meta' => ['billing_entity' => 'chauffeurs']]);

        $this->assertSame('gmail', $booking->pvtMailer());
        $this->assertSame('gmail', (new \App\Mail\BookingConfirmationMail($booking))->mailer);
    }

    public function test_pvt_falls_back_to_smtp_when_there_is_no_gmail_key(): void
    {
        // No Gmail credentials, but an SMTP host is set → use the SMTP 'pvt' mailer.
        config(['services.google_gmail.credentials' => null]);
        Setting::set('mail_pvt_enabled', '1', 'string', 'mail');
        Setting::set('mail_pvt_host', 'smtp.example.com', 'string', 'mail');
        $booking = Booking::factory()->create(['meta' => ['billing_entity' => 'chauffeurs']]);

        $this->assertSame('pvt', $booking->pvtMailer());
    }

    public function test_a_vat_booking_never_uses_the_pvt_mailer(): void
    {
        $this->fakeServiceAccount();
        Setting::set('mail_pvt_enabled', '1', 'string', 'mail');
        $booking = Booking::factory()->create(['meta' => ['billing_entity' => 'transfers']]);

        $this->assertNull($booking->pvtMailer());
    }
}
