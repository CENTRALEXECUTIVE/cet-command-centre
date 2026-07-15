<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * User management (admins). Admins can create drivers and corporate logins; only
 * a SUPER ADMIN can create or edit other admins / grant super-admin. A user can't
 * strip their own super-admin or deactivate themselves.
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.users.index', [
            'users' => User::orderByDesc('is_super_admin')->orderBy('role')->orderBy('name')->paginate(30),
            'isSuper' => $request->user()->isSuperAdmin(),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.users.form', ['user' => new User, 'isSuper' => $request->user()->isSuperAdmin()]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $this->validated($request);
        $this->guardRole($request, $data['role'], (bool) ($data['is_super_admin'] ?? false));

        $password = ($data['password'] ?? null) ?: Str::password(12, symbols: false);
        $user = User::create([
            'name' => $data['name'],
            'email' => Str::lower(trim($data['email'])),
            'phone' => $data['phone'] ?? null,
            'password' => $password,
            'role' => $data['role'],
            'is_super_admin' => $request->user()->isSuperAdmin() ? (bool) ($data['is_super_admin'] ?? false) : false,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->forcePasswordChange($user); // set their own on first sign-in

        return redirect()->route('users.index')
            ->with('new_credentials', $this->credentialCard($user, $password));
    }

    public function edit(Request $request, User $user): View
    {
        abort_unless($request->user()->isAdmin(), 403);
        $this->guardTarget($request, $user);

        return view('admin.users.form', ['user' => $user, 'isSuper' => $request->user()->isSuperAdmin()]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $this->guardTarget($request, $user);

        $data = $this->validated($request, $user);
        $this->guardRole($request, $data['role'], (bool) ($data['is_super_admin'] ?? false));

        $user->fill([
            'name' => $data['name'],
            'email' => Str::lower(trim($data['email'])),
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'],
            'is_active' => $user->id === $request->user()->id ? true : (bool) ($data['is_active'] ?? false),
        ]);
        // Only a super admin can change the super-admin flag, and never on themselves.
        if ($request->user()->isSuperAdmin() && $user->id !== $request->user()->id) {
            $user->is_super_admin = (bool) ($data['is_super_admin'] ?? false);
        }
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        // An admin resetting SOMEONE ELSE'S password → they must pick their own
        // on next sign-in. Resetting your own password doesn't force a change.
        if (! empty($data['password']) && $user->id !== $request->user()->id) {
            $this->forcePasswordChange($user);
        }

        // When the password was reset, hand back a copyable credentials card so
        // the admin can share the exact login (and knows the reset actually took).
        if (! empty($data['password'])) {
            return redirect()->route('users.index')
                ->with('new_credentials', $this->credentialCard($user, $data['password']));
        }

        return redirect()->route('users.index')->with('status', "{$user->name} updated.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $this->guardTarget($request, $user);
        abort_if($user->id === $request->user()->id, 403, 'You can’t remove your own account.');

        $user->update(['is_active' => false]);

        return redirect()->route('users.index')->with('status', "{$user->name} deactivated.");
    }

    /**
     * Flag a user to set their own password on next sign-in. Crash-safe: does
     * nothing until the migration adding the column has run.
     */
    private function forcePasswordChange(User $user): void
    {
        if (Schema::hasColumn('users', 'must_change_password')) {
            $user->forceFill(['must_change_password' => true])->save();
        }
    }

    /**
     * A one-time credentials card for the users page: the exact login to share,
     * plus a wa.me deep link so the admin can send it to the driver by hand
     * (still a manual tap — nothing auto-sends).
     *
     * @return array<string, ?string>
     */
    private function credentialCard(User $user, string $password): array
    {
        $text = "Your Central Executive Command Centre login:\n"
            ."Web: ".route('login')."\n"
            ."Email: {$user->email}\n"
            ."Password: {$password}\n\n"
            ."Please sign in and set your own password.";

        $wa = null;
        $digits = preg_replace('/\D/', '', (string) $user->phone);
        if ($digits) {
            if (str_starts_with($digits, '0')) {
                $digits = '44'.substr($digits, 1);
            }
            $wa = 'https://wa.me/'.$digits.'?text='.rawurlencode($text);
        }

        return [
            'name' => $user->name,
            'email' => $user->email,
            'password' => $password,
            'share_text' => $text,
            'wa_link' => $wa,
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => [$user ? 'nullable' : 'nullable', 'string', 'min:8', 'max:72'],
            'role' => ['required', Rule::in(UserRole::values())],
            'is_super_admin' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /** Only a super admin may create/manage admin or super-admin accounts. */
    private function guardRole(Request $request, string $role, bool $super): void
    {
        if (($role === UserRole::Admin->value || $super) && ! $request->user()->isSuperAdmin()) {
            abort(403, 'Only a super admin can create or manage admin accounts.');
        }
    }

    /** A non-super admin can't edit an admin/super-admin account. */
    private function guardTarget(Request $request, User $user): void
    {
        if (($user->isAdmin() || $user->is_super_admin) && ! $request->user()->isSuperAdmin()) {
            abort(403, 'Only a super admin can manage admin accounts.');
        }
    }
}
