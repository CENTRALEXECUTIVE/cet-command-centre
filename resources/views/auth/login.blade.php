<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in &middot; CET Command Centre</title>
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

            @if($errors->any())
                <div class="alert alert-error">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('login') }}">
                @csrf
                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}"
                           required autofocus autocomplete="username"
                           inputmode="email" autocapitalize="none" autocorrect="off" spellcheck="false">
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <div class="pw-wrap">
                        <input id="password" type="password" name="password" required autocomplete="current-password">
                        <button type="button" class="pw-toggle" data-target="password" aria-label="Show password">Show</button>
                    </div>
                </div>
                <div class="checkbox-row" style="margin-bottom:20px">
                    <input id="remember" type="checkbox" name="remember">
                    <label for="remember">Remember me on this device</label>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Sign in</button>
            </form>

            <p class="hint" style="text-align:center;margin-top:20px">
                Accounts are issued by CET administrators. Contact the office if you need access.
            </p>
        </div>
    </div>
    @include('partials.cookie-consent')
    <script src="{{ asset('js/cet-showpassword.js') }}?v={{ config('cet.asset_version') }}" defer></script>
</body>
</html>
