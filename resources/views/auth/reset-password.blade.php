<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <title>Set a new password &middot; CET Command Centre</title>
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
                <div class="sub">Command Centre &middot; Secure sign in</div>
            </div>

            @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif
            <form method="POST" action="{{ route('password.reset.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="username">
                </div>
                <div class="field">
                    <label for="password">New password (at least 8 characters)</label>
                    <div class="pw-wrap"><input id="password" type="password" name="password" required autocomplete="new-password"><button type="button" class="pw-toggle" data-target="password" aria-label="Show password">Show</button></div>
                </div>
                <div class="field">
                    <label for="password_confirmation">Type it again</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary btn-block">Save new password</button>
            </form>
        </div>
    </div>
    @include('partials.cookie-consent')
    <script src="{{ asset('js/cet-showpassword.js') }}?v={{ config('cet.asset_version') }}" defer></script>
</body>
</html>
