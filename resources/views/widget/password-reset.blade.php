<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Set a new password · Central Executive Transfers</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root{ --gold:#FBBA2A; --gold-deep:#E9A413; --ink:#0b0b0c; --line:#e9e7e0; --muted:#6a6a70; --err:#c02626; --ok:#1f8b4c; }
        *{box-sizing:border-box} html,body{margin:0}
        body{font-family:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:var(--ink);
            background:radial-gradient(1200px 500px at 50% -10%,rgba(251,186,42,.18),transparent 60%),linear-gradient(180deg,#0b0b0c,#121216 55%,#0b0b0c);
            min-height:100vh;padding:32px 16px;line-height:1.45}
        .wrap{max-width:460px;margin:0 auto;background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:0 24px 60px -24px rgba(0,0,0,.45)}
        .hero{background:linear-gradient(135deg,#17171a,#0b0b0c);color:#fff;padding:20px 22px}
        .brand{font-weight:800;letter-spacing:1.5px;font-size:14px}.brand span{color:var(--gold)}
        .hero h1{margin:14px 0 2px;font-size:20px}.hero p{margin:0;color:#c7c6c0;font-size:13px}
        .body{padding:20px 22px 24px}
        .field{margin-bottom:12px}
        .field label{display:block;font-size:12.5px;font-weight:600;color:#3a3a40;margin-bottom:6px}
        .field input{width:100%;padding:12px 13px;border:1px solid var(--line);border-radius:11px;font-size:15px;background:#fbfaf6;font-family:inherit}
        .field input:focus{outline:none;border-color:var(--gold);background:#fff;box-shadow:0 0 0 4px rgba(251,186,42,.18)}
        .btn{width:100%;padding:14px;border:0;border-radius:12px;background:linear-gradient(135deg,var(--gold),var(--gold-deep));color:#0b0b0c;font-weight:800;font-size:16px;cursor:pointer;text-decoration:none;display:block;text-align:center}
        .err{color:var(--err);font-size:13px;margin-bottom:12px;background:rgba(192,38,38,.07);border:1px solid rgba(192,38,38,.25);border-radius:9px;padding:9px 11px}
        .ok{color:var(--ok);font-size:14px;background:#eaf6ef;border:1px solid #bfe6cd;border-radius:9px;padding:12px 13px;margin-bottom:14px}
        .foot{text-align:center;color:var(--muted);font-size:12px;margin-top:14px}
        .foot a{color:var(--muted)}
        .tick{width:64px;height:64px;border-radius:50%;margin:4px auto 12px;background:radial-gradient(circle at 50% 35%,#34c56f,#1f8b4c);color:#fff;font-size:32px;display:grid;place-items:center}
    </style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <div class="brand">CENTRAL <span>EXECUTIVE</span> TRANSFERS</div>
        <h1>{{ ($done ?? false) ? 'Password updated' : 'Set a new password' }}</h1>
        <p>{{ ($done ?? false) ? 'You’re signed in.' : 'Choose a new password for your account.' }}</p>
    </div>
    <div class="body">
        @if($done ?? false)
            <div style="text-align:center">
                <div class="tick">✓</div>
                <p style="color:var(--muted);margin:0 0 16px">Your password has been changed and you’re now signed in.</p>
                <a class="btn" href="{{ route('widget.account') }}">Go to my bookings →</a>
            </div>
        @elseif(! $valid)
            <div class="err">{{ $error ?? 'This reset link is invalid or has expired.' }}</div>
            <a class="btn" href="{{ route('widget.account.forgot') }}">Request a new link</a>
        @else
            @if(!empty($error))<div class="err">{{ $error }}</div>@endif
            @foreach($errors->all() as $e)<div class="err">{{ $e }}</div>@endforeach
            <form method="POST" action="{{ route('widget.account.reset') }}">
                @csrf
                <input type="hidden" name="email" value="{{ $email }}">
                <input type="hidden" name="token" value="{{ $token }}">
                <div class="field"><label for="r-pass">New password</label>
                    <input id="r-pass" name="password" type="password" required minlength="8" placeholder="At least 8 characters"></div>
                <div class="field"><label for="r-conf">Confirm password</label>
                    <input id="r-conf" name="password_confirmation" type="password" required minlength="8" placeholder="Re-type it"></div>
                <button class="btn" type="submit">Save new password</button>
            </form>
            <p class="foot"><a href="{{ route('widget.account') }}">← Back to sign in</a></p>
        @endif
    </div>
</div>
</body>
</html>
