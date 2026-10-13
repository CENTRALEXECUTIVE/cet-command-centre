<?php

namespace App\Services\Mail;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Sends email through the Gmail API over HTTPS — the PVT LTD company's mailbox is
 * on Google Workspace, and the host blocks outbound SMTP, so (exactly like the
 * main company sends via the Microsoft Graph API) PVT sends via Google's API.
 *
 * Auth is a Google SERVICE ACCOUNT with domain-wide delegation: it signs a JWT
 * (RS256, no extra packages — same approach as GoogleCalendarService) with
 * `sub` set to the PVT mailbox, so Google issues a token that can send AS that
 * mailbox. The service account's client id must be authorised in the PVT
 * Workspace admin console for the gmail.send scope, with the Gmail API enabled.
 *
 * Silent no-op (returns false) until credentials + the PVT address are set.
 */
class GmailApiClient
{
    /** The last Google error from a failed send, surfaced on the test. */
    public ?string $lastError = null;

    /** Set up when we have a service-account JSON and a mailbox to send as. */
    public function configured(): bool
    {
        return $this->credentials() !== null && filled($this->sendAs());
    }

    /** The PVT mailbox we send as (and impersonate via domain-wide delegation). */
    public function sendAs(): string
    {
        return (string) (Setting::get('mail_pvt_from_address') ?: config('cet.company_novat.email'));
    }

    /**
     * Send a fully-built RFC822 message. $from must be the authorised mailbox
     * (the PVT address). Returns true on success; sets lastError on failure.
     */
    public function sendRaw(string $from, string $rfc822): bool
    {
        $this->lastError = null;
        if (! $this->configured()) {
            $this->lastError = 'Gmail API not configured (service-account key or PVT address missing).';

            return false;
        }

        $token = $this->accessToken($from);
        if (! $token) {
            return false; // lastError already set
        }

        try {
            $response = Http::withToken($token)->post(
                'https://gmail.googleapis.com/gmail/v1/users/'.rawurlencode($from).'/messages/send',
                ['raw' => $this->base64Url($rfc822)],
            );
        } catch (\Throwable $e) {
            $this->lastError = 'Network error reaching Gmail: '.$e->getMessage();

            return false;
        }

        if ($response->successful()) {
            return true;
        }

        $gErr = trim((string) (data_get($response->json(), 'error.status').': '.data_get($response->json(), 'error.message')));
        $this->lastError = 'HTTP '.$response->status().' sending as '.$from
            .($gErr !== ':' ? ' — '.$gErr : ' — '.substr($response->body(), 0, 200));

        return false;
    }

    /**
     * An access token that can send as $sub, via a signed service-account JWT.
     * Cached ~50 min per mailbox.
     */
    private function accessToken(string $sub): ?string
    {
        return \Illuminate\Support\Facades\Cache::remember(
            'gmail_api_token_'.md5($sub), now()->addMinutes(50), fn () => $this->requestToken($sub),
        );
    }

    private function requestToken(string $sub): ?string
    {
        $creds = $this->credentials();
        if (! $creds || empty($creds['client_email']) || empty($creds['private_key'])) {
            $this->lastError = 'Service-account key is not valid JSON, or is missing client_email/private_key.';

            return null;
        }

        $tokenUri = $creds['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now = time();
        $claims = [
            'iss' => $creds['client_email'],
            'sub' => $sub, // impersonate the PVT mailbox (domain-wide delegation)
            'scope' => 'https://www.googleapis.com/auth/gmail.send',
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $signingInput = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            .'.'.$this->base64Url((string) json_encode($claims));

        $signature = '';
        if (! openssl_sign($signingInput, $signature, $creds['private_key'], 'sha256WithRSAEncryption')) {
            $this->lastError = 'Could not sign the token (the private key in the JSON is invalid).';

            return null;
        }
        $jwt = $signingInput.'.'.$this->base64Url($signature);

        try {
            $response = Http::asForm()->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
        } catch (\Throwable $e) {
            $this->lastError = 'Network error reaching Google: '.$e->getMessage();

            return null;
        }

        if ($response->successful() && $response->json('access_token')) {
            return $response->json('access_token');
        }

        $err = (string) ($response->json('error') ?: $response->status());
        $desc = (string) $response->json('error_description');
        // unauthorized_client almost always = the service account isn't authorised
        // for gmail.send in the PVT Workspace's domain-wide delegation.
        $hint = str_contains($err.$desc, 'unauthorized_client')
            ? ' — authorise this service account for the gmail.send scope in the PVT Workspace (Admin → Security → API controls → Domain-wide delegation).'
            : (str_contains($err.$desc, 'invalid_grant') ? ' — check the server clock, and that the PVT address is a real Workspace mailbox.' : '');
        $this->lastError = "Google rejected the token request (HTTP {$response->status()}: {$err} {$desc}){$hint}";

        return null;
    }

    /** @return array<string, mixed>|null */
    private function credentials(): ?array
    {
        $value = config('services.google_gmail.credentials');
        if (blank($value)) {
            return null;
        }
        $json = (is_string($value) && is_readable($value)) ? file_get_contents($value) : $value;
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
