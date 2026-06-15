<?php

namespace App\Http\Controllers;

use App\Models\Consent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Records cookie-consent choices from the public banner (GDPR/PECR). The choice
 * is also stored client-side in a cookie so the banner only shows once.
 */
class ConsentController extends Controller
{
    public function cookies(Request $request): JsonResponse
    {
        $data = $request->validate([
            'granted' => ['required', 'boolean'],
        ]);

        Consent::create([
            'subject_type' => 'visitor',
            'email' => null,
            'type' => 'cookies',
            'granted' => (bool) $data['granted'],
            'version' => config('cet.privacy_policy_version', '1.0'),
            'ip_address' => $request->ip(),
            'granted_at' => $data['granted'] ? now() : null,
        ]);

        return response()->json(['ok' => true]);
    }
}
