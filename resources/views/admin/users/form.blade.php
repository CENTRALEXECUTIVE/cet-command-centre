@extends('layouts.app')
@section('title', $user->exists ? 'Edit user' : 'Add user')

@section('content')
    <p class="page-sub"><a href="{{ route('users.index') }}">← Users</a></p>
    <h1 class="page-title">{{ $user->exists ? 'Edit '.$user->name : 'Add user' }}</h1>

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
                    <div class="field"><label for="name">Full name <span class="req">*</span></label><input id="name" name="name" value="{{ old('name', $user->name) }}" required></div>
                    <div class="field"><label for="email">Email (login) <span class="req">*</span></label><input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required></div>
                    <div class="field"><label for="phone">Phone</label><input id="phone" name="phone" value="{{ old('phone', $user->phone) }}" placeholder="07…"></div>
                    <div class="field"><label for="password">{{ $user->exists ? 'Reset password' : 'Password' }}</label><input id="password" type="text" name="password" placeholder="{{ $user->exists ? 'Leave blank to keep' : 'Leave blank to auto-generate' }}" autocomplete="off"></div>
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
@endsection
