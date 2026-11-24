<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\StaffPasswordResetMail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * "Forgot password?" for Command Centre staff (directors, 24 Nov 2026: Abdi was
 * locked out and Maj couldn't be reached). Emails a one-hour reset link to the
 * account's own address AND, for admin accounts, to the office recovery inbox
 * (cet.recovery_email, default admin@), because the director logins (abdi@/maj@)
 * aren't real mailboxes. The answer never reveals whether an account exists.
 */
class StaffPasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', strtolower(trim($data['email'])))->where('is_active', true)->first();

        if ($user) {
            $token = Password::broker()->createToken($user);
            $link = route('password.reset', ['token' => $token, 'email' => $user->email]);
            $to = array_values(array_unique(array_filter([
                $user->email,
                $user->isAdmin() ? config('cet.recovery_email') : null,
            ])));
            foreach ($to as $address) {
                try {
                    Mail::to($address)->send(new StaffPasswordResetMail($user, $link));
                } catch (\Throwable $e) {
                    Log::warning('Staff password reset email failed', ['to' => $address, 'error' => $e->getMessage()]);
                }
            }
        }

        return back()->with('status', 'If that account exists, a reset link is on its way. Check the account’s email and the office inbox (admin@). The link works for one hour.');
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);
        $user = User::where('email', strtolower(trim($data['email'])))->first();
        $broker = Password::broker();

        if (! $user || ! $broker->tokenExists($user, $data['token'])) {
            return back()->withErrors(['email' => 'That reset link is invalid or has expired. Ask for a new one.']);
        }
        $user->forceFill(['password' => $data['password']])->save();
        $broker->deleteToken($user);

        return redirect()->route('login')->with('status', 'Password changed. Sign in with your new password.');
    }
}
