<?php

namespace App\Services\Inbox;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Microsoft Graph mail client for the bookings mailbox. Reads unread messages
 * and marks them read. Unconfigured → returns nothing so ingestion is a no-op.
 */
class GraphMailClient
{
    public function configured(): bool
    {
        return filled(config('services.microsoft_graph.client_id'))
            && filled(config('services.microsoft_graph.client_secret'))
            && filled(config('services.microsoft_graph.tenant_id'));
    }

    /**
     * @return array<int, array{id:string, subject:string, body:string, from:?string}>
     */
    public function fetchUnread(int $limit = 25): array
    {
        if (! $this->configured()) {
            return [];
        }

        try {
            $token = $this->accessToken();
            if (! $token) {
                return [];
            }

            $mailbox = rawurlencode(config('services.microsoft_graph.mailbox'));
            $response = Http::withToken($token)->get(
                "https://graph.microsoft.com/v1.0/users/{$mailbox}/mailFolders/inbox/messages",
                ['$filter' => 'isRead eq false', '$top' => $limit, '$select' => 'id,subject,body,from']
            );

            if (! $response->successful()) {
                Log::warning('Graph fetchUnread HTTP error', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);

                return [];
            }

            return collect($response->json('value', []))->map(fn ($m) => [
                'id' => $m['id'],
                'subject' => $m['subject'] ?? '',
                'body' => strip_tags($m['body']['content'] ?? ''),
                'from' => $m['from']['emailAddress']['address'] ?? null,
            ])->all();
        } catch (\Throwable $e) {
            Log::warning('Graph fetchUnread failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function markRead(string $messageId): void
    {
        if (! $this->configured()) {
            return;
        }
        try {
            $token = $this->accessToken();
            $mailbox = rawurlencode(config('services.microsoft_graph.mailbox'));
            Http::withToken($token)->patch(
                "https://graph.microsoft.com/v1.0/users/{$mailbox}/messages/{$messageId}",
                ['isRead' => true]
            );
        } catch (\Throwable $e) {
            Log::warning('Graph markRead failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Step-by-step connectivity check used by `cet:test-graph`. Never reveals the
     * secret — only whether each step works and the error if it doesn't.
     *
     * @return array<string, mixed>
     */
    public function diagnose(): array
    {
        $out = [
            'configured' => $this->configured(),
            'client_id' => filled(config('services.microsoft_graph.client_id')),
            'tenant_id' => filled(config('services.microsoft_graph.tenant_id')),
            'client_secret' => filled(config('services.microsoft_graph.client_secret')),
            'mailbox' => config('services.microsoft_graph.mailbox'),
        ];

        if (! $out['configured']) {
            return $out + ['result' => 'Not configured — fill the MS_GRAPH_* values in .env.'];
        }

        $tenant = config('services.microsoft_graph.tenant_id');
        $tokenResponse = Http::asForm()->post("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
            'client_id' => config('services.microsoft_graph.client_id'),
            'client_secret' => config('services.microsoft_graph.client_secret'),
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);

        $out['token_ok'] = $tokenResponse->successful();
        if (! $tokenResponse->successful()) {
            return $out + [
                'token_error' => $tokenResponse->json('error_description', $tokenResponse->body()),
                'result' => 'Token failed — usually a wrong/expired client secret or tenant id.',
            ];
        }

        $mailbox = rawurlencode((string) config('services.microsoft_graph.mailbox'));
        $mailResponse = Http::withToken($tokenResponse->json('access_token'))->get(
            "https://graph.microsoft.com/v1.0/users/{$mailbox}/mailFolders/inbox/messages",
            ['$filter' => 'isRead eq false', '$top' => 5, '$select' => 'id,subject']
        );

        $out['mail_http_status'] = $mailResponse->status();
        if (! $mailResponse->successful()) {
            return $out + [
                'mail_error' => substr($mailResponse->body(), 0, 400),
                'result' => 'Connected, but reading the mailbox failed — usually missing Mail.ReadWrite admin consent or wrong mailbox address.',
            ];
        }

        $messages = collect($mailResponse->json('value', []));

        return $out + [
            'unread_count' => $messages->count(),
            'unread_subjects' => $messages->pluck('subject')->all(),
            'result' => $messages->isEmpty()
                ? 'Connected OK — but there are no UNREAD emails in the inbox right now.'
                : 'Connected OK — found unread emails ready to process.',
        ];
    }

    /**
     * Client-credentials token for Graph. Returns null until credentials supplied.
     */
    protected function accessToken(): ?string
    {
        $tenant = config('services.microsoft_graph.tenant_id');
        try {
            $response = Http::asForm()->post("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
                'client_id' => config('services.microsoft_graph.client_id'),
                'client_secret' => config('services.microsoft_graph.client_secret'),
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]);

            return $response->successful() ? $response->json('access_token') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
