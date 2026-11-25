<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Lets a signed-in user set their own password — used by the forced first-login
 * flow (must_change_password), and available to anyone who wants to change it.
 */
class PasswordController extends Controller
{
    public function edit(Request $request): View
    {
        return view('auth.change-password', [
            'forced' => (bool) $request->user()->must_change_password,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $forced = (bool) ($user->must_change_password ?? false);

        // Your CURRENT password is needed to change it (QA 25 Nov 2026: a phone left
        // signed in could have its password changed by anyone). The forced first-login
        // change is exempt: they've only just signed in with it.
        $data = $request->validate(array_filter([
            'current_password' => $forced ? null : ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]), [
            'current_password.current_password' => 'That isn’t your current password.',
            'current_password.required' => 'Please enter your current password.',
        ]);

        $user->password = $data['password'];
        if (Schema::hasColumn('users', 'must_change_password')) {
            $user->must_change_password = false;
        }
        $user->save();

        // Sign out every OTHER phone or computer on this account (a lost phone stays
        // signed in otherwise). This one stays signed in.
        if (Schema::hasTable(config('session.table', 'sessions'))) {
            \Illuminate\Support\Facades\DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Password updated — you’re all set.');
    }
}
