<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "View as driver" — an admin can see the app exactly as a driver (or corporate
 * client) does, for support. The original admin id is kept in the session so they
 * can jump straight back. Admins can't impersonate other admins.
 */
class ImpersonationController extends Controller
{
    public const KEY = 'impersonator_id';

    public function start(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_if($user->isAdmin(), 403, 'You can’t view as another admin.');
        abort_if($user->id === $request->user()->id, 400);

        $request->session()->put(self::KEY, $request->user()->id);
        Auth::login($user);

        return redirect('/')->with('status', "You’re now viewing as {$user->name}.");
    }

    public function stop(Request $request): RedirectResponse
    {
        $originalId = $request->session()->pull(self::KEY);
        if (! $originalId || ! ($original = User::find($originalId))) {
            return redirect('/');
        }

        Auth::login($original);

        return redirect()->route('users.index')->with('status', 'Back to your own account.');
    }
}
