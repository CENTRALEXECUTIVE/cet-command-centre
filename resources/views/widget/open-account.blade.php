<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Open an account · Central Executive Transfers</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root{ --gold:#FBBA2A; --gold-deep:#E9A413; --ink:#0b0b0c; --paper:#fff; --cream:#fbfaf6;
            --line:#e9e7e0; --muted:#6a6a70; --muted-2:#9a9aa2; --ok:#1f8b4c; --err:#c02626;
            --shadow:0 24px 60px -24px rgba(0,0,0,.45),0 8px 24px -12px rgba(0,0,0,.25); --radius:16px; }
        *{box-sizing:border-box} html,body{margin:0}
        body{font-family:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:var(--ink);
            background:radial-gradient(1200px 500px at 50% -10%,rgba(251,186,42,.18),transparent 60%),
                linear-gradient(180deg,#0b0b0c 0%,#121216 55%,#0b0b0c 100%);min-height:100vh;padding:28px 16px 48px;line-height:1.45;}
        .wrap{max-width:620px;margin:0 auto;background:var(--paper);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
        .hero{background:radial-gradient(600px 200px at 12% -40%,rgba(251,186,42,.30),transparent 60%),linear-gradient(135deg,#17171a,#0b0b0c);color:#fff;padding:22px 24px 20px;position:relative}
        .hero::after{content:"";position:absolute;left:0;right:0;bottom:0;height:3px;background:linear-gradient(90deg,var(--gold),var(--gold-deep))}
        .brand{display:flex;align-items:center;gap:10px}
        .mark{width:34px;height:34px;border-radius:9px;background:var(--gold);color:#0b0b0c;font-family:Georgia,serif;font-weight:800;font-size:22px;display:grid;place-items:center}
        .brand .name{font-weight:800;letter-spacing:1.5px;font-size:15px}.brand .name span{color:var(--gold)}
        .hero h1{margin:16px 0 2px;font-size:22px;font-weight:800}.hero p{margin:0;color:#c7c6c0;font-size:13px}
        .body{padding:18px 24px 24px}
        .seg{display:flex;gap:8px;margin-bottom:16px}
        .seg button{flex:1;padding:12px;border:1.5px solid var(--line);border-radius:12px;background:var(--cream);font-weight:800;font-size:14px;cursor:pointer;color:var(--ink)}
        .seg button.on{border-color:var(--gold);background:#fff;box-shadow:0 0 0 3px rgba(251,186,42,.16)}
        .field{margin-bottom:12px}
        .field label{display:block;font-size:12.5px;font-weight:600;color:#3a3a40;margin-bottom:6px}
        .field label .opt{font-weight:400;color:var(--muted-2)}
        .field input,.field textarea{width:100%;padding:12px 13px;border:1px solid var(--line);border-radius:11px;font-size:15px;background:var(--cream);font-family:inherit;color:var(--ink)}
        .field input:focus,.field textarea:focus{outline:none;border-color:var(--gold);background:#fff;box-shadow:0 0 0 4px rgba(251,186,42,.18)}
        .field textarea{min-height:64px;resize:vertical}
        .two{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:460px){.two{grid-template-columns:1fr}}
        .sec{font-size:13px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin:18px 0 10px}
        .email-row{display:flex;gap:8px;margin-bottom:8px}
        .email-row input{flex:1}
        .email-row button{flex:0 0 auto;width:44px;border:1px solid var(--line);border-radius:11px;background:#fff;color:var(--err);font-size:16px;font-weight:800;cursor:pointer}
        .addbtn{border:1px dashed var(--line);background:var(--cream);border-radius:11px;padding:9px 14px;font-weight:700;font-size:13px;cursor:pointer}
        .note{background:#fbfaf7;border:1px dashed var(--line);border-radius:12px;padding:12px 14px;font-size:13px;color:var(--muted);margin-top:4px}
        .note b{color:var(--ink)}
        .btn{width:100%;padding:15px;border:0;border-radius:12px;background:linear-gradient(135deg,var(--gold),var(--gold-deep));color:#0b0b0c;font-weight:800;font-size:16px;cursor:pointer;box-shadow:0 10px 24px -10px rgba(233,164,19,.7);margin-top:8px}
        .err{color:var(--err);font-size:13px;margin-top:10px;background:rgba(192,38,38,.07);border:1px solid rgba(192,38,38,.25);border-radius:9px;padding:9px 11px}
        .foot{text-align:center;color:var(--muted);font-size:11.5px;margin-top:14px}
        .hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
        .done{text-align:center;padding:28px 12px 34px}
        .done .tick{width:70px;height:70px;border-radius:50%;margin:0 auto 14px;background:radial-gradient(circle at 50% 35%,#34c56f,#1f8b4c);color:#fff;font-size:36px;display:grid;place-items:center}
        .done h2{margin:6px 0 6px;font-size:22px;font-weight:800}
    </style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <div class="brand"><div class="mark">C</div><div class="name">CENTRAL <span>EXECUTIVE</span></div></div>
        @if($done)
            <h1>Thank you</h1><p>Your request is with our office.</p>
        @else
            <h1>Open an account</h1><p>Faster bookings and, for businesses, invoicing on account.</p>
        @endif
    </div>

    @if($done)
        <div class="body"><div class="done">
            <div class="tick">✓</div>
            @if(($accountType ?? '') === 'company')
                <h2>Business account requested</h2>
                <p style="color:var(--muted)">Our office will review your details and set up your invoice/credit account. We'll be in touch to confirm — you can book straight away in the meantime.</p>
            @else
                <h2>You're all set</h2>
                <p style="color:var(--muted)">Your details are saved — future bookings will be quicker, and you can manage them in <strong>My Account</strong>.</p>
            @endif
            <a href="{{ route('widget.book') }}" class="btn" style="display:block;margin-top:18px;text-decoration:none;text-align:center">Book a journey →</a>
        </div></div>
    @else
        <div class="body">
            @if($errors->any())
                <div class="err"><strong>Please check the form:</strong>
                    <ul style="margin:6px 0 0;padding-left:18px">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
            @endif

            <div class="seg" role="tablist">
                <button type="button" id="seg-personal" class="on" onclick="cetType('personal')">👤 Personal</button>
                <button type="button" id="seg-company" onclick="cetType('company')">🏢 Business</button>
            </div>

            <form method="POST" action="{{ route('widget.open-account.store') }}" autocomplete="off">
                @csrf
                <div class="hp"><label>Company<input name="company" tabindex="-1" autocomplete="off"></label></div>
                <input type="hidden" name="account_type" id="account_type" value="personal">

                {{-- PERSONAL --}}
                <div id="panel-personal">
                    <div class="field"><label>Full name</label><input name="personal_name" value="{{ old('personal_name') }}"></div>
                    <div class="two">
                        <div class="field"><label>Mobile</label><input name="personal_phone" value="{{ old('personal_phone') }}" placeholder="07…"></div>
                        <div class="field"><label>Email</label><input name="personal_email" type="email" value="{{ old('personal_email') }}"></div>
                    </div>
                </div>

                {{-- COMPANY --}}
                <div id="panel-company" style="display:none">
                    <div class="sec">Company details</div>
                    <div class="field"><label>Company name</label><input name="company_name" value="{{ old('company_name') }}"></div>
                    <div class="two">
                        <div class="field"><label>Company number <span class="opt">(optional)</span></label><input name="company_number" value="{{ old('company_number') }}" placeholder="e.g. 15749931"></div>
                        <div class="field"><label>VAT number <span class="opt">(optional)</span></label><input name="vat_number" value="{{ old('vat_number') }}"></div>
                    </div>
                    <div class="field"><label>Company address</label><textarea name="company_address">{{ old('company_address') }}</textarea></div>

                    <div class="sec">Main contact</div>
                    <div class="field"><label>Contact name</label><input name="contact_name" value="{{ old('contact_name') }}"></div>
                    <div class="two">
                        <div class="field"><label>Contact mobile</label><input name="contact_phone" value="{{ old('contact_phone') }}" placeholder="07…"></div>
                        <div class="field"><label>Contact email</label><input name="contact_email" type="email" value="{{ old('contact_email') }}"></div>
                    </div>
                    <div class="field"><label>Main contact address <span class="opt">(optional)</span></label><textarea name="contact_address">{{ old('contact_address') }}</textarea></div>

                    <div class="sec">Additional emails <span class="opt" style="text-transform:none;letter-spacing:0;font-weight:500">(optional — e.g. accounts / bookings inboxes)</span></div>
                    <div id="extra-emails"></div>
                    <button type="button" class="addbtn" id="add-email">＋ Add another email</button>

                    <div class="note" style="margin-top:16px">🏢 <b>Business accounts are reviewed by our office</b> before invoice/credit terms are switched on — this protects both sides. You can start booking right away in the meantime.</div>
                </div>

                <button class="btn" type="submit" id="submit">Create account</button>
                <p class="foot">By continuing you agree to our
                    <a href="{{ url('/terms') }}" target="_blank" rel="noopener">Terms</a> &amp;
                    <a href="{{ url('/privacy') }}" target="_blank" rel="noopener">Privacy Policy</a>.</p>
            </form>
        </div>
    @endif
</div>

<script>
    function cetType(t) {
        document.getElementById('account_type').value = t;
        document.getElementById('panel-personal').style.display = t === 'personal' ? '' : 'none';
        document.getElementById('panel-company').style.display = t === 'company' ? '' : 'none';
        document.getElementById('seg-personal').classList.toggle('on', t === 'personal');
        document.getElementById('seg-company').classList.toggle('on', t === 'company');
        document.getElementById('submit').textContent = t === 'company' ? 'Request business account' : 'Create account';
    }
    (function () {
        var wrap = document.getElementById('extra-emails');
        var addBtn = document.getElementById('add-email');
        if (addBtn) addBtn.addEventListener('click', function () {
            if (wrap.querySelectorAll('input').length >= 10) return;
            var row = document.createElement('div'); row.className = 'email-row';
            var input = document.createElement('input'); input.type = 'email'; input.name = 'extra_emails[]'; input.placeholder = 'name@company.co.uk';
            var x = document.createElement('button'); x.type = 'button'; x.textContent = '✕'; x.setAttribute('aria-label', 'Remove');
            x.addEventListener('click', function () { row.remove(); });
            row.appendChild(input); row.appendChild(x); wrap.appendChild(row); input.focus();
        });
        @if(old('account_type') === 'company') cetType('company'); @endif
    })();
</script>
</body>
</html>
