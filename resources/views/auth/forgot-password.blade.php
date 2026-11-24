<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <title>Forgot password &middot; CET Command Centre</title>
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
            <form method="POST" action="{{ route('password.email') }}">
                @csrf
                <div class="field">
                    <label for="email">Your Command Centre email</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false">
                </div>
                <button type="submit" class="btn btn-primary btn-block">Email me a reset link</button>
            </form>
            <p class="hint" style="text-align:center;margin-top:16px">The link goes to your account email and, for directors, the office inbox (admin@).</p>
            <p class="hint" style="text-align:center"><a href="{{ route('login') }}">&larr; Back to sign in</a></p>
        </div>
    </div>
    @include('partials.cookie-consent')
    <script src="{{ asset('js/cet-showpassword.js') }}?v={{ config('cet.asset_version') }}" defer></script>
</body>
</html>
