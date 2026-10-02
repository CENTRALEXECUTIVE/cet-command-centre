<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>My bookings · Central Executive Transfers</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='22' fill='%230b0b0c'/><text x='50' y='72' font-size='64' text-anchor='middle' fill='%23FBBA2A' font-family='Georgia,serif' font-weight='bold'>C</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --gold:#FBBA2A; --gold-deep:#E9A413;
            --ink:#0b0b0c; --paper:#ffffff; --cream:#fbfaf6;
            --line:#e9e7e0; --muted:#6a6a70; --muted-2:#9a9aa2;
            --ok:#1f8b4c; --err:#c02626;
            --shadow:0 24px 60px -24px rgba(0,0,0,.45), 0 8px 24px -12px rgba(0,0,0,.25);
            --radius:16px;
        }
        * { box-sizing:border-box; }
        [hidden] { display:none !important; }
        html, body { margin:0; }
        body {
            font-family:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
            color:var(--ink); background:transparent; line-height:1.45; -webkit-font-smoothing:antialiased;
        }
        html.standalone body {
            background:
                radial-gradient(1200px 500px at 50% -10%, rgba(251,186,42,.18), transparent 60%),
                linear-gradient(180deg, #0b0b0c 0%, #121216 55%, #0b0b0c 100%);
            min-height:100vh; padding:32px 16px 44px;
        }

        .cet-widget { max-width:600px; margin:0 auto; background:var(--paper);
            border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; }

        .cet-hero {
            background:radial-gradient(600px 200px at 12% -40%, rgba(251,186,42,.30), transparent 60%),
                linear-gradient(135deg, #17171a 0%, #0b0b0c 100%);
            color:#fff; padding:18px 22px 20px; position:relative;
        }
        .cet-hero::after { content:""; position:absolute; left:0; right:0; bottom:0; height:3px;
            background:linear-gradient(90deg, var(--gold), var(--gold-deep)); }
        .cet-top { display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .cet-brand { display:flex; align-items:center; gap:10px; }
        .cet-mark { width:32px; height:32px; border-radius:9px; background:var(--gold); color:#0b0b0c;
            font-family:Georgia,serif; font-weight:800; font-size:21px; display:grid; place-items:center; flex:0 0 auto; }
        .cet-brand .name { font-weight:800; letter-spacing:1.4px; font-size:14px; line-height:1.1; }
        .cet-brand .name span { color:var(--gold); }
        .cet-logout { background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.18); color:#fff;
            border-radius:999px; padding:6px 14px; font-size:12.5px; font-weight:700; cursor:pointer; font-family:inherit; }
        .cet-logout:hover { background:rgba(255,255,255,.2); }
        .cet-hero h1 { margin:14px 0 2px; font-size:21px; font-weight:800; letter-spacing:-.3px; }
        .cet-hero p { margin:0; color:#c9c8c3; font-size:13px; }

        .cet-body { padding:18px 22px 20px; }

        .cet-field { margin-bottom:12px; }
        .cet-field label { display:block; font-size:11px; font-weight:700; color:var(--muted); margin-bottom:6px; text-transform:uppercase; letter-spacing:.6px; }
        .cet-field input, .cet-field select, .cet-field textarea {
            width:100%; padding:13px 14px; border:1px solid var(--line); border-radius:12px; font-size:15px;
            background:var(--cream); color:var(--ink); font-family:inherit; transition:border-color .12s, box-shadow .12s, background .12s;
        }
        .cet-field input::placeholder, .cet-field textarea::placeholder { color:var(--muted-2); }
        .cet-field input:focus, .cet-field select:focus, .cet-field textarea:focus {
            outline:none; border-color:var(--gold); background:#fff; box-shadow:0 0 0 4px rgba(251,186,42,.18);
        }

        .cet-btn {
            width:100%; padding:14px; border:0; border-radius:13px; cursor:pointer;
            background:linear-gradient(180deg, var(--gold), var(--gold-deep)); color:#0b0b0c; font-weight:800; font-size:15px;
            box-shadow:0 10px 22px -10px rgba(233,164,19,.7); transition:transform .08s, box-shadow .12s; font-family:inherit; letter-spacing:.2px;
        }
        .cet-btn:hover { transform:translateY(-1px); }
        .cet-link { background:none; border:0; color:var(--muted); text-decoration:underline; cursor:pointer; font-size:13px; padding:0; font-family:inherit; }
        a { color:var(--gold-deep); }

        .cet-alert { padding:11px 14px; border-radius:12px; font-size:14px; margin-bottom:14px; font-weight:600; }
        .cet-ok { background:rgba(31,139,76,.10); color:var(--ok); border:1px solid rgba(31,139,76,.25); }
        .cet-bad { background:rgba(192,38,38,.08); color:var(--err); border:1px solid rgba(192,38,38,.25); }

        .cet-tabs { display:flex; gap:8px; margin-bottom:16px; }
        .cet-tabs button { flex:1; padding:11px; border:1px solid var(--line); border-radius:12px; background:var(--cream); font-weight:700; font-size:13px; cursor:pointer; color:var(--muted); font-family:inherit; transition:.12s; }
        .cet-tabs button.on { border-color:var(--gold); background:#fff; color:var(--ink); box-shadow:0 0 0 4px rgba(251,186,42,.16); }
        .cet-forgot { display:block; text-align:right; margin-top:-4px; }

        .cet-job { border:1px solid var(--line); border-left:4px solid var(--gold); border-radius:13px; padding:14px 16px; margin-bottom:12px; background:#fff; }
        .cet-job h3 { margin:0 0 2px; font-size:15.5px; font-weight:800; }
        .cet-badge { display:inline-block; font-size:11px; font-weight:800; padding:3px 10px; border-radius:999px; background:rgba(251,186,42,.18); color:#8a6400; letter-spacing:.3px; }
        .cet-muted { color:var(--muted); font-size:13px; }
        .cet-row { display:flex; justify-content:space-between; gap:8px; flex-wrap:wrap; align-items:center; }
        details.cet-manage { margin-top:10px; border-top:1px dashed var(--line); padding-top:8px; }
        details.cet-manage summary { cursor:pointer; font-size:13px; font-weight:700; color:var(--gold-deep); }
        .cet-foot { text-align:center; color:var(--muted-2); font-size:12px; margin-top:14px; }
        .cet-sub { border:1px solid var(--line); border-radius:13px; padding:14px 16px; margin-top:16px; background:var(--cream); }
        .cet-sub h4 { margin:0 0 8px; font-size:14px; }
    </style>
</head>
<body>
    <div class="cet-widget" id="cet-widget">
        <div class="cet-hero">
            <div class="cet-top">
                <div class="cet-brand"><span class="cet-mark">C</span><span class="name">CENTRAL <span>EXECUTIVE</span> TRANSFERS</span></div>
                @if($verified)
                    <form action="{{ route('widget.account.logout') }}" method="POST" style="margin:0">@csrf<button class="cet-logout">Log out</button></form>
                @endif
            </div>
            <h1>{{ $verified ? 'My bookings' : 'Manage my bookings' }}</h1>
            <p>{{ $verified ? 'Your upcoming and recent journeys.' : 'Sign in or use a booking reference.' }}</p>
        </div>

        <div class="cet-body">
            @if(session('account_status'))<div class="cet-alert cet-ok">{{ session('account_status') }}</div>@endif
            @if(session('account_error'))<div class="cet-alert cet-bad">{{ session('account_error') }}</div>@endif

            @unless($verified)
                @if($passwordLogin)
                    <div class="cet-tabs" role="tablist">
                        <button type="button" id="tab-signin" class="on" onclick="cetTab('signin')">Sign in</button>
                        <button type="button" id="tab-ref" onclick="cetTab('ref')">Use a booking reference</button>
                    </div>

                    {{-- Email + password sign-in --}}
                    <div id="pane-signin">
                        <form action="{{ route('widget.account.login') }}" method="POST" autocomplete="on">
                            @csrf
                            <div class="cet-field"><label for="l-email">Email</label>
                                <input id="l-email" name="email" type="email" required value="{{ old('login_email') }}" placeholder="you@email.com"></div>
                            <div class="cet-field"><label for="l-pass">Password</label>
                                <input id="l-pass" name="password" type="password" required placeholder="Your password"></div>
                            <button class="cet-btn" type="submit">Sign in</button>
                            <a class="cet-link cet-forgot" href="{{ route('widget.account.forgot') }}" target="_top">Forgot password?</a>
                        </form>
                        <p class="cet-foot">New here? <a href="{{ route('widget.open-account') }}" target="_top">Create an account →</a></p>
                    </div>
                @endif

                {{-- Booking reference + contact (works with no password) --}}
                <div id="pane-ref" @if($passwordLogin) style="display:none" @endif>
                    <p class="cet-muted" style="margin:0 0 12px">Enter a booking reference and the phone number or email on it.</p>
                    <form action="{{ route('widget.account.verify') }}" method="POST" autocomplete="off">
                        @csrf
                        <div class="cet-field"><label for="a-ref">Booking reference</label>
                            <input id="a-ref" name="reference" required placeholder="e.g. CET-XXXXXX or your ETO ref"></div>
                        <div class="cet-field"><label for="a-contact">Phone or email on the booking</label>
                            <input id="a-contact" name="contact" required placeholder="07… or you@email.com"></div>
                        <button class="cet-btn" type="submit">View my bookings</button>
                    </form>
                    <p class="cet-foot">Don't have a reference? <a href="{{ route('widget.book') }}" target="_top">Make a booking →</a></p>
                </div>
            @else
                @forelse($bookings as $b)
                    @php $upcoming = $b->pickup_at && $b->pickup_at->isFuture() && ! $b->status->isTerminal(); @endphp
                    <div class="cet-job">
                        <div class="cet-row">
                            <h3>{{ $b->pickup_at?->format('D d M Y · H:i') }}</h3>
                            <span class="cet-badge">{{ $b->status->label() }}</span>
                        </div>
                        <div class="cet-muted">{{ \Illuminate\Support\Str::limit($b->pickup_address, 34) }} → {{ \Illuminate\Support\Str::limit($b->destination_address, 34) }}</div>
                        <div class="cet-muted" style="margin-top:4px">
                            {{ $b->vehicleType?->name }}
                            @if($b->fareAmount()) · £{{ number_format($b->fareAmount(), 2) }}@endif
                            @if($b->driverPublicName()) · Driver: {{ $b->driverPublicName() }}@endif
                            · Ref {{ $b->reference }}
                        </div>

                        @if($upcoming)
                            <details class="cet-manage">
                                <summary>Change or cancel this booking</summary>
                                <form action="{{ route('widget.account.request', $b) }}" method="POST" style="margin-top:10px">
                                    @csrf
                                    <div class="cet-field">
                                        <label>What do you need?</label>
                                        <select name="type">
                                            <option value="change">Request a change</option>
                                            <option value="cancel">Request cancellation</option>
                                        </select>
                                    </div>
                                    <div class="cet-field">
                                        <label>Details (optional)</label>
                                        <textarea name="message" rows="2" placeholder="e.g. move to 3pm, add a stop, cancel please"></textarea>
                                    </div>
                                    <button class="cet-btn" type="submit" style="font-size:14px;padding:11px">Send request to the office</button>
                                </form>
                            </details>
                        @endif
                    </div>
                @empty
                    <p class="cet-muted">No bookings found on your record yet.</p>
                @endforelse

                @if($passwordLogin && filled($customer?->email))
                    <div class="cet-sub">
                        <h4>{{ $hasPassword ? '🔒 Change your password' : '🔒 Set a password for faster sign-in' }}</h4>
                        @unless($hasPassword)
                            <p class="cet-muted" style="margin:0 0 10px">Next time you can just sign in with <strong>{{ $customer->email }}</strong> and your password.</p>
                        @endunless
                        <form action="{{ route('widget.account.set-password') }}" method="POST" autocomplete="off">
                            @csrf
                            <div class="cet-field"><label for="sp-pass">New password</label>
                                <input id="sp-pass" name="password" type="password" required minlength="8" placeholder="At least 8 characters"></div>
                            <div class="cet-field"><label for="sp-conf">Confirm password</label>
                                <input id="sp-conf" name="password_confirmation" type="password" required minlength="8" placeholder="Re-type it"></div>
                            <button class="cet-btn" type="submit" style="font-size:14px;padding:11px">{{ $hasPassword ? 'Update password' : 'Save password' }}</button>
                        </form>
                    </div>
                @endif

                <p class="cet-foot">Need something else? <a href="{{ route('widget.book') }}" target="_top">Make a new booking →</a></p>
            @endunless
        </div>
    </div>
    <script>
        if (window.self === window.top) { document.documentElement.classList.add('standalone'); }
        function cetTab(which) {
            var signin = which === 'signin';
            var ps = document.getElementById('pane-signin'), pr = document.getElementById('pane-ref');
            if (ps) ps.style.display = signin ? '' : 'none';
            if (pr) pr.style.display = signin ? 'none' : '';
            var ts = document.getElementById('tab-signin'), tr = document.getElementById('tab-ref');
            if (ts) ts.classList.toggle('on', signin);
            if (tr) tr.classList.toggle('on', !signin);
            try { parent.postMessage({ cetWidgetHeight: document.getElementById('cet-widget').offsetHeight + 24 }, '*'); } catch (e) {}
        }
        try { parent.postMessage({ cetWidgetHeight: document.getElementById('cet-widget').offsetHeight + 24 }, '*'); } catch (e) {}
    </script>
</body>
</html>
