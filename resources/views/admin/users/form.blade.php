@extends('layouts.app')
@section('title', $user->exists ? 'Edit user' : 'Add user')

@section('content')
    <div class="form-hero">
        <div class="form-hero-glow"></div>
        <div class="fh-eyebrow"><a href="{{ route('users.index') }}" style="color:var(--gold)">← Users</a></div>
        <div class="fh-title">{{ $user->exists ? 'Edit '.$user->name : 'Add user' }}</div>
        <div class="fh-sub">Logins for the office, drivers and corporate clients.</div>
    </div>

    @if($errors->any())
        <div class="alert alert-error"><ul style="margin:0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="eto-form">
        @csrf
        @if($user->exists)@method('PUT')@endif
        <div class="eto-section">
            <div class="head"><span class="ico">👤</span> Account</div>
            <div class="body">
                <div class="grid grid-2">
                    <div class="field"><label for="name">Full name <span class="req">*</span></label><input id="name" name="name" value="{{ old('name', $user->name) }}" required>
                        <span class="hint">Their real name — used on booking reminders and anything the customer sees.</span></div>
                    <div class="field"><label for="nickname">Known as <span class="muted">(office only)</span></label>
                        <input id="nickname" name="nickname" value="{{ old('nickname', $user->nickname()) }}" placeholder="e.g. Hamza E Class">
                        <span class="hint">How you refer to them on the Command Centre so you know who it is. Never shown to customers.</span></div>
                    <div class="field"><label for="email">Email (login) <span class="req">*</span></label><input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required inputmode="email" autocapitalize="none" autocorrect="off" spellcheck="false"></div>
                    <div class="field"><label for="phone">Phone</label><input id="phone" name="phone" value="{{ old('phone', $user->phone) }}" placeholder="07…"></div>
                    <div class="field"><label for="password">{{ $user->exists ? 'Reset password' : 'Password' }}</label>
                        <div class="pw-wrap">
                            <input id="password" type="password" name="password" placeholder="{{ $user->exists ? 'Leave blank to keep' : 'Leave blank to auto-generate' }}" autocomplete="new-password">
                            <button type="button" class="pw-toggle" data-target="password" aria-label="Show password">Show</button>
                        </div>
                        @if($user->exists)
                            @if($user->must_change_password)
                                <div class="hint" style="color:#b8860b">⏳ A temporary password is set — they’ll choose their own the next time they sign in.</div>
                            @else
                                <div class="hint">🔒 They’ve set their own password — it’s hidden, even from you. Type a new one here to reset it (they’ll be asked to choose their own again).</div>
                            @endif
                        @endif
                    </div>
                </div>

                <div class="field">
                    <label for="role">Role <span class="req">*</span></label>
                    <select id="role" name="role" required>
                        <option value="driver" @selected(old('role', $user->role?->value ?? 'driver')==='driver')>Driver</option>
                        <option value="corporate_client" @selected(old('role', $user->role?->value)==='corporate_client')>Corporate client</option>
                        @if($isSuper)
                            <option value="admin" @selected(old('role', $user->role?->value)==='admin')>Administrator</option>
                        @endif
                    </select>
                    @unless($isSuper)<div class="hint">Only a super admin can assign the Administrator role.</div>@endunless
                </div>

                @if($isSuper)
                    <div class="checkbox-row" style="margin-bottom:6px">
                        <input id="is_super_admin" type="checkbox" name="is_super_admin" value="1" {{ old('is_super_admin', $user->is_super_admin) ? 'checked' : '' }}
                            {{ $user->exists && $user->id === auth()->id() ? 'disabled' : '' }}>
                        <label for="is_super_admin">Super admin — can manage all users</label>
                    </div>
                @endif

                @if($user->exists && $user->id !== auth()->id())
                    <div class="checkbox-row">
                        <input id="is_active" type="checkbox" name="is_active" value="1" {{ old('is_active', $user->is_active) ? 'checked' : '' }}>
                        <label for="is_active">Active — can log in</label>
                    </div>
                @endif
            </div>
        </div>

        <div class="toolbar">
            <button class="btn btn-primary">{{ $user->exists ? 'Save' : 'Create user' }}</button>
            <a href="{{ route('users.index') }}" class="btn btn-ghost">Cancel</a>
            @if($user->exists && $user->id !== auth()->id())
                <span style="flex:1"></span>
                <button type="submit" form="deactivate-user" class="btn btn-ghost" style="color:#b32020">Deactivate</button>
            @endif
        </div>
    </form>
    @if($user->exists && $user->id !== auth()->id())
        <form id="deactivate-user" method="POST" action="{{ route('users.destroy', $user) }}" onsubmit="return confirm('Deactivate {{ $user->name }}?')">@csrf @method('DELETE')</form>
    @endif
    <script src="{{ asset('js/cet-showpassword.js') }}?v={{ config('cet.asset_version') }}" defer></script>
@endsection
