<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\Push\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stores/removes a device's Web Push subscription for the signed-in user, so
 * their phone can be notified of new jobs even with the app closed.
 */
class PushSubscriptionController extends Controller
{
    /** The VAPID public key the browser needs to subscribe. */
    public function key(WebPushService $push): JsonResponse
    {
        return response()->json(['key' => $push->publicKey(), 'enabled' => $push->configured()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'max:16'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
                'last_used_at' => now(),
            ],
        );

        return response()->json(['subscribed' => true]);
    }

    /**
     * Fire a test notification to the signed-in user's own devices, so an admin
     * can confirm alerts actually reach their phone (and that they installed the
     * app / granted permission correctly). Rate-limited on the route.
     */
    public function test(Request $request, WebPushService $push): JsonResponse
    {
        if (! $push->configured()) {
            return response()->json(['ok' => false, 'reason' => 'not_configured'], 422);
        }

        $res = $push->sendToUserReport(
            $request->user(),
            'CET test alert',
            'Notifications are working on this device 🎉',
            ['url' => route('dashboard'), 'tag' => 'cet-test'],
        );

        $sent = collect($res['reports'])->where('ok', true)->count();

        return response()->json([
            'ok' => $sent > 0,
            'devices' => $sent,
            'subscriptions' => $res['subscriptions'],
            'reports' => $res['reports'],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint');
        if ($endpoint !== '') {
            PushSubscription::where('endpoint_hash', hash('sha256', $endpoint))
                ->where('user_id', $request->user()->id)
                ->delete();
        }

        return response()->json(['subscribed' => false]);
    }
}
