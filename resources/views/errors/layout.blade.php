<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Something went wrong') · Central Executive Transfers</title>
    <style>
        :root{ --gold:#FBBA2A; --gold-deep:#E9A413; --ink:#0b0b0c; }
        *{ box-sizing:border-box } html,body{ margin:0; height:100% }
        body{ font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
            color:#f4f2ec; min-height:100%; display:flex; align-items:center; justify-content:center; padding:24px;
            background:radial-gradient(900px 400px at 50% -10%, rgba(251,186,42,.18), transparent 60%),
                linear-gradient(180deg,#0b0b0c 0%, #121216 55%, #0b0b0c 100%); }
        .box{ max-width:520px; width:100%; text-align:center; background:rgba(18,18,22,.7);
            border:1px solid #23232a; border-radius:20px; padding:36px 28px 30px; box-shadow:0 30px 70px -30px rgba(0,0,0,.7); }
        .mark{ width:52px; height:52px; border-radius:14px; background:var(--gold); color:#0b0b0c;
            font-family:Georgia,serif; font-weight:800; font-size:32px; display:grid; place-items:center; margin:0 auto 18px; }
        .code{ color:var(--gold); font-weight:800; letter-spacing:3px; font-size:13px; text-transform:uppercase; }
        h1{ font-size:24px; font-weight:800; margin:8px 0 10px; letter-spacing:-.3px }
        p{ color:#c7c6c0; font-size:15px; line-height:1.6; margin:0 auto 10px; max-width:42ch }
        .safe{ display:inline-block; margin:6px 0 18px; padding:7px 13px; border-radius:999px;
            background:rgba(31,139,76,.16); color:#53d08a; font-size:13px; font-weight:700 }
        .actions{ display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:6px }
        .btn{ display:inline-block; text-decoration:none; font-weight:800; font-size:15px; padding:13px 22px; border-radius:12px; }
        .btn.primary{ background:linear-gradient(135deg,var(--gold),var(--gold-deep)); color:#0b0b0c;
            box-shadow:0 12px 26px -10px rgba(233,164,19,.75) }
        .btn.ghost{ background:rgba(255,255,255,.1); color:#fff; border:1px solid rgba(255,255,255,.22) }
        .office{ margin-top:20px; font-size:13px; color:#9a9aa2 }
        .office a{ color:var(--gold); text-decoration:none; font-weight:700 }
    </style>
</head>
<body>
    <div class="box">
        <div class="mark">C</div>
        <div class="code">@yield('code', 'Error')</div>
        <h1>@yield('heading', 'Something went wrong')</h1>
        <p>@yield('message', 'Our end had a hiccup. Please try again in a moment.')</p>
        <div class="safe">✓ Your bookings and data are safe</div>
        <div class="actions">
            <a href="{{ url('/') }}" class="btn primary">Back to the Command Centre</a>
            <a href="javascript:location.reload()" class="btn ghost">Try again</a>
        </div>
        <div class="office">Need help now? Call the office on <a href="tel:+447405172435">+44&nbsp;7405&nbsp;172435</a></div>
    </div>
</body>
</html>
