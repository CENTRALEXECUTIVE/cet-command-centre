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
