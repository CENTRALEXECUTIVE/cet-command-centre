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
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();
        $user->password = $data['password'];
        if (Schema::hasColumn('users', 'must_change_password')) {
            $user->must_change_password = false;
        }
        $user->save();

        return redirect()->route('dashboard')->with('status', 'Password updated — you’re all set.');
    }
}
