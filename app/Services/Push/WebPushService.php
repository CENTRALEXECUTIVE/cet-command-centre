<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sends Web Push notifications to drivers' phones (free — no Apple/Google fees).
 * A no-op until VAPID keys are configured (config/webpush), so nothing breaks
 * before the operator has run `cet:make-vapid`. Dead subscriptions (410/404)
 * are pruned automatically.
 */
class WebPushService
{
    public function configured(): bool
    {
        return filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }

    /** The public key the browser needs to subscribe (safe to expose). */
    public function publicKey(): ?string
    {
        return config('webpush.vapid.public_key');
    }

    /**
     * Send a notification to every device a user has enabled. Returns the number
     * of devices successfully pushed to. Silent no-op when unconfigured.
     *
     * @param  array{url?: string, tag?: string}  $options
     */
    public function sendToUser(User $user, string $title, string $body, array $options = []): int
    {
        if (! $this->configured()) {
            return 0;
        }

        $subs = $user->pushSubscriptions()->get();
        if ($subs->isEmpty()) {
            return 0;
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ]]);
        } catch (\Throwable $e) {
            Log::warning('WebPush init failed', ['error' => $e->getMessage()]);

            return 0;
        }

        $payload = json_encode(array_filter([
            'title' => $title,
            'body' => $body,
            'url' => $options['url'] ?? route('driver.jobs'),
            'tag' => $options['tag'] ?? null,
        ]));

        foreach ($subs as $sub) {
            $webPush->queueNotification($this->toSubscription($sub), $payload);
        }

        $sent = 0;
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;

                continue;
            }
            // 404/410 → the subscription is dead; remove it so we stop trying.
            if (in_array($report->getResponse()?->getStatusCode(), [404, 410], true)) {
                PushSubscription::where('endpoint_hash', hash('sha256', $report->getEndpoint()))->delete();
            } else {
                Log::info('WebPush delivery failed', ['reason' => $report->getReason()]);
            }
        }

        $user->pushSubscriptions()->update(['last_used_at' => now()]);

        return $sent;
    }

    /**
     * Like sendToUser but returns the raw per-device delivery reports (HTTP
     * status + reason from the push service) so the Notifications page can show
     * exactly what Google/Mozilla said. For diagnostics only.
     *
     * @return array<string, mixed>
     */
    public function sendToUserReport(User $user, string $title, string $body, array $options = []): array
    {
        if (! $this->configured()) {
            return ['configured' => false, 'subscriptions' => 0, 'reports' => []];
        }

        $subs = $user->pushSubscriptions()->get();
        if ($subs->isEmpty()) {
            return ['configured' => true, 'subscriptions' => 0, 'reports' => []];
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ]]);
        } catch (\Throwable $e) {
            return ['configured' => true, 'subscriptions' => $subs->count(), 'reports' => [['ok' => false, 'reason' => 'init: '.$e->getMessage()]]];
        }

        $payload = json_encode(array_filter([
            'title' => $title, 'body' => $body,
            'url' => $options['url'] ?? route('driver.jobs'), 'tag' => $options['tag'] ?? null,
        ]));

        foreach ($subs as $sub) {
            $webPush->queueNotification($this->toSubscription($sub), $payload);
        }

        $reports = [];
        foreach ($webPush->flush() as $report) {
            $reports[] = [
                'ok' => $report->isSuccess(),
                'status' => $report->getResponse()?->getStatusCode(),
                'reason' => $report->isSuccess() ? 'accepted' : ($report->getReason() ?: 'unknown'),
            ];
        }

        return ['configured' => true, 'subscriptions' => $subs->count(), 'reports' => $reports];
    }

    private function toSubscription(PushSubscription $sub): Subscription
    {
        return Subscription::create([
            'endpoint' => $sub->endpoint,
            'publicKey' => $sub->public_key,
            'authToken' => $sub->auth_token,
            'contentEncoding' => $sub->content_encoding,
        ]);
    }
}
