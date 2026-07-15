<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <title>Set your password &middot; CET Command Centre</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('cet.asset_version') }}">
    @include('partials.pwa')
</head>
<body>
    <div class="auth-wrap">
        <div class="auth-aura"></div>
        <div class="auth-card">
            <div class="auth-brand">
                <div class="auth-logo"><span class="dot"></span></div>
                <div class="mark">CENTRAL <span class="gold">EXECUTIVE</span></div>
                <div class="sub">{{ $forced ? 'Set your own password to continue' : 'Change your password' }}</div>
            </div>

            @if($errors->any())
                <div class="alert alert-error">{{ $errors->first() }}</div>
            @endif
            @if($forced)
                <p class="hint" style="text-align:center;margin:0 0 18px">Welcome. For security, please choose your own password before you carry on.</p>
            @endif

            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                @method('PUT')
                <div class="field">
                    <label for="password">New password</label>
                    <div class="pw-wrap">
                        <input id="password" type="password" name="password" required autocomplete="new-password" minlength="8">
                        <button type="button" class="pw-toggle" data-target="password" aria-label="Show password">Show</button>
                    </div>
                    <div class="hint">At least 8 characters.</div>
                </div>
                <div class="field">
                    <label for="password_confirmation">Confirm new password</label>
                    <div class="pw-wrap">
                        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                        <button type="button" class="pw-toggle" data-target="password_confirmation" aria-label="Show password">Show</button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Save password</button>
            </form>

            <form method="POST" action="{{ route('logout') }}" style="margin-top:16px;text-align:center">
                @csrf
                <button type="submit" class="btn btn-ghost" style="font-size:13px">Sign out</button>
            </form>
        </div>
    </div>
    @include('partials.cookie-consent')
    <script src="{{ asset('js/cet-showpassword.js') }}?v={{ config('cet.asset_version') }}" defer></script>
</body>
</html>
