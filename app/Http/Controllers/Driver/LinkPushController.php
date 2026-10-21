<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PushSubscription;
use App\Services\Push\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Web Push opt-in for a COVER driver working a job through a shareable driver
 * LINK (no login). The URL token is the auth; the subscription is stored against
 * the driver the token's booking is allocated to, so the office's "new job" and
 * set-off/flight nudges reach their phone exactly like an account driver's.
 */
class LinkPushController extends Controller
{
    /** The VAPID public key the browser needs to subscribe (no token needed). */
    public function key(WebPushService $push): JsonResponse
    {
        return response()->json(['key' => $push->publicKey(), 'enabled' => $push->configured()]);
    }

    public function subscribe(Request $request, string $token): JsonResponse
    {
        $booking = Booking::byDriverLinkToken($token);
        if (! $booking || ! $booking->driver_id) {
            // Unknown/expired token, or a job with no driver to attach it to.
            return response()->json(['subscribed' => false], 404);
        }

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'max:16'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'user_id' => $booking->driver_id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
                'last_used_at' => now(),
            ],
        );

        return response()->json(['subscribed' => true]);
    }
}
