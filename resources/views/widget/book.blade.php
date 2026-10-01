<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Book a journey · Central Executive Transfers</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='22' fill='%230b0b0c'/><text x='50' y='72' font-size='64' text-anchor='middle' fill='%23FBBA2A' font-family='Georgia,serif' font-weight='bold'>C</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --gold:#FBBA2A; --gold-deep:#E9A413;
            --ink:#0b0b0c; --ink-2:#17171a;
            --paper:#ffffff; --cream:#fbfaf6;
            --line:#e9e7e0; --muted:#6a6a70; --muted-2:#9a9aa2;
            --ok:#1f8b4c; --err:#c02626;
            --shadow:0 24px 60px -24px rgba(0,0,0,.45), 0 8px 24px -12px rgba(0,0,0,.25);
            --radius:16px;
        }
        * { box-sizing:border-box; }
        /* Ensure [hidden] always wins over component display rules (flex/grid),
           so gated bits (account payment, meet & greet, panels) stay hidden. */
        [hidden] { display:none !important; }
        html, body { margin:0; }
        body {
            font-family:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
            color:var(--ink); background:transparent; line-height:1.45; -webkit-font-smoothing:antialiased;
        }
        html.standalone body {
            background:
                radial-gradient(1200px 500px at 50% -10%, rgba(251,186,42,.18), transparent 60%),
                radial-gradient(800px 600px at 100% 0%, rgba(251,186,42,.06), transparent 55%),
                linear-gradient(180deg, #0b0b0c 0%, #121216 55%, #0b0b0c 100%);
            min-height:100vh; padding:32px 16px 44px;
        }
        html.standalone .cet-tagline-top { display:block; }

        .cet-tagline-top { display:none; text-align:center; margin:0 0 20px; }
        .cet-tagline-top .k { color:var(--gold); font-weight:800; letter-spacing:3px; font-size:12px; text-transform:uppercase; }
        .cet-tagline-top .t { color:#f4f2ec; font-size:26px; font-weight:800; letter-spacing:-.4px; margin-top:6px; }
        .cet-tagline-top .s { color:#b9b8b2; font-size:13px; margin-top:4px; }

        .cet-widget { max-width:600px; margin:0 auto; background:var(--paper);
            border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; }

        .cet-hero {
            background:radial-gradient(600px 200px at 12% -40%, rgba(251,186,42,.30), transparent 60%),
                linear-gradient(135deg, #17171a 0%, #0b0b0c 100%);
            color:#fff; padding:16px 24px 20px; position:relative;
            display:flex; flex-direction:column;
        }
        .cet-hero::after { content:""; position:absolute; left:0; right:0; bottom:0; height:3px;
            background:linear-gradient(90deg, var(--gold), var(--gold-deep)); }
        .cet-brand { display:flex; align-items:center; gap:10px; }
        .cet-mark { width:34px; height:34px; border-radius:9px; background:var(--gold); color:#0b0b0c;
            font-family:Georgia,serif; font-weight:800; font-size:22px; display:grid; place-items:center; flex:0 0 auto; }
        .cet-brand .name { font-weight:800; letter-spacing:1.5px; font-size:15px; line-height:1.1; }
        .cet-brand .name span { color:var(--gold); }
        .cet-brand .sub { color:#b7b6b0; font-size:11px; letter-spacing:.5px; margin-top:2px; }
        .cet-hero h1 { margin:16px 0 2px; font-size:22px; font-weight:800; letter-spacing:-.4px; }
        .cet-hero p { margin:0; color:#c7c6c0; font-size:13px; }

        /* Language switcher (ETO-style) — drives Google's free website translation. */
        .cet-lang { align-self:flex-end; display:inline-flex; align-items:center; gap:5px;
            background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.22); border-radius:999px;
            padding:5px 8px 5px 11px; margin-bottom:12px; z-index:5; }
        .cet-lang .ic { font-size:13px; line-height:1; }
        .cet-lang select { background:transparent; border:0; color:#fff; font-family:inherit; font-size:12.5px;
            font-weight:700; cursor:pointer; outline:none; padding-right:2px; }
        .cet-lang select option { color:#111; }
        /* Hide Google's own gadget + top banner; keep the page from being pushed down. */
        #google_translate_element { display:none; }
        .goog-te-banner-frame, .goog-te-gadget-icon, .skiptranslate iframe { display:none !important; }
        body { top:0 !important; }
        /* Google wraps translated runs in <font> — keep them inheriting our styles. */
        font { font:inherit !important; color:inherit !important; background:transparent !important; }

        /* Progress steps */
        .cet-steps { display:flex; align-items:center; gap:6px; padding:16px 24px 4px; }
        .cet-steps .st { display:flex; align-items:center; gap:8px; }
        .cet-steps .num { width:26px; height:26px; border-radius:50%; display:grid; place-items:center;
            font-size:13px; font-weight:800; background:#eee; color:#9a9aa2; flex:0 0 auto; transition:.2s; }
        .cet-steps .lbl { font-size:12px; font-weight:700; color:var(--muted-2); text-transform:uppercase; letter-spacing:.6px; }
        .cet-steps .st.active .num { background:var(--gold); color:#0b0b0c; box-shadow:0 4px 12px -4px rgba(233,164,19,.7); }
        .cet-steps .st.active .lbl { color:var(--ink); }
        .cet-steps .st.done .num { background:var(--ink); color:#fff; }
        .cet-steps .bar { flex:1; height:2px; background:#ececec; border-radius:2px; }
        .cet-steps .bar.fill { background:var(--gold); }
        @media (max-width:460px){ .cet-steps .lbl { display:none; } }

        .cet-body { padding:16px 24px 22px; }

        .cet-step[hidden] { display:none; }
        .cet-step-title { font-size:17px; font-weight:800; margin:4px 0 14px; letter-spacing:-.2px; }
        /* Booking summary on the details step */
        .cet-summary { border:1px solid var(--line); border-radius:14px; background:var(--cream); padding:14px 16px; margin-bottom:16px; }
        .cet-summary h4 { margin:0 0 10px; font-size:12px; font-weight:800; letter-spacing:.7px; text-transform:uppercase; color:var(--muted); }
        .cet-summary .row { display:flex; justify-content:space-between; gap:14px; padding:5px 0; font-size:13.5px; }
        .cet-summary .row .k { color:var(--muted); flex:0 0 auto; }
        .cet-summary .row .v { font-weight:700; text-align:right; }
        .cet-summary .tot { margin-top:8px; padding-top:10px; border-top:1px solid var(--line); }
        .cet-summary .tot .v { font-size:18px; font-weight:800; }

        .cet-field { margin-bottom:12px; }
        .cet-field label { display:block; font-size:12.5px; font-weight:600; color:#3a3a40; margin-bottom:6px; }
        .cet-field label .opt { font-weight:400; color:var(--muted-2); }
        .cet-field input, .cet-field select, .cet-field textarea {
            width:100%; padding:12px 13px; border:1px solid var(--line); border-radius:11px;
            font-size:15px; background:var(--cream); font-family:inherit; color:var(--ink);
            transition:border-color .15s, box-shadow .15s, background .15s;
        }
        .cet-field input::placeholder, .cet-field textarea::placeholder { color:#b3b2ad; }
        .cet-field input:focus, .cet-field select:focus, .cet-field textarea:focus {
            outline:none; border-color:var(--gold); background:#fff; box-shadow:0 0 0 4px rgba(251,186,42,.18); }
        .cet-field.bad input, .cet-field.bad textarea { border-color:var(--err); background:#fdf3f3; }
        .cet-field.icon { position:relative; }
        .cet-field.icon input { padding-right:46px; }
        /* Address stays full width (never cropped); the postcode sits on its own
           line below it as a compact box. */
        .cet-addr-row .cet-field { margin-bottom:12px; }
        .cet-pc { max-width:180px; }
        /* The location/clear button turns into a red ✕ when the field has an address. */
        .cet-field.icon .geo.is-clear { color:var(--err); font-weight:700; }
        /* "Use my current location" button (like ETO's crosshair). */
        .cet-field.icon .geo { position:absolute; right:7px; top:25px; width:38px; height:40px; border:0;
            background:transparent; color:var(--muted); font-size:20px; line-height:1; cursor:pointer;
            display:grid; place-items:center; border-radius:9px; transition:background .15s, color .15s; }
        .cet-field.icon .geo:hover { background:rgba(0,0,0,.05); color:var(--gold-deep); }
        .cet-field.icon .geo.busy { opacity:.5; cursor:default; }
        .cet-field.icon .geo.busy::after { content:""; }
        .cet-stop-row { position:relative; display:flex; align-items:center; gap:8px; margin-bottom:8px; }
        .cet-stop-row .pin { position:absolute; left:13px; top:50%; transform:translateY(-50%); font-size:14px; pointer-events:none; }
        .cet-stop-row input { flex:1; padding-left:38px; }
        .cet-stop-x { flex:0 0 auto; width:40px; height:44px; border:1px solid var(--line); border-radius:11px; background:#fff; color:var(--err,#c02626); font-size:16px; font-weight:800; cursor:pointer; }
        .cet-addstop { border:1px dashed var(--line); background:var(--cream,#fbfaf6); border-radius:11px; padding:10px 14px; font-weight:700; font-size:13px; cursor:pointer; color:var(--ink,#111); }
        .cet-addstop:disabled { opacity:.5; cursor:default; }
        .cet-acctbar { border:1px solid var(--line); border-radius:12px; padding:12px 14px; margin-bottom:14px; background:var(--cream,#fbfaf6); }
        .cet-linkbtn { background:none; border:0; padding:0; color:var(--gold-deep,#E9A413); font-weight:700; font-size:13.5px; cursor:pointer; text-decoration:underline; }
        #b-loggedin { font-size:14px; color:#1f7a44; font-weight:600; }
        #b-loggedin #b-me-acct { color:var(--muted,#666); font-weight:500; }
        .cet-bad-note { background:#fbeaea; color:#b32020; border-radius:8px; padding:8px 10px; font-size:13px; }
        .cet-two { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media (max-width:460px){ .cet-two { grid-template-columns:1fr; } }

        /* Vehicle cards — a tidy 3-column grid: photo · details · tick. The price
           sits on its own line inside the details so it never collides with the
           capacity text, and each capacity stat stays on one piece when it wraps. */
        .cet-vehs { display:flex; flex-direction:column; gap:10px; }
        .cet-veh { display:grid; grid-template-columns:88px 1fr 22px; gap:12px; align-items:center;
            padding:12px; border:1.5px solid var(--line); border-radius:16px; background:var(--cream);
            cursor:pointer; position:relative; transition:transform .12s ease,border-color .15s,box-shadow .15s; }
        @media (min-width:440px){ .cet-veh { grid-template-columns:118px 1fr 24px; gap:15px; padding:13px 15px; } }
        .cet-veh:hover { border-color:var(--gold-deep); background:#fff; box-shadow:0 8px 20px rgba(0,0,0,.07); transform:translateY(-1px); }
        .cet-veh input { position:absolute; opacity:0; pointer-events:none; }
        .cet-veh.sel { border-color:var(--gold); background:#fff; box-shadow:0 0 0 4px rgba(251,186,42,.16); }
        /* Uniform premium photo tile: the whole car shows (contain, never cropped),
           centred on a soft white ground with a subtle drop shadow. */
        .cet-veh-img { width:100%; height:64px; border-radius:12px; overflow:hidden; padding:7px;
            border:1px solid var(--line);
            background:radial-gradient(130% 130% at 50% 16%,#ffffff 0%,#f1efe8 100%);
            display:grid; place-items:center; }
        @media (min-width:440px){ .cet-veh-img { height:78px; padding:8px; } }
        .cet-veh-img img { width:100%; height:100%; object-fit:contain; object-position:center;
            filter:drop-shadow(0 6px 9px rgba(0,0,0,.16)); }
        .cet-veh-img svg { width:82px; height:auto; opacity:.8; }
        .cet-veh.sel .cet-veh-img { border-color:rgba(251,186,42,.55); }
        .cet-veh-meta { min-width:0; }
        .cet-veh-name { font-weight:800; font-size:16px; line-height:1.15; letter-spacing:-.2px; }
        .cet-veh-tag { font-size:12px; color:var(--gold-deep); font-weight:700; margin-top:2px; }
        .cet-veh-cap { display:flex; flex-wrap:wrap; gap:3px 12px; margin-top:6px; font-size:11.5px; color:var(--muted); }
        .cet-veh-cap span { white-space:nowrap; }
        /* Price on its own line under the details. */
        .cet-veh-price { margin-top:8px; }
        .cet-veh-price .amt { font-weight:900; font-size:19px; letter-spacing:-.4px; color:var(--ink); }
        .cet-veh-price .amt.poa { font-size:14px; font-weight:800; color:var(--muted); }
        .cet-veh-price .sub { font-size:11px; color:var(--muted-2); font-weight:600; margin-left:6px; }
        .cet-veh-tick { width:22px; height:22px; border-radius:50%; border:2px solid var(--line);
            display:grid; place-items:center; color:#fff; font-size:12px; font-weight:900; }
        @media (min-width:440px){ .cet-veh-tick { width:24px; height:24px; font-size:13px; } }
        .cet-veh.sel .cet-veh-tick { background:var(--gold); border-color:var(--gold); color:#0b0b0c; }
        .cet-veh[hidden] { display:none; }
        /* A vehicle that can't fit the party/luggage is greyed and not selectable. */
        .cet-veh.unfit { opacity:.5; filter:grayscale(.55); cursor:not-allowed; }
        .cet-veh.unfit:hover { border-color:var(--line); background:var(--cream); box-shadow:none; transform:none; }
        .cet-veh.unfit .cet-veh-price { display:none; }
        .cet-veh-warn { display:none; margin-top:7px; font-size:11.5px; font-weight:700; color:var(--err); }
        .cet-veh.unfit .cet-veh-warn { display:block; }

        /* Extras (step 3) — ETO-style add-ons. */
        .cet-mini-title { font-size:14px; font-weight:800; margin:18px 0 9px; letter-spacing:-.2px; display:flex; align-items:center; gap:7px; }
        .cet-mini-title .opt { font-weight:500; }
        .cet-check { display:flex; align-items:center; gap:11px; border:1px solid var(--line); border-radius:12px;
            padding:12px 13px; cursor:pointer; background:var(--cream); font-size:14px; font-weight:600; }
        .cet-check:hover { border-color:var(--gold-deep); background:#fff; }
        .cet-check input { width:19px; height:19px; flex:0 0 auto; accent-color:var(--gold-deep); }
        .cet-check .px { margin-left:auto; font-weight:800; color:var(--ink); }
        .cet-check.on { border-color:var(--gold); background:#fff; box-shadow:0 0 0 3px rgba(251,186,42,.14); }
        /* ETO-style auth tabs (guest / sign in / register). */
        .cet-authtabs { display:flex; gap:6px; margin-bottom:14px; background:var(--cream); border:1px solid var(--line); border-radius:12px; padding:4px; }
        .cet-authtabs button { flex:1; padding:9px 6px; border:0; border-radius:9px; background:transparent; font-weight:700; font-size:13px; color:var(--muted); cursor:pointer; }
        .cet-authtabs button.on { background:#fff; color:var(--ink); box-shadow:0 1px 3px rgba(0,0,0,.10); }
        .cet-signedin { border:1px solid #bfe6cd; background:#eef8f1; color:#1f7a44; border-radius:12px; padding:11px 14px; font-size:14px; font-weight:600; margin-bottom:14px; }
        .cet-signedin #b-me-acct { color:var(--muted); font-weight:500; }
        .cet-btn-sm { padding:9px 18px; font-size:14px; }
        /* Compact extras list. */
        .cet-xtras { display:flex; flex-direction:column; gap:8px; }
        .cet-check-sm { padding:10px 12px; font-size:13.5px; font-weight:600; gap:10px; }
        .cet-check-sm input { width:17px; height:17px; }
        .cet-check-sm .opt { font-weight:400; color:var(--muted-2); font-size:12.5px; }
        .cet-check-sm .px { margin-left:auto; font-weight:800; color:var(--ink); font-size:13px; }
        .cet-steppers { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; margin-top:2px; }
        @media (max-width:460px){ .cet-steppers { grid-template-columns:1fr; } }
        .cet-stepper label { display:block; font-size:12.5px; font-weight:600; color:#3a3a40; margin-bottom:6px; }
        .cet-stepper label .opt { font-weight:400; color:var(--muted-2); }
        .cet-stepper .ctrl { display:flex; align-items:center; border:1px solid var(--line); border-radius:11px; overflow:hidden; background:var(--cream); }
        .cet-stepper .ctrl button { width:44px; height:46px; border:0; background:transparent; font-size:22px; font-weight:700;
            color:var(--gold-deep); cursor:pointer; line-height:1; flex:0 0 auto; }
        .cet-stepper .ctrl button:active { background:#f2efe6; }
        .cet-stepper .ctrl input { border:0; background:transparent; text-align:center; font-size:16px; font-weight:800;
            width:100%; padding:12px 0; -moz-appearance:textfield; }
        .cet-stepper .ctrl input::-webkit-outer-spin-button,
        .cet-stepper .ctrl input::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }

        .cet-vat { display:flex; gap:11px; align-items:flex-start; margin-top:16px; padding:13px 14px;
            border:1px dashed var(--line); border-radius:12px; background:var(--cream); cursor:pointer; }
        .cet-vat input { width:19px; height:19px; margin-top:2px; flex:0 0 auto; accent-color:var(--gold-deep); }
        .cet-vat .t { font-size:13px; color:var(--muted); line-height:1.4; }
        .cet-vat .t b { color:var(--ink); }
        .cet-vat.on { border-style:solid; border-color:var(--gold); background:#fff; box-shadow:0 0 0 3px rgba(251,186,42,.14); }

        /* Payment method (ETO-style radios). */
        .cet-pay { display:flex; align-items:center; gap:11px; border:1.5px solid var(--line); border-radius:12px;
            padding:13px 14px; cursor:pointer; background:var(--cream); margin-top:9px; }
        .cet-pay:hover { border-color:var(--gold-deep); background:#fff; }
        .cet-pay input { width:19px; height:19px; flex:0 0 auto; accent-color:var(--gold-deep); }
        .cet-pay .t { font-size:14px; }
        .cet-pay .t .opt { color:var(--muted-2); font-weight:400; }
        .cet-pay.on { border-color:var(--gold); background:#fff; box-shadow:0 0 0 3px rgba(251,186,42,.14); }

        /* Total price band. */
        .cet-total { display:flex; align-items:center; justify-content:space-between; gap:14px; margin-top:16px;
            padding:14px 16px; border-radius:13px; background:linear-gradient(135deg,#fff9ea,#fdf3d6);
            border:1px solid var(--gold); box-shadow:0 6px 18px -10px rgba(233,164,19,.5); }
        .cet-total .lbl { font-size:13px; font-weight:700; color:var(--ink); text-transform:uppercase; letter-spacing:.5px; }
        .cet-total .amt { font-size:26px; font-weight:900; letter-spacing:-.6px; }
        .cet-total .amt small { font-size:11px; font-weight:600; color:var(--muted); }

        /* Terms / privacy acceptance. */
        .cet-agree { display:flex; align-items:center; gap:10px; margin-top:11px; font-size:13.5px; color:#3a3a40; cursor:pointer; }
        .cet-agree input { width:18px; height:18px; flex:0 0 auto; accent-color:var(--gold-deep); }
        .cet-agree a { color:var(--gold-deep); font-weight:700; }

        /* ETO-style notice banner (e.g. the 8-hour minimum notice). */
        .cet-banner { display:none; margin:0 0 14px; padding:12px 14px; border-radius:11px; font-size:13.5px;
            background:#fdecec; border:1px solid #f3b7b7; color:#8f1f1f; font-weight:600; }
        .cet-banner.show { display:block; }

        .cet-actions { display:flex; gap:10px; margin-top:18px; }
        .cet-btn { flex:1; padding:15px; border:0; border-radius:12px;
            background:linear-gradient(135deg, var(--gold), var(--gold-deep)); color:#0b0b0c;
            font-weight:800; font-size:16px; cursor:pointer; letter-spacing:.2px;
            box-shadow:0 10px 24px -10px rgba(233,164,19,.7); transition:transform .12s, filter .12s; }
        .cet-btn:hover { transform:translateY(-1px); filter:brightness(1.03); }
        .cet-btn:active { transform:translateY(0); }
        .cet-btn:disabled { opacity:.6; cursor:default; transform:none; box-shadow:none; }
        .cet-back { flex:0 0 auto; padding:15px 20px; border:1.5px solid var(--line); border-radius:12px;
            background:#fff; color:var(--ink); font-weight:700; font-size:15px; cursor:pointer; }
        .cet-back:hover { background:#f5f4f0; }

        .cet-err { color:var(--err); font-size:13px; margin-top:10px; background:rgba(192,38,38,.07);
            border:1px solid rgba(192,38,38,.25); border-radius:9px; padding:9px 11px; display:none; }
        .cet-foot { text-align:center; color:var(--muted); font-size:11.5px; margin-top:12px; }

        .cet-trust { display:flex; gap:8px; flex-wrap:wrap; margin-top:16px; padding-top:16px; border-top:1px solid var(--line); }
        .cet-trust span { flex:1; min-width:120px; display:flex; align-items:center; gap:7px; font-size:11.5px; color:#4a4a50; font-weight:600; }
        .cet-trust .ic { color:var(--gold-deep); font-size:14px; }

        /* "Add a return?" prompt shown when a one-way booking moves on. */
        .cet-modal { position:fixed; inset:0; z-index:60; display:grid; place-items:center; padding:20px;
            background:rgba(11,11,12,.55); backdrop-filter:blur(3px); }
        .cet-modal[hidden] { display:none; }
        .cet-modal-card { background:var(--paper); border-radius:16px; max-width:380px; width:100%;
            box-shadow:var(--shadow); padding:24px 22px 20px; text-align:center; }
        .cet-modal-emoji { font-size:34px; line-height:1; margin-bottom:8px; }
        .cet-modal-card h3 { margin:0 0 8px; font-size:19px; font-weight:800; letter-spacing:-.3px; }
        .cet-modal-card p { margin:0 0 18px; font-size:14px; color:var(--muted); line-height:1.5; }
        .cet-modal-actions { display:flex; flex-direction:column; gap:9px; }
        .cet-modal-actions .cet-btn, .cet-modal-actions .cet-back { flex:none; width:100%; }

        .cet-hp { position:absolute; left:-9999px; width:1px; height:1px; overflow:hidden; }
        .cet-done { text-align:center; padding:30px 12px 34px; }
        .cet-done .tick { width:70px; height:70px; border-radius:50%; margin:0 auto 14px;
            background:radial-gradient(circle at 50% 35%, #34c56f, #1f8b4c); color:#fff; font-size:36px;
            display:grid; place-items:center; box-shadow:0 12px 30px -12px rgba(31,139,76,.7); }
        .cet-done h2 { margin:6px 0 6px; font-size:22px; font-weight:800; }
        .cet-ref { display:inline-block; margin-top:2px; font-weight:800; letter-spacing:1px;
            background:var(--cream); border:1px dashed var(--gold); border-radius:8px; padding:5px 12px; color:var(--ink); }
    </style>
</head>
<body>
    @php
        // Clean SVG silhouettes so cards look good before real photos are added.
        $saloon = '<svg viewBox="0 0 120 60" xmlns="http://www.w3.org/2000/svg"><path fill="#0b0b0c" d="M8 44c-3 0-5-2-5-5v-4c0-2 1-3 3-4l10-3 12-11c3-3 7-4 11-4h28c5 0 9 2 13 5l10 9 14 3c4 1 6 3 6 7v3c0 3-2 5-5 5h-6a10 10 0 01-20 0H34a10 10 0 01-20 0H8zm16-6a6 6 0 100 12 6 6 0 000-12zm64 0a6 6 0 100 12 6 6 0 000-12zM40 20l-9 9h26V20H40zm22 0v9h24l-8-7c-2-1-4-2-7-2H62z"/></svg>';
        $van = '<svg viewBox="0 0 120 60" xmlns="http://www.w3.org/2000/svg"><path fill="#0b0b0c" d="M6 46c-2 0-4-2-4-4V22c0-4 3-7 7-7h58c4 0 8 2 11 5l16 15 10 3c4 1 7 4 7 8v0c0 2-2 4-4 4h-7a10 10 0 01-20 0H33a10 10 0 01-20 0H6zm17-7a6 6 0 100 13 6 6 0 000-13zm64 0a6 6 0 100 13 6 6 0 000-13zM14 22v11h20V22H14zm28 0v11h20V22H42zm28 1v10h20l-12-8c-2-1-5-2-8-2z"/></svg>';
    @endphp
    <div class="cet-shell">
        <div class="cet-tagline-top">
            <div class="k">Central Executive Transfers</div>
            <div class="t">Book your executive journey</div>
            <div class="s">Sheffield's premium chauffeur service · Licensed Operator OP037</div>
        </div>

        <div class="cet-widget" id="cet-widget">
            <div class="cet-hero">
                <div class="cet-lang" translate="no">
                    <span class="ic">🌐</span>
                    <select id="cet-lang-select" aria-label="Choose language">
                        <option value="en">English</option>
                        <option value="pl">Polski</option>
                        <option value="ro">Română</option>
                        <option value="ur">اردو</option>
                        <option value="ar">العربية</option>
                        <option value="fr">Français</option>
                        <option value="es">Español</option>
                        <option value="de">Deutsch</option>
                        <option value="pt">Português</option>
                        <option value="it">Italiano</option>
                        <option value="zh-CN">中文</option>
                        <option value="hi">हिन्दी</option>
                    </select>
                </div>
                <div class="cet-brand">
                    <div class="cet-mark">C</div>
                    <div><div class="name">CENTRAL <span>EXECUTIVE</span></div><div class="sub">TRANSFERS · SHEFFIELD</div></div>
                </div>
                @if($done)
                    <h1>Thank you</h1><p>Your request is with our office.</p>
                @else
                    <h1>Book a journey</h1><p>Fixed prices, professional chauffeurs, confirmed by our office.</p>
                @endif
            </div>

        @if($done)
            <div class="cet-body">
                <div class="cet-done">
                    <div class="tick">✓</div>
                    @if(!empty($quoteRequest))
                        <h2>Quote request received</h2>
                        <p style="color:var(--muted);margin:0 0 6px">Thank you — we’ll email you a price for your chosen vehicle shortly.</p>
                    @else
                        <h2>Booking request received</h2>
                        <p style="color:var(--muted);margin:0 0 6px">Our office will confirm your journey and price shortly.</p>
                    @endif
                    @if(isset($ref))<div class="cet-ref">Ref {{ $ref }}</div>@endif
                    @if(!empty($quoteRequest))
                        <p class="cet-foot" style="margin-top:12px">No payment has been taken — this vehicle is quoted on request.</p>
                    @elseif(!empty($payUrl))
                        <a href="{{ $payUrl }}" target="_top" class="cet-btn" style="display:block;margin-top:18px;text-decoration:none;text-align:center">
                            Pay now to secure it{{ $payAmount ? ' · £'.number_format($payAmount, 0) : '' }}</a>
                        <p class="cet-foot" style="margin-top:8px">Secure card payment by Square.
                            @if(empty($payWanted))Prefer to pay later? No problem — we'll be in touch.@endif</p>
                    @else
                        <p class="cet-foot" style="margin-top:12px">No payment has been taken — our office will confirm your journey and price.</p>
                    @endif
                </div>
            </div>
        @else
            <div class="cet-steps" id="cet-steps">
                <div class="st active" data-for="1"><span class="num">1</span><span class="lbl">Journey</span></div>
                <div class="bar"></div>
                <div class="st" data-for="2"><span class="num">2</span><span class="lbl">Vehicle</span></div>
                <div class="bar"></div>
                <div class="st" data-for="3"><span class="num">3</span><span class="lbl">Details</span></div>
            </div>

            <div class="cet-body">
                <form id="cet-book" action="{{ route('widget.book.store') }}" method="POST" autocomplete="off">
                    @csrf
                    <div class="cet-hp"><label>Company<input name="company" tabindex="-1" autocomplete="off"></label></div>

                    {{-- STEP 1 — Journey --}}
                    <div class="cet-step" data-step="1">
                        <div class="cet-step-title">📍 Where and when?</div>
                        <div class="cet-banner" id="b-notice"></div>
                        <div class="cet-field"><label for="b-journey">Journey type</label>
                            <select id="b-journey" name="journey_type">
                                <option value="one_way">One way</option>
                                <option value="return">Return</option>
                                <option value="hourly">Hourly hire (as directed)</option>
                            </select>
                        </div>
                        <div class="cet-addr-row">
                            <div class="cet-field icon" style="flex:1;min-width:0"><label for="b-pickup">Pickup address</label>
                                <input id="b-pickup" name="pickup_address" required placeholder="Enter a pickup location" data-places data-postcode-target="#b-pickup-pc" autocomplete="off">
                                <button type="button" class="geo" data-geo="#b-pickup" data-geo-pc="#b-pickup-pc" aria-label="Clear address" title="Clear address">✕</button>
                            </div>
                            <div class="cet-field cet-pc"><label for="b-pickup-pc">Postcode</label>
                                <input id="b-pickup-pc" name="pickup_postcode" placeholder="" style="text-transform:uppercase" autocomplete="postal-code" inputmode="text">
                            </div>
                        </div>
                        <div class="cet-field" id="b-stops-field" data-stop-rate="{{ (float) ($sc['stopover'] ?? 0) }}">
                            <label>Extra stops <span class="opt">(optional)</span></label>
                            <div id="b-stops"></div>
                            <button type="button" id="b-add-stop" class="cet-addstop">＋ Add a stop</button>
                        </div>
                        <div class="cet-addr-row" id="b-dropoff-row">
                            <div class="cet-field icon" id="b-dropoff-field" style="flex:1;min-width:0"><label for="b-dropoff">Drop-off address</label>
                                <input id="b-dropoff" name="destination_address" required placeholder="Enter a dropoff location" data-places data-postcode-target="#b-dropoff-pc" autocomplete="off">
                                <button type="button" class="geo" data-geo="#b-dropoff" data-geo-pc="#b-dropoff-pc" aria-label="Clear address" title="Clear address">✕</button></div>
                            <div class="cet-field cet-pc" id="b-dropoff-pc-field"><label for="b-dropoff-pc">Postcode</label>
                                <input id="b-dropoff-pc" name="destination_postcode" placeholder="" style="text-transform:uppercase" autocomplete="postal-code" inputmode="text"></div>
                        </div>
                        <div class="cet-two">
                            <div class="cet-field"><label for="b-when">Date &amp; time</label>
                                <input id="b-when" name="pickup_at" type="datetime-local" required></div>
                            <div class="cet-field"><label for="b-pax">Passengers</label>
                                <select id="b-pax" name="passengers" required>
                                    @for($i = 1; $i <= 8; $i++)<option value="{{ $i }}">{{ $i }}</option>@endfor
                                </select></div>
                        </div>
                        <div class="cet-field" id="b-return-field" style="display:none">
                            <label for="b-return">Return date &amp; time</label>
                            <input id="b-return" name="return_pickup_at" type="datetime-local">
                        </div>
                        <div class="cet-field" id="b-hours-field" style="display:none">
                            <label for="b-hours">Hours booked</label>
                            <input id="b-hours" name="hours" type="number" min="1" max="24" value="3">
                            <span class="opt" style="font-size:12px">Car &amp; driver by the hour — no fixed destination. We'll confirm the price.</span>
                        </div>
                        <div class="cet-two">
                            <div class="cet-field"><label for="b-suit">Suitcases</label>
                                <select id="b-suit" name="suitcases">
                                    @for($i = 0; $i <= 8; $i++)<option value="{{ $i }}">{{ $i }}</option>@endfor
                                </select></div>
                            <div class="cet-field"><label for="b-hand">Hand luggage</label>
                                <select id="b-hand" name="hand_luggage">
                                    @for($i = 0; $i <= 8; $i++)<option value="{{ $i }}">{{ $i }}</option>@endfor
                                </select></div>
                        </div>

                        {{-- Business / VAT invoice — a required Yes/No choice. "Yes" adds
                             {{ $vatPercent ?? 20 }}% VAT and provides a VAT invoice to reclaim;
                             "No" is the standard price. Must be chosen before continuing. --}}
                        <div class="cet-field" id="b-vat-field">
                            <label>Do you need a business (VAT) invoice?</label>
                            <label class="cet-pay" id="b-vat-yes-wrap">
                                <input type="radio" name="vat_invoice" id="b-vat-yes" value="1">
                                <span class="t"><b>Yes</b><span class="opt"> — I’ll reclaim VAT ({{ $vatPercent ?? 20 }}% VAT added, VAT invoice provided)</span></span>
                            </label>
                            <label class="cet-pay" id="b-vat-no-wrap">
                                <input type="radio" name="vat_invoice" id="b-vat-no" value="0">
                                <span class="t"><b>No</b><span class="opt"> — standard price, no VAT reclaim needed</span></span>
                            </label>
                        </div>

                        <div class="cet-err" data-err="1"></div>
                        <div class="cet-actions">
                            <button type="button" class="cet-btn" data-next="2">Continue →</button>
                        </div>
                    </div>

                    {{-- STEP 2 — Vehicle --}}
                    <div class="cet-step" data-step="2" hidden>
                        <div class="cet-step-title">🚘 Choose your vehicle</div>
                        <div class="cet-vehs">
                            @foreach($vehicleTypes as $vt)
                                {{-- The standard 8-Seater and the XL are a swap-pair: only the
                                     one that fits the party/luggage is shown (see minibusToggle). --}}
                                <label class="cet-veh {{ $loop->first ? 'sel' : '' }}"
                                       data-id="{{ $vt->id }}" data-slug="{{ $vt->slug }}"
                                       data-cap-pax="{{ $vt->passenger_capacity }}" data-cap-lug="{{ $vt->luggage_capacity }}"
                                       data-cap-hand="{{ $vt->handLuggageCapacity() }}"
                                       @if($vt->slug === 'minibus-8-xl') hidden @endif>
                                    <input type="radio" name="vehicle_type_id" value="{{ $vt->id }}" {{ $loop->first ? 'checked' : '' }}>
                                    <div class="cet-veh-img">
                                        @if($vt->photoUrl())
                                            <img src="{{ $vt->photoUrl() }}" alt="{{ $vt->name }}" loading="lazy">
                                        @else
                                            {!! $vt->isLargeVehicle() ? $van : $saloon !!}
                                        @endif
                                    </div>
                                    <div class="cet-veh-meta">
                                        <div class="cet-veh-name">{{ $vt->name }}</div>
                                        @if($vt->tagline())<div class="cet-veh-tag">{{ $vt->tagline() }}</div>@endif
                                        <div class="cet-veh-cap">
                                            <span>👤 {{ $vt->passenger_capacity }} passengers</span>
                                            <span>🧳 {{ $vt->luggage_capacity }} suitcases</span>
                                            <span>👜 {{ $vt->handLuggageCapacity() }} hand luggage</span>
                                        </div>
                                        <div class="cet-veh-price"><span class="amt" data-price>—</span></div>
                                        <div class="cet-veh-warn">Too small for your group</div>
                                    </div>
                                    <div class="cet-veh-tick">✓</div>
                                </label>
                            @endforeach
                        </div>
                        <p class="cet-foot" style="margin-top:10px">Prices confirmed by our office before your journey.</p>
                        <div class="cet-actions">
                            <button type="button" class="cet-back" data-back="1">← Back</button>
                            <button type="button" class="cet-btn" data-next="3">Continue →</button>
                        </div>
                    </div>

                    {{-- STEP 3 — Details --}}
                    <div class="cet-step" data-step="3" hidden>
                        <div class="cet-step-title">👤 Your details</div>
                        {{-- Full journey summary (like ETO's confirmation) so the customer
                             sees everything — incl. luggage — before they book. --}}
                        <div class="cet-summary" id="cet-summary"></div>

                        @php $sc = $surcharges ?? []; $pw = $passwordLogin ?? false; @endphp
                        <input type="hidden" name="create_account" id="b-create" value="">

                        {{-- Book as guest / Sign in / Register (ETO-style tabs). --}}
                        @if($pw)
                        <div class="cet-authtabs" id="b-authtabs" role="tablist">
                            <button type="button" class="on" data-auth="guest">Book as guest</button>
                            <button type="button" data-auth="login">Sign in</button>
                            <button type="button" data-auth="register">Register</button>
                        </div>
                        <div id="b-signedin" class="cet-signedin" hidden>✓ Signed in as <strong id="b-me-name"></strong><span id="b-me-acct"></span></div>
                        @endif

                        {{-- SIGN IN panel --}}
                        <div id="b-login-panel" hidden>
                            <div class="cet-two">
                                <div class="cet-field"><label for="b-login-email">Email</label>
                                    <input id="b-login-email" type="email" autocomplete="email"></div>
                                <div class="cet-field"><label for="b-login-pass">Password</label>
                                    <input id="b-login-pass" type="password" autocomplete="current-password"></div>
                            </div>
                            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                                <button type="button" id="b-login-btn" class="cet-btn cet-btn-sm">Sign in</button>
                                <a class="cet-linkbtn" href="{{ route('widget.account.forgot') }}" target="_top">Forgot password?</a>
                            </div>
                            <div id="b-login-err" class="cet-bad-note" hidden style="margin-top:8px"></div>
                        </div>

                        {{-- GUEST / REGISTER — the actual booking contact details. --}}
                        <div id="b-guest-panel">
                            <div class="cet-field"><label for="b-name">Full name</label>
                                <input id="b-name" name="customer_name" required></div>
                            <div class="cet-two">
                                <div class="cet-field"><label for="b-phone">Mobile number</label>
                                    <input id="b-phone" name="customer_phone" placeholder="07…"></div>
                                <div class="cet-field"><label for="b-email">Email</label>
                                    <input id="b-email" name="customer_email" type="email"></div>
                            </div>
                            <div class="cet-field" id="b-reg-pass-field" hidden>
                                <label for="b-reg-pass">Choose a password</label>
                                <input id="b-reg-pass" name="password" type="password" minlength="8" placeholder="At least 8 characters" autocomplete="new-password" disabled>
                                <p class="opt" style="font-size:12px;margin:5px 0 0;color:var(--muted)">Save your details, track &amp; manage bookings and rebook in seconds.</p>
                            </div>

                            <label class="cet-check cet-check-sm" id="b-else-wrap" style="margin-top:10px">
                                <input type="checkbox" id="b-else" name="booking_for_other" value="1">
                                <span>I’m booking for someone else</span>
                            </label>
                            <div id="b-lead-block" hidden>
                                <div class="cet-two">
                                    <div class="cet-field"><label for="b-lead-name">Passenger’s full name</label>
                                        <input id="b-lead-name" name="lead_passenger_name" autocomplete="off"></div>
                                    <div class="cet-field"><label for="b-lead-phone">Passenger’s mobile</label>
                                        <input id="b-lead-phone" name="lead_passenger_phone" placeholder="07…" autocomplete="off"></div>
                                </div>
                                <p class="opt" style="font-size:12px;margin:2px 0 0;color:var(--muted)">This is the <strong>lead passenger</strong> — who the <strong>driver contacts</strong> on the day.</p>
                            </div>
                        </div>

                        {{-- Extras — compact. Meet & greet appears (pre-ticked) only for airport
                             journeys; child seats hide behind a tick, max 2 in total. --}}
                        <div class="cet-mini-title">Extras <span class="opt">(optional)</span></div>
                        <div class="cet-field" id="b-flight-field" hidden>
                            <label for="b-flight"><span id="b-flight-label">Flight number</span> <span class="opt" id="b-flight-opt">— for airport journeys (we track it)</span></label>
                            <input id="b-flight" name="flight_number" placeholder="e.g. BA1368" autocomplete="off" style="text-transform:uppercase">
                        </div>
                        <div class="cet-field" id="b-flight-landing-field" hidden>
                            <label for="b-flight-landing"><span id="b-flight-landing-label">Flight landing time</span> <span class="opt" id="b-flight-landing-opt">— when your flight is due to land</span></label>
                            <input id="b-flight-landing" name="flight_landing_at" type="datetime-local">
                        </div>
                        <div class="cet-xtras">
                            <label class="cet-check cet-check-sm" id="b-mg-wrap" hidden>
                                <input type="checkbox" id="b-meet-greet" name="meet_greet" value="1" data-extra="{{ (float) ($sc['meet_greet'] ?? 0) }}">
                                <span>Meet &amp; greet <span class="opt">— driver waits in arrivals with a name board</span></span>
                                @if(($sc['meet_greet'] ?? 0) > 0)<span class="px">+£{{ (int) ($sc['meet_greet']) }}</span>@endif
                            </label>
                            <label class="cet-check cet-check-sm" id="b-need-seats-wrap">
                                <input type="checkbox" id="b-need-seats" name="need_child_seat" value="1">
                                <span>Child / booster / infant seat</span>
                            </label>
                            <div id="b-seats-block" hidden>
                                <div class="cet-steppers">
                                    @foreach ([
                                        'child_seats'   => ['Child',   $sc['child_seat']   ?? 0],
                                        'booster_seats' => ['Booster', $sc['booster_seat'] ?? 0],
                                        'infant_seats'  => ['Infant',  $sc['infant_seat']  ?? 0],
                                    ] as $field => [$label, $unit])
                                        <div class="cet-stepper">
                                            <label for="b-{{ $field }}">{{ $label }}</label>
                                            <div class="ctrl">
                                                <button type="button" data-step-btn="-" aria-label="Less">−</button>
                                                <input id="b-{{ $field }}" name="{{ $field }}" type="number" min="0" max="2" value="0"
                                                       inputmode="numeric" data-extra="{{ (float) $unit }}" data-seat="1" readonly>
                                                <button type="button" data-step-btn="+" aria-label="More">+</button>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                                <p class="cet-foot" style="text-align:left;margin:8px 0 2px">Up to 2 seats in total (any mix).</p>
                            </div>
                            <label class="cet-check cet-check-sm">
                                <input type="checkbox" id="b-ribbons" name="ribbons" value="1" data-extra="{{ (float) ($sc['ribbons_car'] ?? 0) }}">
                                <span>Wedding ribbons</span>
                                @if(($sc['ribbons_car'] ?? 0) > 0)<span class="px">+£{{ (int) ($sc['ribbons_car']) }}</span>@endif
                            </label>
                        </div>

                        <div class="cet-field" style="margin-top:14px"><label for="b-notes">Comments <span class="opt">(optional)</span></label>
                            <textarea id="b-notes" name="notes" rows="2" placeholder="Anything else we should know…"></textarea></div>

                        {{-- Payment method (like ETO): pay by card online, or cash to the
                             driver on the day. Hidden entirely for a quote-only vehicle
                             (Rolls Royce / on request) — then it's a request-a-quote. --}}
                        <div id="b-payment-section">
                        <div class="cet-mini-title">💳 Payment method</div>
                        <label class="cet-pay" id="b-pay-card-wrap">
                            <input type="radio" name="payment_method" id="b-pay-card" value="card" checked>
                            <span class="t"><b>Credit or debit card</b><span class="opt"> — pay online to secure your booking</span></span>
                        </label>
                        <label class="cet-pay" id="b-pay-cash-wrap">
                            <input type="radio" name="payment_method" id="b-pay-cash" value="cash">
                            <span class="t"><b>Cash</b><span class="opt"> — pay the driver on the day</span></span>
                        </label>
                        {{-- Account (monthly invoice) — revealed ONLY after signing in as a
                             recognised business account (see signIn() below); a guest or a
                             personal customer never sees it, and the server refuses an
                             "account" choice that isn't a signed-in business account. No
                             payment is taken; the job is stored pending and billed on the account. --}}
                        <label class="cet-pay" id="b-pay-account-wrap" hidden>
                            <input type="radio" name="payment_method" id="b-pay-account" value="account">
                            <span class="t"><b>Account</b> <span class="opt">— <span id="b-account-name">on account</span>, invoiced monthly (no payment now)</span></span>
                        </label>
                        </div>{{-- /#b-payment-section --}}
                        <p class="cet-foot" id="b-quote-note" hidden style="margin:6px 0 0;text-align:left">This vehicle is quoted on request — no payment now. Give us your details and we’ll email you a price.</p>

                        <div class="cet-field" style="margin-top:14px"><label for="b-voucher">Discount code <span class="opt">(optional)</span></label>
                            <input id="b-voucher" name="voucher" placeholder="" autocomplete="off" style="text-transform:uppercase;max-width:260px"></div>

                        <div class="cet-total" id="b-total">
                            <span class="lbl">Total price</span>
                            <span class="amt" id="b-total-amt">—</span>
                        </div>

                        <label class="cet-agree"><input type="checkbox" id="b-terms" name="accept_terms" value="1">
                            <span>I accept the <a href="{{ config('cet.links.terms') }}" target="_blank" rel="noopener">Terms &amp; Conditions</a>
                            and <a href="{{ config('cet.links.cancellation') }}" target="_blank" rel="noopener">Cancellation Policy</a></span></label>
                        <label class="cet-agree"><input type="checkbox" id="b-privacy" name="accept_privacy" value="1">
                            <span>I accept the <a href="{{ config('cet.links.privacy') }}" target="_blank" rel="noopener">Privacy Policy</a></span></label>

                        <div class="cet-err" data-err="3"></div>
                        <div class="cet-actions">
                            <button type="button" class="cet-back" data-back="2">← Back</button>
                            <button class="cet-btn" type="submit" id="cet-submit">Book now</button>
                        </div>
                        <p class="cet-foot" id="b-pay-note">Pay securely by card to confirm — or choose cash and our office will confirm your journey.</p>
                    </div>
                </form>
            </div>
        @endif
        </div>
    </div>

    {{-- "Add a return?" prompt — offered once when a one-way booking moves on. --}}
    <div class="cet-modal" id="b-return-modal" hidden>
        <div class="cet-modal-card">
            <div class="cet-modal-emoji">↩️</div>
            <h3>Travelling back too?</h3>
            <p>Would you like to add a return journey? Booking both legs together means one confirmed price and driver for your whole trip.</p>
            <div class="cet-modal-actions">
                <button type="button" class="cet-btn" id="b-return-yes">Yes, add a return</button>
                <button type="button" class="cet-back" id="b-return-no">No, one way only</button>
            </div>
        </div>
    </div>

    {{-- Free Google website translation, driven by the styled switcher in the hero.
         Google's own widget is hidden; our <select> sets the language. --}}
    <div id="google_translate_element" translate="no"></div>
    <script>
        window.googleTranslateElementInit = function () {
            try { new google.translate.TranslateElement({ pageLanguage: 'en', autoDisplay: false }, 'google_translate_element'); } catch (e) {}
        };
        (function () {
            var sel = document.getElementById('cet-lang-select');
            if (!sel) return;
            // Reflect the current language (from Google's googtrans cookie) in the switcher.
            try {
                var m = document.cookie.match(/googtrans=\/[^/]*\/([^;]+)/);
                if (m && m[1]) sel.value = decodeURIComponent(m[1]);
            } catch (e) {}
            function apply(code, tries) {
                var combo = document.querySelector('.goog-te-combo');
                if (!combo) { if ((tries || 0) < 40) return setTimeout(function () { apply(code, (tries || 0) + 1); }, 150); return; }
                combo.value = code;
                combo.dispatchEvent(new Event('change'));
                setTimeout(function () { try { parent.postMessage({ cetWidgetHeight: document.getElementById('cet-widget').offsetHeight + 24 }, '*'); } catch (e) {} }, 400);
            }
            sel.addEventListener('change', function () { apply(sel.value, 0); });
        })();
    </script>
    <script src="https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit" defer></script>

    {{-- Google address autocomplete via the server proxy (key stays server-side). --}}
    <script>window.CET_PLACES_URL = "{{ route('public.book.places') }}";
        window.CET_ADDRESSES_URL = "{{ route('public.book.addresses') }}";
        window.CET_RESOLVE_URL = "{{ route('public.book.resolve') }}";
        window.CET_REVERSE_URL = "{{ route('public.book.reverse') }}";
        window.CET_ACCOUNT_CHECK_URL = "{{ route('widget.account-check') }}";
        window.CET_LOGIN_URL = "{{ route('widget.login') }}";
        window.CET_ME = {!! json_encode($me ?? null) !!};
        window.CET_ME_ACCOUNT = {!! json_encode($meAccount ?? null) !!};</script>
    <script src="{{ asset('js/cet-forms.js') }}?v=35" defer></script>

    <script>
        (function () {
            try { if (window.self === window.top) document.documentElement.classList.add('standalone'); }
            catch (e) { document.documentElement.classList.add('standalone'); }

            function reportHeight() {
                try { parent.postMessage({ cetWidgetHeight: document.getElementById('cet-widget').offsetHeight + 24 }, '*'); } catch (e) {}
            }
            window.addEventListener('load', reportHeight);
            window.addEventListener('resize', reportHeight);

            var form = document.getElementById('cet-book');
            if (!form) return;

            // The pickup postcode is now a live Google type-ahead (see
            // data-postcode-fill in cet-forms.js): as the customer types their
            // postcode, Google suggests matching addresses and picking one fills
            // the pickup address + back-fills the postcode. No button needed.

            // Minimum notice for online bookings — the date shown starts at now + N
            // hours, and anything sooner is rejected with the same message as ETO.
            var MIN_LEAD_H = {{ (int) config('cet.public_min_lead_hours', 8) }};
            var NOTICE_MSG = 'Sorry — we need at least ' + MIN_LEAD_H + ' hours’ notice for online bookings. '
                + 'For a journey sooner than that, please call our office on +44 7405 172435 and we’ll be glad to help.';
            function pad2(n){ return (n < 10 ? '0' : '') + n; }
            function fmtLocal(d){ return d.getFullYear()+'-'+pad2(d.getMonth()+1)+'-'+pad2(d.getDate())+'T'+pad2(d.getHours())+':'+pad2(d.getMinutes()); }
            function earliestAllowed(){ var e = new Date(Date.now() + MIN_LEAD_H*3600*1000); e.setMinutes(Math.ceil(e.getMinutes()/15)*15, 0, 0); return e; }
            var whenEl = document.getElementById('b-when');
            var retEl = document.getElementById('b-return');
            (function () {
                var earliest = earliestAllowed();
                if (whenEl) { whenEl.min = fmtLocal(earliest); if (!whenEl.value) whenEl.value = fmtLocal(earliest); }
                if (retEl) { retEl.min = fmtLocal(earliest); }
            })();
            var noticeEl = document.getElementById('b-notice');
            function showNotice(on){ if (noticeEl) { noticeEl.textContent = on ? NOTICE_MSG : ''; noticeEl.classList.toggle('show', !!on); } }
            // True when the chosen pickup is too soon (inside the notice window).
            function tooSoon(){
                if (!whenEl || !whenEl.value) return false;
                var picked = new Date(whenEl.value);
                return !isNaN(picked) && picked.getTime() < earliestAllowed().getTime();
            }
            if (whenEl) { whenEl.addEventListener('change', function () { showNotice(tooSoon()); }); }

            var tokenEl = document.querySelector('meta[name="csrf-token"]');
            var token = tokenEl ? tokenEl.getAttribute('content') : '';
            var steps = Array.prototype.slice.call(form.querySelectorAll('.cet-step'));
            var stepEls = Array.prototype.slice.call(document.querySelectorAll('#cet-steps .st'));
            var bars = Array.prototype.slice.call(document.querySelectorAll('#cet-steps .bar'));

            function showErr(step, msg) {
                var box = form.querySelector('[data-err="' + step + '"]');
                if (box) { box.textContent = msg; box.style.display = msg ? 'block' : 'none'; }
            }

            function goTo(n) {
                steps.forEach(function (s) { s.hidden = (+s.dataset.step !== n); });
                stepEls.forEach(function (s) {
                    var f = +s.dataset.for;
                    s.classList.toggle('active', f === n);
                    s.classList.toggle('done', f < n);
                });
                bars.forEach(function (b, i) { b.classList.toggle('fill', i < n - 1); });
                try { document.getElementById('cet-widget').scrollIntoView({ behavior:'smooth', block:'start' }); } catch (e) {}
                reportHeight();
            }

            function nonEmpty(el) { return el && String(el.value || '').trim() !== ''; }

            // Journey type toggles: return date, hours, and whether drop-off is needed.
            var journeyEl = document.getElementById('b-journey');
            var returnField = document.getElementById('b-return-field');
            var hoursField = document.getElementById('b-hours-field');
            var dropRow = document.getElementById('b-dropoff-row');
            function toggleJourney() {
                var v = journeyEl ? journeyEl.value : 'one_way';
                if (returnField) returnField.style.display = v === 'return' ? '' : 'none';
                if (hoursField) hoursField.style.display = v === 'hourly' ? '' : 'none';
                if (dropRow) dropRow.style.display = v === 'hourly' ? 'none' : '';
                var stopsField = document.getElementById('b-stops-field');
                if (stopsField) stopsField.style.display = v === 'hourly' ? 'none' : '';
                // No cash bookings on return journeys — hide Cash, force card (or account).
                applyReturnPaymentRule();
            }
            if (journeyEl) { journeyEl.addEventListener('change', toggleJourney); }

            // Cash is not offered on return journeys OR on VAT-invoice bookings (both
            // must pay by card, or on account if signed in — VAT bookings are always
            // card). Hide the Cash option and move any cash selection to Card. Called
            // on journey-type / VAT change and after payment wiring is ready.
            function applyReturnPaymentRule() {
                var isReturn = journeyEl && journeyEl.value === 'return';
                var vatYesEl = document.getElementById('b-vat-yes');
                var vatOn = !!(vatYesEl && vatYesEl.checked);
                var noCash = isReturn || vatOn;
                var cashWrap = document.getElementById('b-pay-cash-wrap');
                var cashRadio = document.getElementById('b-pay-cash');
                var cardRadio = document.getElementById('b-pay-card');
                if (cashWrap) cashWrap.hidden = noCash;
                if (cashRadio) cashRadio.disabled = noCash;
                if (noCash && cashRadio && cashRadio.checked && cardRadio) {
                    cardRadio.checked = true;
                    if (typeof syncPay === 'function') syncPay();
                }
            }
            toggleJourney();

            // "Add a stop" — via points on the main page. Each is a Google-autocomplete
            // address the driver must call at between pickup and drop-off; the count
            // drives the per-stop surcharge in the live total.
            (function () {
                var wrap = document.getElementById('b-stops');
                var addBtn = document.getElementById('b-add-stop');
                if (!wrap || !addBtn) return;
                var MAX_STOPS = 6;
                function refresh() { if (typeof fillSummary === 'function') fillSummary(); addBtn.disabled = wrap.children.length >= MAX_STOPS; }
                window.__cetCountStops = function () {
                    var n = 0;
                    wrap.querySelectorAll('input[name="stops[]"]').forEach(function (i) { if (String(i.value || '').trim() !== '') n++; });
                    return n;
                };
                addBtn.addEventListener('click', function () {
                    if (wrap.children.length >= MAX_STOPS) return;
                    var row = document.createElement('div'); row.className = 'cet-stop-row';
                    var pin = document.createElement('span'); pin.className = 'pin'; pin.textContent = '➕';
                    var input = document.createElement('input');
                    input.name = 'stops[]'; input.placeholder = 'Stop address…'; input.autocomplete = 'off';
                    input.setAttribute('data-places', '');
                    input.setAttribute('data-postcode-target', ''); // no linked postcode box for stops
                    var x = document.createElement('button'); x.type = 'button'; x.className = 'cet-stop-x'; x.textContent = '✕'; x.setAttribute('aria-label', 'Remove stop');
                    x.addEventListener('click', function () { row.remove(); refresh(); reportHeight(); });
                    input.addEventListener('input', refresh);
                    input.addEventListener('change', refresh);
                    row.appendChild(pin); row.appendChild(input); row.appendChild(x);
                    wrap.appendChild(row);
                    if (window.CETattachPlaces) window.CETattachPlaces(input);
                    input.focus(); refresh(); reportHeight();
                });
            })();

            // "Use my current location" buttons on the pickup / drop-off fields —
            // like ETO's crosshair. Uses the browser's geolocation, then reverse-
            // geocodes the coordinates on the server (Google key stays server-side).
            // Clear (✕) button inside each address field: shown only when the field
            // has text, taps to clear the address (and its postcode). No location dot.
            form.querySelectorAll('.geo[data-geo]').forEach(function (btn) {
                var addr = document.querySelector(btn.dataset.geo);
                var pc = btn.dataset.geoPc ? document.querySelector(btn.dataset.geoPc) : null;
                if (!addr) return;
                btn.textContent = '✕';
                btn.classList.add('is-clear');
                function sync() { btn.style.display = String(addr.value || '').trim() !== '' ? '' : 'none'; }
                addr.addEventListener('input', sync);
                addr.addEventListener('change', sync);
                sync();
                btn.addEventListener('click', function () {
                    addr.value = ''; addr.dispatchEvent(new Event('change'));
                    if (pc) { pc.value = ''; pc.dispatchEvent(new Event('change')); }
                    sync(); addr.focus();
                });
            });

            function validateStep(n) {
                showErr(n, '');
                if (n === 1) {
                    var jt = journeyEl ? journeyEl.value : 'one_way';
                    var ok = true, first = null;
                    var required = [['b-pickup','pickup'],['b-when','date & time']];
                    if (jt !== 'hourly') required.push(['b-dropoff','drop-off']);
                    if (jt === 'return') required.push(['b-return','return date & time']);
                    if (jt === 'hourly') required.push(['b-hours','hours']);
                    required.forEach(function (p) {
                        var el = document.getElementById(p[0]);
                        var bad = !nonEmpty(el);
                        if (el) el.closest('.cet-field').classList.toggle('bad', bad);
                        if (bad && !first) first = el;
                        if (bad) ok = false;
                    });
                    if (!ok) { showErr(1, 'Please fill in the highlighted journey details.'); if (first) first.focus(); }
                    // Postcode is required only for a HOME/street pickup that's missing
                    // one — a driver needs it. Airports/venues (residential !== '1') and
                    // addresses that already resolved a postcode are never blocked.
                    if (ok) {
                        var puEl = document.getElementById('b-pickup');
                        var puPc = document.getElementById('b-pickup-pc');
                        if (puEl && puPc && puEl.dataset.residential === '1' && !nonEmpty(puPc)) {
                            puPc.closest('.cet-field').classList.add('bad');
                            showErr(1, 'Please add the pickup postcode so your driver can find the address.');
                            puPc.focus();
                            return false;
                        }
                    }
                    // Business (VAT) invoice — a Yes/No answer is required before moving on.
                    var vatYesEl = document.getElementById('b-vat-yes'), vatNoEl = document.getElementById('b-vat-no');
                    var vatChosen = (vatYesEl && vatYesEl.checked) || (vatNoEl && vatNoEl.checked);
                    var vatField = document.getElementById('b-vat-field');
                    if (vatField) vatField.classList.toggle('bad', !vatChosen);
                    if (ok && !vatChosen) {
                        showErr(1, 'Please choose Yes or No for a business (VAT) invoice.');
                        if (vatField) vatField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        return false;
                    }
                    // Minimum-notice guard — same message as ETO. Show it BOTH at the top
                    // banner AND right by the Continue button so it's always on screen.
                    if (ok && tooSoon()) {
                        showNotice(true);
                        showErr(1, NOTICE_MSG);
                        var w = document.getElementById('b-when'); if (w) { w.closest('.cet-field').classList.add('bad'); }
                        var eb = form.querySelector('[data-err="1"]'); if (eb) eb.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        return false;
                    }
                    showNotice(false);
                    return ok;
                }
                if (n === 3) {
                    // On the "Sign in" tab without signing in? Nudge them.
                    var auth = window.__cetAuthState ? window.__cetAuthState() : { onLogin: false };
                    if (auth.onLogin) { showErr(3, 'Please sign in, or choose “Book as guest”.'); return false; }
                    var nameEl = document.getElementById('b-name');
                    var phone = document.getElementById('b-phone'), email = document.getElementById('b-email');
                    var okName = nonEmpty(nameEl);
                    nameEl.closest('.cet-field').classList.toggle('bad', !okName);
                    // BOTH a mobile number and an email are required.
                    var okPhone = nonEmpty(phone), okEmail = nonEmpty(email);
                    phone.closest('.cet-field').classList.toggle('bad', !okPhone);
                    email.closest('.cet-field').classList.toggle('bad', !okEmail);
                    if (!okName) { showErr(3, 'Please enter your name.'); nameEl.focus(); return false; }
                    if (!okPhone) { showErr(3, 'Please enter your mobile number.'); phone.focus(); return false; }
                    if (!okEmail) { showErr(3, 'Please enter your email address.'); email.focus(); return false; }
                    // Flight number is required for airport journeys (arrivals we track).
                    var flightField = document.getElementById('b-flight-field'), flightIn = document.getElementById('b-flight');
                    if (flightIn && flightIn.dataset.required === '1' && !nonEmpty(flightIn)) {
                        if (flightField) flightField.classList.add('bad');
                        showErr(3, 'Please enter the flight number for your airport journey.'); flightIn.focus(); return false;
                    }
                    // Flight landing time is required on airport journeys too.
                    var landField = document.getElementById('b-flight-landing-field'), landIn = document.getElementById('b-flight-landing');
                    if (landIn && landIn.dataset.required === '1' && !nonEmpty(landIn)) {
                        if (landField) landField.classList.add('bad');
                        showErr(3, 'Please enter your flight’s landing time so we can track it.'); landIn.focus(); return false;
                    }
                    // Registering — need a password of at least 8 characters.
                    var regPass = document.getElementById('b-reg-pass');
                    if (regPass && !regPass.disabled && (regPass.value || '').length < 8) {
                        regPass.closest('.cet-field').classList.add('bad');
                        showErr(3, 'Please choose a password of at least 8 characters, or switch to “Book as guest”.'); regPass.focus(); return false;
                    }
                    var elseBox = document.getElementById('b-else'), leadName = document.getElementById('b-lead-name');
                    if (elseBox && elseBox.checked && leadName && !nonEmpty(leadName)) {
                        leadName.closest('.cet-field').classList.add('bad');
                        showErr(3, 'Please enter the passenger’s name.'); leadName.focus(); return false;
                    }
                    var terms = document.getElementById('b-terms'), privacy = document.getElementById('b-privacy');
                    if ((terms && !terms.checked) || (privacy && !privacy.checked)) {
                        showErr(3, 'Please accept the Terms & Conditions and Privacy Policy to continue.');
                        return false;
                    }
                    return true;
                }
                return true;
            }

            // Advance to a step, running that step's setup.
            function proceedTo(next) {
                goTo(next);
                if (next === 2) { minibusToggle(); fitVehicles(); loadPrices(); }
                if (next === 3) { applyAirportMeetGreet(); syncQuoteMode(); fillSummary(); }
            }

            // One-way "are you sure you don't want a return?" prompt — offered once.
            var returnPrompted = false;
            var returnModal = document.getElementById('b-return-modal');
            function closeReturnModal() { if (returnModal) returnModal.hidden = true; }
            (function () {
                var yes = document.getElementById('b-return-yes');
                var no = document.getElementById('b-return-no');
                if (yes) yes.addEventListener('click', function () {
                    // Switch to a return booking and let them fill the return date/time
                    // (the return uses the same addresses, reversed) before moving on.
                    if (journeyEl) { journeyEl.value = 'return'; toggleJourney(); }
                    closeReturnModal();
                    var rf = document.getElementById('b-return-field');
                    var ri = document.getElementById('b-return');
                    if (rf) rf.style.display = '';
                    try { (rf || ri).scrollIntoView({ behavior:'smooth', block:'center' }); } catch (e) {}
                    if (ri) ri.focus();
                    reportHeight();
                });
                if (no) no.addEventListener('click', function () { closeReturnModal(); proceedTo(2); });
            })();

            form.querySelectorAll('[data-next]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var cur = +btn.closest('.cet-step').dataset.step;
                    if (!validateStep(cur)) return;
                    var next = +btn.dataset.next;
                    // Leaving the journey step on a one-way trip? Offer a return once.
                    if (cur === 1 && next === 2 && journeyEl && journeyEl.value === 'one_way' && !returnPrompted) {
                        returnPrompted = true;
                        if (returnModal) { returnModal.hidden = false; return; }
                    }
                    proceedTo(next);
                });
            });
            form.querySelectorAll('[data-back]').forEach(function (btn) {
                btn.addEventListener('click', function () { goTo(+btn.dataset.back); });
            });

            // Vehicle card selection + per-card pricing.
            var priceById = {};   // vehicle id -> { formatted, price, poa }
            var pricesFor = '';   // the pickup|dropoff we last priced, to avoid refetch
            var lastQuote = null; // the selected vehicle's quote (for the summary)

            // Fill the booking summary shown on the details step (all info + luggage).
            function fillSummary() {
                var box = document.getElementById('cet-summary');
                if (!box) return;
                function val(id){ var el=document.getElementById(id); return el ? String(el.value||'').trim() : ''; }
                function when(){ var v=val('b-when'); if(!v) return '—'; var d=new Date(v); if(isNaN(d)) return v;
                    return d.toLocaleDateString('en-GB',{weekday:'short',day:'2-digit',month:'short',year:'numeric'})+' · '+
                           d.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'}); }
                var vehCard = form.querySelector('input[name="vehicle_type_id"]:checked');
                var vehName = vehCard ? (vehCard.closest('.cet-veh').querySelector('.cet-veh-name')||{}).textContent : '';
                var jt = journeyEl ? journeyEl.value : 'one_way';
                var dropoff = jt === 'hourly' ? 'As directed (hourly hire)' : (val('b-dropoff') || '—');
                function row(k,v){ return '<div class="row"><span class="k">'+k+'</span><span class="v">'+(v||'—')+'</span></div>'; }
                var stopVals = [];
                document.querySelectorAll('#b-stops input[name="stops[]"]').forEach(function (i) {
                    var v = String(i.value || '').trim(); if (v) stopVals.push(v);
                });
                var html = '<h4>Your journey</h4>'
                    + row('Date &amp; time', when())
                    + row('Vehicle', vehName || '—')
                    + row('Pick-up', val('b-pickup'))
                    + (stopVals.length ? row(stopVals.length > 1 ? 'Stops' : 'Stop', stopVals.join(' · ')) : '')
                    + row('Drop-off', dropoff)
                    + row('Passengers', val('b-pax') || '—')
                    + row('Suitcases', val('b-suit') || '0')
                    + row('Hand luggage', val('b-hand') || '0');
                var elseBox2 = document.getElementById('b-else');
                if (elseBox2 && elseBox2.checked && val('b-lead-name')) html += row('Passenger', val('b-lead-name'));
                if (val('b-flight')) html += row('Flight', val('b-flight').toUpperCase());
                if (val('b-flight-landing')) {
                    var ld = new Date(val('b-flight-landing'));
                    html += row('Lands', isNaN(ld) ? val('b-flight-landing')
                        : ld.toLocaleDateString('en-GB',{day:'2-digit',month:'short'})+' · '+ld.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'}));
                }
                var items = extrasList();
                items.forEach(function (i) { html += row(i.label, i.amount > 0 ? money(i.amount) : 'included'); });
                var vat = vatOn();
                if (lastQuote && !lastQuote.poa && lastQuote.price != null) {
                    var net = Number(lastQuote.price) + mgFee() + extrasTotal();
                    if (vat) html += row('VAT (' + vatPercent + '%)', money(net * vatPercent / 100));
                    var totNotes = [];
                    if (vat) totNotes.push('inc. VAT');
                    if (mgFee() > 0) totNotes.push('including meet &amp; greet');
                    html += '<div class="row tot"><span class="k">Total</span><span class="v">' + money(currentTotal())
                        + (totNotes.length ? ' <span style="font-size:11px;color:var(--muted-2)">' + totNotes.join(' · ') + '</span>' : '') + '</span></div>';
                } else if (lastQuote && lastQuote.formatted) {
                    html += '<div class="row tot"><span class="k">Total</span><span class="v">' + lastQuote.formatted + '</span></div>';
                }
                box.innerHTML = html;
                updateTotal();
            }
            form.querySelectorAll('.cet-veh').forEach(function (card) {
                card.addEventListener('click', function () {
                    if (card.hidden || card.classList.contains('unfit')) return;
                    selectCard(card);
                });
            });

            // Grey out any vehicle too small for the party/luggage and make sure the
            // selected one always fits — auto-picking the first that does.
            function fitVehicles() {
                function num(id){ var el=document.getElementById(id); return el ? (parseInt(el.value,10)||0) : 0; }
                var pax = num('b-pax'), suit = num('b-suit'), hand = num('b-hand');
                var firstFit = null;
                form.querySelectorAll('.cet-veh').forEach(function (card) {
                    if (card.hidden) return;
                    var capPax = parseInt(card.dataset.capPax, 10) || 0;
                    var capLug = parseInt(card.dataset.capLug, 10) || 0;
                    var capHand = parseInt(card.dataset.capHand, 10) || 0;
                    var fits = pax <= capPax && suit <= capLug && hand <= capHand;
                    card.classList.toggle('unfit', !fits);
                    var input = card.querySelector('input'); if (input) input.disabled = !fits;
                    if (fits && !firstFit) firstFit = card;
                });
                if (!firstFit) {
                    // Nothing fits (rare) — don't lock the customer out; allow all and
                    // pick the biggest, the office will sort the details.
                    var big = null, bigCap = -1;
                    form.querySelectorAll('.cet-veh').forEach(function (card) {
                        card.classList.remove('unfit');
                        var i = card.querySelector('input'); if (i) i.disabled = false;
                        if (card.hidden) return;
                        var c = parseInt(card.dataset.capPax, 10) || 0;
                        if (c > bigCap) { bigCap = c; big = card; }
                    });
                    if (big) selectCard(big);
                    return;
                }
                var sel = form.querySelector('.cet-veh.sel');
                if (!sel || sel.hidden || sel.classList.contains('unfit')) selectCard(firstFit);
            }

            function selectCard(card) {
                form.querySelectorAll('.cet-veh').forEach(function (c) { c.classList.remove('sel'); });
                card.classList.add('sel');
                var input = card.querySelector('input'); if (input) input.checked = true;
                var q = priceById[card.dataset.id];
                lastQuote = q ? { formatted: q.poa ? 'On request' : q.formatted, price: q.price, poa: !!q.poa } : null;
            }

            // ETO-style extras: +/- steppers, the meet & greet toggle, and a live
            // total (vehicle + extras + VAT when a business invoice is wanted).
            function money(n){ return '£' + Number(n).toLocaleString('en-GB',{minimumFractionDigits:0,maximumFractionDigits:0}); }
            function vatOn(){ var y = document.getElementById('b-vat-yes'); return !!(y && y.checked); }
            function withVat(base){ return vatOn() ? Math.round(base * (1 + vatPercent / 100)) : Math.round(base); }
            // Meet & greet is MANDATORY on an airport arrival (a one-way airport pickup,
            // or any return touching an airport) — the driver waits in arrivals with a
            // name board. It's baked into the price (shown on the vehicle cards and the
            // total), never offered as a removable tick, so it can't be dropped.
            function mgApplies() {
                var jt = journeyEl ? journeyEl.value : 'one_way';
                var isReturn = jt === 'return';
                return isAirportPickup() || (isReturn && (isAirportPickup() || isAirportDropoff()));
            }
            function mgFee() {
                return (mgApplies() && mg) ? (parseFloat(mg.dataset.extra) || 0) : 0;
            }
            // The all-in total for the selected vehicle, or null when it's on request.
            function currentTotal() {
                if (!lastQuote || lastQuote.poa || lastQuote.price == null) return null;
                return withVat(Number(lastQuote.price) + mgFee() + extrasTotal());
            }
            // The prominent Total-price band on the details step.
            function updateTotal() {
                var el = document.getElementById('b-total-amt'); if (!el) return;
                var t = currentTotal();
                if (t == null) { el.innerHTML = 'Office to confirm'; return; }
                var notes = [];
                if (vatOn()) notes.push('inc. VAT');
                if (mgFee() > 0) notes.push('incl. meet &amp; greet');
                el.innerHTML = money(t) + (notes.length ? ' <small>' + notes.join(' · ') + '</small>' : '');
            }
            // Put the current price on each vehicle card (VAT added when wanted). Airport
            // arrivals include the mandatory meet & greet in the shown price.
            function renderPrices() {
                form.querySelectorAll('.cet-veh').forEach(function (card) {
                    var el = card.querySelector('[data-price]'); if (!el) return;
                    var o = priceById[card.dataset.id];
                    if (!o) { el.textContent = ''; el.classList.remove('poa'); return; }
                    if (o.poa || o.price == null) { el.classList.add('poa'); el.textContent = 'On request'; return; }
                    el.classList.remove('poa');
                    el.textContent = money(withVat(o.price + mgFee()));
                });
            }
            // Total child/booster/infant seats we can carry across all three types.
            var MAX_SEATS = 2;
            function seatTotal() {
                var t = 0;
                ['b-child_seats','b-booster_seats','b-infant_seats'].forEach(function (id) {
                    var el = document.getElementById(id); if (el) t += parseInt(el.value, 10) || 0;
                });
                return t;
            }
            form.querySelectorAll('[data-step-btn]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var input = btn.parentElement.querySelector('input');
                    if (!input) return;
                    var v = parseInt(input.value, 10) || 0;
                    var up = btn.dataset.stepBtn === '+';
                    // Child/booster/infant are capped at MAX_SEATS in total.
                    if (up && input.dataset.seat && seatTotal() >= MAX_SEATS) return;
                    var min = parseInt(input.min, 10) || 0, max = parseInt(input.max, 10) || 10;
                    v += (up ? 1 : -1);
                    v = Math.max(min, Math.min(max, v));
                    input.value = v;
                    fillSummary();
                });
            });

            // Reveal handlers: "booking for someone else" and "I require a child seat".
            var elseBox = document.getElementById('b-else'), leadBlock = document.getElementById('b-lead-block');
            if (elseBox) { elseBox.addEventListener('change', function () {
                document.getElementById('b-else-wrap').classList.toggle('on', elseBox.checked);
                if (leadBlock) leadBlock.hidden = !elseBox.checked;
                reportHeight(); fillSummary();
            }); }
            var needSeats = document.getElementById('b-need-seats'), seatsBlock = document.getElementById('b-seats-block');
            if (needSeats) { needSeats.addEventListener('change', function () {
                document.getElementById('b-need-seats-wrap').classList.toggle('on', needSeats.checked);
                if (seatsBlock) seatsBlock.hidden = !needSeats.checked;
                if (!needSeats.checked) { // clear seat counts when hidden
                    ['b-child_seats','b-booster_seats','b-infant_seats'].forEach(function (id) {
                        var el = document.getElementById(id); if (el) el.value = 0;
                    });
                }
                reportHeight(); fillSummary();
            }); }

            var mg = document.getElementById('b-meet-greet');
            if (mg) { mg.addEventListener('change', function () {
                var w = document.getElementById('b-mg-wrap'); if (w) w.classList.toggle('on', mg.checked);
                fillSummary();
            }); }

            // Meet & greet is only relevant when picking a passenger up FROM an
            // airport — shown (and pre-ticked) then, hidden otherwise.
            function isAirportJourney() {
                var pu = (document.getElementById('b-pickup') || {}).value || '';
                var dp = (document.getElementById('b-dropoff') || {}).value || '';
                var jt = journeyEl ? journeyEl.value : 'one_way';
                var hay = (jt === 'return') ? (pu + ' ' + dp) : pu; // return legs pick up from either end
                hay = hay.toLowerCase();
                return /\bairport\b|terminal|heathrow|gatwick|stansted|luton|manchester airport|\bt1\b|\bt2\b|\bt3\b|\bt5\b/.test(hay);
            }
            // True when the journey is an airport RETURN (return trip touching an airport).
            function isAirportReturn() {
                var jt = journeyEl ? journeyEl.value : 'one_way';
                return jt === 'return' && isAirportJourney();
            }
            function airportIn(v) {
                return /\bairport\b|terminal|heathrow|gatwick|stansted|luton|manchester airport|\bt1\b|\bt2\b|\bt3\b|\bt5\b/.test(String(v || '').toLowerCase());
            }
            function isAirportPickup() { return airportIn((document.getElementById('b-pickup') || {}).value); }
            function isAirportDropoff() { return airportIn((document.getElementById('b-dropoff') || {}).value); }

            function applyAirportMeetGreet() {
                var jt = journeyEl ? journeyEl.value : 'one_way';
                var isReturn = jt === 'return';
                var puAir = isAirportPickup();
                var dpAir = isAirportDropoff();
                var anyAir = puAir || dpAir;
                // "arrival" = we meet them off a flight: a one-way airport pickup, or ANY
                // return touching an airport (they land on the way back).
                var arrival = puAir || (isReturn && anyAir);

                var wrap = document.getElementById('b-mg-wrap');
                if (wrap && mg) {
                    // Meet & greet is mandatory on an arrival and baked into the price —
                    // never shown as a removable option (so it can't be dropped). The
                    // hidden checkbox still carries meet_greet=1 to the server.
                    wrap.hidden = true;
                    wrap.classList.remove('on');
                    mg.checked = arrival;
                }

                var ff = document.getElementById('b-flight-field');
                var fe = document.getElementById('b-flight');
                var flabel = document.getElementById('b-flight-label');
                var fopt = document.getElementById('b-flight-opt');
                var lf = document.getElementById('b-flight-landing-field');
                var le = document.getElementById('b-flight-landing');
                var llabel = document.getElementById('b-flight-landing-label');

                // Show the flight field for any airport journey; require it (and landing)
                // for arrivals/returns; a plain one-way DEPARTURE is optional, no landing.
                if (ff) ff.hidden = !anyAir;
                if (fe) fe.dataset.required = arrival ? '1' : '';
                if (lf) lf.hidden = !arrival;
                if (le) le.dataset.required = arrival ? '1' : '';

                if (isReturn && anyAir) {
                    if (flabel) flabel.textContent = 'Return flight number';
                    if (fopt) fopt.textContent = '— required (the flight you land on coming back)';
                    if (llabel) llabel.textContent = 'Return flight landing time';
                } else if (puAir) {
                    if (flabel) flabel.textContent = 'Flight number';
                    if (fopt) fopt.textContent = '— required (arrival flight we track)';
                    if (llabel) llabel.textContent = 'Flight landing time';
                } else if (dpAir) {
                    if (flabel) flabel.textContent = 'Departure flight number';
                    if (fopt) fopt.textContent = '— optional';
                }
            }

            // Rolls Royce / any "on request" vehicle: no payment method — it's a
            // request-a-quote (we take details, then email the office/customer a price).
            function syncQuoteMode() {
                var poa = !!(lastQuote && lastQuote.poa);
                var sec = document.getElementById('b-payment-section');
                var qn = document.getElementById('b-quote-note');
                var payNote = document.getElementById('b-pay-note');
                if (sec) sec.hidden = poa;
                if (qn) qn.hidden = !poa;
                if (payNote) payNote.hidden = poa;
                var btn = document.getElementById('cet-submit');
                if (btn) {
                    if (poa) btn.textContent = 'Request a quote';
                    else syncPay();
                }
            }
            if (mg) { mg.addEventListener('change', function () { mg.dataset.userset = '1'; }); }
            var flightEl = document.getElementById('b-flight');
            if (flightEl) { flightEl.addEventListener('input', fillSummary); }
            var flightLandEl = document.getElementById('b-flight-landing');
            if (flightLandEl) { flightLandEl.addEventListener('change', fillSummary); }
            // Wheelchair / ribbons checkbox extras — highlight + live total.
            ['b-wheelchair', 'b-ribbons'].forEach(function (id) {
                var el = document.getElementById(id); if (!el) return;
                el.addEventListener('change', function () {
                    var lab = el.closest('.cet-check'); if (lab) lab.classList.toggle('on', el.checked);
                    fillSummary();
                });
            });
            var vatPercent = {{ (int) ($vatPercent ?? 20) }};
            var vatYes = document.getElementById('b-vat-yes'), vatNo = document.getElementById('b-vat-no');
            function syncVat() {
                var yw = document.getElementById('b-vat-yes-wrap'), nw = document.getElementById('b-vat-no-wrap');
                if (yw) yw.classList.toggle('on', !!(vatYes && vatYes.checked));
                if (nw) nw.classList.toggle('on', !!(vatNo && vatNo.checked));
                applyReturnPaymentRule(); // VAT bookings are always card — hide cash
                renderPrices(); fillSummary();
            }
            [vatYes, vatNo].forEach(function (r) { if (r) r.addEventListener('change', syncVat); });

            // Payment method (card / cash / account) — ETO-style.
            var payCard = document.getElementById('b-pay-card'), payCash = document.getElementById('b-pay-cash'),
                payAccount = document.getElementById('b-pay-account');
            function selectedPay() {
                if (payAccount && payAccount.checked) return 'account';
                if (payCash && payCash.checked) return 'cash';
                return 'card';
            }
            function syncPay() {
                var v = selectedPay();
                var cw = document.getElementById('b-pay-card-wrap'), hw = document.getElementById('b-pay-cash-wrap'),
                    aw = document.getElementById('b-pay-account-wrap');
                if (cw) cw.classList.toggle('on', v === 'card');
                if (hw) hw.classList.toggle('on', v === 'cash');
                if (aw) aw.classList.toggle('on', v === 'account');
                var note = document.getElementById('b-pay-note');
                if (note) note.textContent = v === 'card'
                    ? 'Pay securely by card to confirm your booking — our office checks the details first.'
                    : (v === 'account'
                        ? 'No payment now — this journey is billed to your business account and confirmed by our office.'
                        : 'Pay the driver on the day — our office will confirm your journey and price first.');
                var btn = document.getElementById('cet-submit');
                if (btn) btn.textContent = v === 'card' ? 'Book & pay' : 'Request booking';
            }
            [payCard, payCash, payAccount].forEach(function (r) { if (r) r.addEventListener('change', syncPay); });
            syncPay();

            // Book as guest / Sign in / Register tabs. Account payment is only ever
            // shown to a SIGNED-IN business account — guests/register can't pick it.
            (function () {
                var acctWrap = document.getElementById('b-pay-account-wrap');
                var acctName = document.getElementById('b-account-name');
                var tabs = document.getElementById('b-authtabs');
                var loginPanel = document.getElementById('b-login-panel');
                var guestPanel = document.getElementById('b-guest-panel');
                var regPassField = document.getElementById('b-reg-pass-field');
                var regPass = document.getElementById('b-reg-pass');
                var createFlag = document.getElementById('b-create');
                var nameEl = document.getElementById('b-name');
                var signedin = document.getElementById('b-signedin');
                var signedIn = false;

                // Switch tab: guest | login | register.
                function setTab(which) {
                    if (tabs) tabs.querySelectorAll('button').forEach(function (b) { b.classList.toggle('on', b.dataset.auth === which); });
                    if (loginPanel) loginPanel.hidden = which !== 'login';
                    if (guestPanel) guestPanel.hidden = which === 'login';
                    // Register → collect a password + flag account creation.
                    var reg = which === 'register';
                    if (regPassField) regPassField.hidden = !reg;
                    if (regPass) regPass.disabled = !reg;
                    if (createFlag) createFlag.value = reg ? '1' : '';
                    // Name is only required when the guest/register fields are visible.
                    if (nameEl) { if (which === 'login') nameEl.removeAttribute('required'); else nameEl.setAttribute('required', 'required'); }
                    reportHeight();
                }
                if (tabs) tabs.querySelectorAll('button').forEach(function (b) {
                    b.addEventListener('click', function () { setTab(b.dataset.auth); });
                });

                function signIn(account, me) {
                    signedIn = true;
                    if (me) {
                        var ph = document.getElementById('b-phone'), em = document.getElementById('b-email');
                        if (nameEl && me.name) nameEl.value = me.name;
                        if (ph && me.phone) ph.value = me.phone;
                        if (em && me.email) em.value = me.email;
                    }
                    if (tabs) tabs.hidden = true;
                    if (signedin && me) { signedin.hidden = false;
                        var mn = document.getElementById('b-me-name'), ma = document.getElementById('b-me-acct');
                        if (mn) mn.textContent = me.name || 'your account';
                        if (ma) ma.textContent = account ? ' · ' + account.name + ' (acct ' + account.code + ')' : '';
                    }
                    if (loginPanel) loginPanel.hidden = true;
                    if (guestPanel) guestPanel.hidden = false;
                    if (regPassField) regPassField.hidden = true;
                    if (regPass) regPass.disabled = true;
                    if (createFlag) createFlag.value = '';
                    if (nameEl) nameEl.setAttribute('required', 'required');
                    // Reveal + select Account payment for a business account.
                    if (account && acctWrap) {
                        if (acctName) acctName.textContent = account.name;
                        acctWrap.hidden = false;
                        if (payAccount) { payAccount.checked = true; syncPay(); }
                    }
                    reportHeight(); fillSummary();
                }

                // Already signed in when the page loaded?
                if (window.CET_ME) signIn(window.CET_ME_ACCOUNT, window.CET_ME);

                var loginBtn = document.getElementById('b-login-btn');
                if (loginBtn && window.CET_LOGIN_URL) loginBtn.addEventListener('click', function () {
                    var email = (document.getElementById('b-login-email') || {}).value || '';
                    var pass = (document.getElementById('b-login-pass') || {}).value || '';
                    var err = document.getElementById('b-login-err');
                    if (err) err.hidden = true;
                    loginBtn.disabled = true; loginBtn.textContent = 'Signing in…';
                    fetch(window.CET_LOGIN_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                        body: JSON.stringify({ email: email.trim(), password: pass })
                    }).then(function (r) { return r.ok ? r.json() : Promise.reject(); })
                      .then(function (d) {
                          if (d && d.ok) { signIn(d.account, d); }
                          else { if (err) { err.hidden = false; err.textContent = 'That email and password didn’t match.'; } }
                      })
                      .catch(function () { if (err) { err.hidden = false; err.textContent = 'That email and password didn’t match.'; } })
                      .finally(function () { loginBtn.disabled = false; loginBtn.textContent = 'Sign in'; });
                });

                // Expose whether the booker is on the login tab without signing in,
                // so step validation can nudge them.
                window.__cetAuthState = function () {
                    var onLogin = loginPanel && !loginPanel.hidden && !signedIn;
                    return { onLogin: !!onLogin, signedIn: signedIn };
                };
            })();

            function extrasList() {
                // NB: meet & greet is NOT listed here — on an airport arrival it's
                // mandatory and baked into the price (mgFee), shown as "including meet
                // & greet" on the total rather than as a removable line item.
                var items = [];
                [['b-child_seats','Child seat'],['b-booster_seats','Booster seat'],
                 ['b-infant_seats','Infant seat']].forEach(function (p) {
                    var el = document.getElementById(p[0]); if (!el) return;
                    var n = parseInt(el.value, 10) || 0; if (n <= 0) return;
                    var unit = parseFloat(el.dataset.extra) || 0;
                    items.push({ label: (n > 1 ? n + ' × ' : '') + p[1] + (n > 1 ? 's' : ''), amount: unit * n });
                });
                // Checkbox extras (wheelchair, wedding ribbons).
                [['b-wheelchair','Wheelchair accessible vehicle'],['b-ribbons','Wedding ribbons']].forEach(function (p) {
                    var el = document.getElementById(p[0]); if (!el || !el.checked) return;
                    items.push({ label: p[1], amount: parseFloat(el.dataset.extra) || 0 });
                });
                // Extra stops added on the main page (each via point).
                var stopsField = document.getElementById('b-stops-field');
                var stopCount = window.__cetCountStops ? window.__cetCountStops() : 0;
                if (stopsField && stopCount > 0) {
                    var stopUnit = parseFloat(stopsField.dataset.stopRate) || 0;
                    items.push({ label: (stopCount > 1 ? stopCount + ' × ' : '') + 'Extra stop' + (stopCount > 1 ? 's' : ''), amount: stopUnit * stopCount });
                }
                return items;
            }
            function extrasTotal() { return extrasList().reduce(function (s, i) { return s + i.amount; }, 0); }

            // Show only the minibus that fits: the standard 8-Seater normally, or the
            // XL as soon as the party is bigger than the 8-Seater's seats OR the
            // luggage is more than it holds. Only one of the pair is ever visible.
            function minibusToggle() {
                var std = form.querySelector('.cet-veh[data-slug="minibus-8"]');
                var xl = form.querySelector('.cet-veh[data-slug="minibus-8-xl"]');
                if (!std || !xl) return;
                function num(id){ var el=document.getElementById(id); return el ? (parseInt(el.value,10)||0) : 0; }
                var pax = num('b-pax');
                var lug = num('b-suit') + num('b-hand');
                var capPax = parseInt(std.dataset.capPax, 10) || 0;
                var capLug = parseInt(std.dataset.capLug, 10) || 0;
                var needXl = (pax > capPax) || (lug > capLug);
                std.hidden = needXl;
                xl.hidden = !needXl;
                // If the now-hidden minibus was selected, move the choice to its
                // visible partner so a hidden card is never the selection.
                var hidden = needXl ? std : xl;
                if (hidden.classList.contains('sel')) { selectCard(needXl ? xl : std); }
            }

            // Fetch a price for every (visible) vehicle and show it on the card.
            function loadPrices() {
                var pu = document.getElementById('b-pickup'), dp = document.getElementById('b-dropoff');
                var jt = journeyEl ? journeyEl.value : 'one_way';
                // Hourly hire has no drop-off / fixed route — the office prices it.
                if (jt === 'hourly' || !pu || !dp || !nonEmpty(pu) || !nonEmpty(dp)) {
                    form.querySelectorAll('.cet-veh [data-price]').forEach(function (el) {
                        el.textContent = ''; el.classList.remove('poa');
                    });
                    reportHeight(); return;
                }
                // Include postcodes so the fixed-price zones resolve accurately.
                function withPc(addr, pcId){ var pc=(document.getElementById(pcId)||{}).value||''; pc=pc.trim();
                    return (pc && addr.toUpperCase().indexOf(pc.toUpperCase())===-1) ? addr+', '+pc : addr; }
                var pickup = withPc(pu.value, 'b-pickup-pc'), dest = withPc(dp.value, 'b-dropoff-pc');
                var key = pickup + '|' + dest;
                if (key === pricesFor && Object.keys(priceById).length) { renderPrices(); reportHeight(); return; }
                form.querySelectorAll('.cet-veh [data-price]').forEach(function (el) { el.textContent = '…'; });
                fetch('{{ route('widget.prices') }}', {
                    method:'POST',
                    headers:{ 'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN':token },
                    body:JSON.stringify({ pickup:pickup, destination:dest })
                })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    pricesFor = key; priceById = {};
                    (d.options || []).forEach(function (o) { priceById[o.id] = o; });
                    renderPrices();
                    // Refresh the selected card's summary price.
                    var sel = form.querySelector('.cet-veh.sel'); if (sel) selectCard(sel);
                    reportHeight();
                })
                .catch(function () {
                    form.querySelectorAll('.cet-veh [data-price]').forEach(function (el) {
                        el.textContent = ''; el.classList.remove('poa');
                    });
                    reportHeight();
                });
            }

            form.addEventListener('submit', function (e) {
                if (!validateStep(3)) { e.preventDefault(); }
            });
        })();
    </script>
</body>
</html>
