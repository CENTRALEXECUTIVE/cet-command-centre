<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Forgot password · Central Executive Transfers</title>
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
        .btn{width:100%;padding:14px;border:0;border-radius:12px;background:linear-gradient(135deg,var(--gold),var(--gold-deep));color:#0b0b0c;font-weight:800;font-size:16px;cursor:pointer}
        .err{color:var(--err);font-size:13px;margin-bottom:12px;background:rgba(192,38,38,.07);border:1px solid rgba(192,38,38,.25);border-radius:9px;padding:9px 11px}
        .ok{color:var(--ok);font-size:14px;background:#eaf6ef;border:1px solid #bfe6cd;border-radius:9px;padding:12px 13px}
        .foot{text-align:center;color:var(--muted);font-size:12px;margin-top:14px}
        .foot a{color:var(--muted)}
    </style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <div class="brand">CENTRAL <span>EXECUTIVE</span> TRANSFERS</div>
        <h1>Reset your password</h1>
        <p>We'll email you a secure link to set a new one.</p>
    </div>
    <div class="body">
        @if($sent)
            <div class="ok">If that email is registered with us, a reset link is on its way. It expires in 1 hour — check your inbox (and spam).</div>
            <p class="foot"><a href="{{ route('widget.account') }}">← Back to sign in</a></p>
        @else
            @error('email')<div class="err">{{ $message }}</div>@enderror
            <form method="POST" action="{{ route('widget.account.send-reset') }}">
                @csrf
                <div class="field"><label for="f-email">Your email</label>
                    <input id="f-email" name="email" type="email" required value="{{ old('email') }}" placeholder="you@email.com"></div>
                <button class="btn" type="submit">Send reset link</button>
            </form>
            <p class="foot"><a href="{{ route('widget.account') }}">← Back to sign in</a></p>
        @endif
    </div>
</div>
</body>
</html>
