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
            color:#fff; padding:22px 24px 20px; position:relative;
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
        .cet-field.icon .pin { position:absolute; left:13px; top:37px; font-size:15px; }
        .cet-field.icon input { padding-left:38px; }
        .cet-two { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media (max-width:460px){ .cet-two { grid-template-columns:1fr; } }

        /* Vehicle cards */
        .cet-vehs { display:flex; flex-direction:column; gap:10px; }
        .cet-veh { display:flex; align-items:center; gap:15px; padding:13px 15px; border:1.5px solid var(--line);
            border-radius:16px; background:var(--cream); cursor:pointer; position:relative;
            transition:transform .12s ease,border-color .15s,box-shadow .15s; }
        .cet-veh:hover { border-color:var(--gold-deep); background:#fff; box-shadow:0 8px 20px rgba(0,0,0,.07); transform:translateY(-1px); }
        .cet-veh input { position:absolute; opacity:0; pointer-events:none; }
        .cet-veh.sel { border-color:var(--gold); background:#fff; box-shadow:0 0 0 4px rgba(251,186,42,.16); }
        /* Uniform premium photo tile: the whole car shows (contain, never cropped),
           centred on a soft white ground with a subtle drop shadow so it reads as a
           proper product shot — real photos and placeholders line up identically. */
        .cet-veh-img { width:122px; height:80px; flex:0 0 auto; border-radius:12px; overflow:hidden; padding:8px;
            border:1px solid var(--line);
            background:radial-gradient(130% 130% at 50% 16%,#ffffff 0%,#f1efe8 100%);
            display:grid; place-items:center; }
        .cet-veh-img img { width:100%; height:100%; object-fit:contain; object-position:center;
            filter:drop-shadow(0 6px 9px rgba(0,0,0,.16)); }
        .cet-veh-img svg { width:94px; height:auto; opacity:.8; }
        .cet-veh.sel .cet-veh-img { border-color:rgba(251,186,42,.55); }
        @media (max-width:460px){ .cet-veh-img { width:104px; height:70px; } }
        .cet-veh-meta { flex:1; min-width:0; }
        .cet-veh-name { font-weight:800; font-size:15.5px; }
        .cet-veh-tag { font-size:12.5px; color:var(--gold-deep); font-weight:700; margin-top:1px; }
        .cet-veh-cap { font-size:12.5px; color:var(--muted); margin-top:3px; }
        /* Per-card price (right of the meta, before the tick). */
        .cet-veh-price { flex:0 0 auto; text-align:right; min-width:64px; }
        .cet-veh-price .amt { font-weight:900; font-size:18px; letter-spacing:-.4px; white-space:nowrap; }
        .cet-veh-price .amt.poa { font-size:13px; font-weight:800; color:var(--muted); }
        .cet-veh-price .sub { font-size:10.5px; color:var(--muted-2); font-weight:600; }
        @media (max-width:460px){ .cet-veh-price .amt { font-size:16px; } }
        .cet-veh-tick { width:24px; height:24px; flex:0 0 auto; border-radius:50%; border:2px solid var(--line);
            display:grid; place-items:center; color:#fff; font-size:13px; font-weight:900; }
        .cet-veh.sel .cet-veh-tick { background:var(--gold); border-color:var(--gold); color:#0b0b0c; }
        .cet-veh[hidden] { display:none; }

        .cet-price { background:linear-gradient(135deg,#fff9ea,#fdf3d6); border:1px solid var(--gold);
            border-radius:13px; padding:14px 16px; margin:14px 0 4px; display:none;
            box-shadow:0 6px 18px -10px rgba(233,164,19,.5); }
        .cet-price b { font-size:24px; font-weight:900; letter-spacing:-.5px; }

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
                    <h2>Booking request received</h2>
                    <p style="color:var(--muted);margin:0 0 6px">Our office will confirm your journey and price shortly.</p>
                    @if(isset($ref))<div class="cet-ref">Ref {{ $ref }}</div>@endif
                    @if(!empty($payUrl))
                        <a href="{{ $payUrl }}" class="cet-btn" style="display:block;margin-top:18px;text-decoration:none;text-align:center">
                            Pay now to secure it{{ $payAmount ? ' · £'.number_format($payAmount, 0) : '' }}</a>
                        <p class="cet-foot" style="margin-top:8px">Secure card payment by Square. Prefer to pay later? No problem — we'll be in touch.</p>
                    @else
                        <p class="cet-foot" style="margin-top:12px">No payment has been taken.</p>
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
                        <div class="cet-field"><label for="b-journey">Journey type</label>
                            <select id="b-journey" name="journey_type">
                                <option value="one_way">One way</option>
                                <option value="return">Return</option>
                                <option value="hourly">Hourly hire (as directed)</option>
                            </select>
                        </div>
                        <div class="cet-field icon"><label for="b-pickup">Pickup address</label>
                            <span class="pin">🟡</span>
                            <input id="b-pickup" name="pickup_address" required placeholder="Start typing your address…" data-places autocomplete="off"></div>
                        <div class="cet-field icon"><label for="b-dropoff">Drop-off address</label>
                            <span class="pin">🏁</span>
                            <input id="b-dropoff" name="destination_address" required placeholder="Start typing an address…" data-places autocomplete="off"></div>
                        <div class="cet-two">
                            <div class="cet-field"><label for="b-when">Date &amp; time <span style="font-weight:600;color:var(--muted);font-size:11px">· min {{ (int) config('cet.public_min_lead_hours', 8) }}h notice</span></label>
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
                                        <div class="cet-veh-cap">👤 {{ $vt->passenger_capacity }} passengers · 🧳 {{ $vt->luggage_capacity }} suitcases · 👜 {{ $vt->handLuggageCapacity() }} hand luggage</div>
                                    </div>
                                    <div class="cet-veh-price"><div class="amt" data-price>—</div></div>
                                    <div class="cet-veh-tick">✓</div>
                                </label>
                            @endforeach
                        </div>
                        <p class="cet-foot" style="margin-top:10px">Guide prices — confirmed by our office before your journey.</p>
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
                        <div class="cet-field"><label for="b-name">Full name</label>
                            <input id="b-name" name="customer_name" required></div>
                        <div class="cet-two">
                            <div class="cet-field"><label for="b-phone">Mobile number</label>
                                <input id="b-phone" name="customer_phone" placeholder="07…"></div>
                            <div class="cet-field"><label for="b-email">Email</label>
                                <input id="b-email" name="customer_email" type="email"></div>
                        </div>
                        <div class="cet-field"><label for="b-notes">Notes for us <span class="opt">(optional)</span></label>
                            <textarea id="b-notes" name="notes" rows="2" placeholder="Meet &amp; greet, child seat, extra stops…"></textarea></div>
                        <div class="cet-err" data-err="3"></div>
                        <div class="cet-actions">
                            <button type="button" class="cet-back" data-back="2">← Back</button>
                            <button class="cet-btn" type="submit" id="cet-submit">Request booking</button>
                        </div>
                        <p class="cet-foot">No payment is taken now — our office confirms your journey and price first.</p>
                        <div class="cet-trust">
                            <span><span class="ic">🛡️</span> Licensed Operator OP037</span>
                            <span><span class="ic">💷</span> Fixed, upfront prices</span>
                            <span><span class="ic">🕐</span> 24/7 chauffeur service</span>
                        </div>
                    </div>
                </form>
            </div>
        @endif
        </div>
    </div>

    {{-- Google address autocomplete via the server proxy (key stays server-side). --}}
    <script>window.CET_PLACES_URL = "{{ route('public.book.places') }}";</script>
    <script src="{{ asset('js/cet-forms.js') }}" defer></script>

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

            // Minimum notice for online bookings — the date picker can't go below
            // now + N hours (office only for anything sooner).
            var MIN_LEAD_H = {{ (int) config('cet.public_min_lead_hours', 8) }};
            (function () {
                function p(n){return (n<10?'0':'')+n;}
                function fmt(d){return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+'T'+p(d.getHours())+':'+p(d.getMinutes());}
                var earliest = new Date(Date.now() + MIN_LEAD_H*3600*1000);
                earliest.setMinutes(Math.ceil(earliest.getMinutes()/15)*15, 0, 0);
                var when = document.getElementById('b-when');
                var ret = document.getElementById('b-return');
                if (when) { when.min = fmt(earliest); if (!when.value) when.value = fmt(earliest); }
                if (ret) { ret.min = fmt(earliest); }
            })();

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
            var dropWrap = document.getElementById('b-dropoff') ? document.getElementById('b-dropoff').closest('.cet-field') : null;
            function toggleJourney() {
                var v = journeyEl ? journeyEl.value : 'one_way';
                if (returnField) returnField.style.display = v === 'return' ? '' : 'none';
                if (hoursField) hoursField.style.display = v === 'hourly' ? '' : 'none';
                if (dropWrap) dropWrap.style.display = v === 'hourly' ? 'none' : '';
            }
            if (journeyEl) { journeyEl.addEventListener('change', toggleJourney); toggleJourney(); }

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
                    return ok;
                }
                if (n === 3) {
                    var nameEl = document.getElementById('b-name');
                    var phone = document.getElementById('b-phone'), email = document.getElementById('b-email');
                    var okName = nonEmpty(nameEl);
                    nameEl.closest('.cet-field').classList.toggle('bad', !okName);
                    var okContact = nonEmpty(phone) || nonEmpty(email);
                    phone.closest('.cet-field').classList.toggle('bad', !okContact);
                    email.closest('.cet-field').classList.toggle('bad', !okContact);
                    if (!okName) { showErr(3, 'Please enter your name.'); nameEl.focus(); return false; }
                    if (!okContact) { showErr(3, 'Please give a phone number or an email so we can confirm.'); phone.focus(); return false; }
                    return true;
                }
                return true;
            }

            form.querySelectorAll('[data-next]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var cur = +btn.closest('.cet-step').dataset.step;
                    if (!validateStep(cur)) return;
                    var next = +btn.dataset.next;
                    goTo(next);
                    if (next === 2) { minibusToggle(); loadPrices(); }
                    if (next === 3) fillSummary();
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
                var html = '<h4>Your journey</h4>'
                    + row('Date &amp; time', when())
                    + row('Vehicle', vehName || '—')
                    + row('Pick-up', val('b-pickup'))
                    + row('Drop-off', dropoff)
                    + row('Passengers', val('b-pax') || '—')
                    + row('Suitcases', val('b-suit') || '0')
                    + row('Hand luggage', val('b-hand') || '0');
                if (val('b-flight')) html += row('Flight', val('b-flight'));
                if (lastQuote && lastQuote.formatted) {
                    html += '<div class="row tot"><span class="k">Guide price</span><span class="v">'+lastQuote.formatted+'</span></div>';
                }
                box.innerHTML = html;
            }
            form.querySelectorAll('.cet-veh').forEach(function (card) {
                card.addEventListener('click', function () {
                    if (card.hidden) return;
                    selectCard(card);
                });
            });

            function selectCard(card) {
                form.querySelectorAll('.cet-veh').forEach(function (c) { c.classList.remove('sel'); });
                card.classList.add('sel');
                var input = card.querySelector('input'); if (input) input.checked = true;
                var q = priceById[card.dataset.id];
                lastQuote = q ? { formatted: q.poa ? 'On request' : q.formatted } : null;
            }

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
                var key = pu.value + '|' + dp.value;
                if (key === pricesFor && Object.keys(priceById).length) { reportHeight(); return; }
                form.querySelectorAll('.cet-veh [data-price]').forEach(function (el) { el.textContent = '…'; });
                fetch('{{ route('widget.prices') }}', {
                    method:'POST',
                    headers:{ 'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN':token },
                    body:JSON.stringify({ pickup:pu.value, destination:dp.value })
                })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    pricesFor = key; priceById = {};
                    (d.options || []).forEach(function (o) {
                        priceById[o.id] = o;
                        var card = form.querySelector('.cet-veh[data-id="' + o.id + '"]');
                        if (!card) return;
                        var el = card.querySelector('[data-price]');
                        if (!el) return;
                        el.classList.toggle('poa', !!o.poa);
                        el.innerHTML = o.poa ? 'On request'
                            : o.formatted + '<span class="sub">' + (o.fixed ? 'fixed price' : 'guide') + '</span>';
                    });
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
