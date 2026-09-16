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
            --ink:#0b0b0c; --ink-2:#17171a; --ink-3:#222227;
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
            color:var(--ink); background:transparent; line-height:1.45;
            -webkit-font-smoothing:antialiased;
        }
        /* Full premium backdrop ONLY when opened standalone (not inside an iframe). */
        html.standalone body {
            background:
                radial-gradient(1200px 500px at 50% -10%, rgba(251,186,42,.18), transparent 60%),
                radial-gradient(800px 600px at 100% 0%, rgba(251,186,42,.06), transparent 55%),
                linear-gradient(180deg, #0b0b0c 0%, #121216 55%, #0b0b0c 100%);
            min-height:100vh;
            padding:32px 16px 44px;
        }
        html.standalone .cet-shell { max-width:600px; margin:0 auto; }
        html.standalone .cet-tagline-top { display:block; }

        .cet-tagline-top { display:none; text-align:center; margin:0 0 20px; }
        .cet-tagline-top .k { color:var(--gold); font-weight:800; letter-spacing:3px; font-size:12px; text-transform:uppercase; }
        .cet-tagline-top .t { color:#f4f2ec; font-size:26px; font-weight:800; letter-spacing:-.4px; margin-top:6px; }
        .cet-tagline-top .s { color:#b9b8b2; font-size:13px; margin-top:4px; }

        .cet-widget {
            max-width:600px; margin:0 auto; background:var(--paper);
            border:1px solid var(--line); border-radius:var(--radius);
            box-shadow:var(--shadow); overflow:hidden;
        }

        /* Dark branded hero band inside the card */
        .cet-hero {
            background:
                radial-gradient(600px 200px at 12% -40%, rgba(251,186,42,.30), transparent 60%),
                linear-gradient(135deg, #17171a 0%, #0b0b0c 100%);
            color:#fff; padding:22px 24px 20px; position:relative;
        }
        .cet-hero::after { content:""; position:absolute; left:0; right:0; bottom:0; height:3px;
            background:linear-gradient(90deg, var(--gold), var(--gold-deep)); }
        .cet-brand { display:flex; align-items:center; gap:10px; }
        .cet-mark { width:34px; height:34px; border-radius:9px; background:var(--gold); color:#0b0b0c;
            font-family:Georgia,"Times New Roman",serif; font-weight:800; font-size:22px;
            display:grid; place-items:center; flex:0 0 auto; }
        .cet-brand .name { font-weight:800; letter-spacing:1.5px; font-size:15px; line-height:1.1; }
        .cet-brand .name span { color:var(--gold); }
        .cet-brand .sub { color:#b7b6b0; font-size:11px; letter-spacing:.5px; margin-top:2px; }
        .cet-hero h1 { margin:16px 0 2px; font-size:22px; font-weight:800; letter-spacing:-.4px; }
        .cet-hero p { margin:0; color:#c7c6c0; font-size:13px; }

        .cet-body { padding:20px 24px 22px; }

        .cet-sec { display:flex; align-items:center; gap:8px; font-size:11px; font-weight:800;
            color:var(--muted); text-transform:uppercase; letter-spacing:1.2px; margin:20px 0 12px; }
        .cet-sec:first-child { margin-top:4px; }
        .cet-sec .ic { color:var(--gold-deep); font-size:14px; }
        .cet-sec::after { content:""; height:1px; flex:1; background:linear-gradient(90deg,var(--line),transparent); }

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
            outline:none; border-color:var(--gold); background:#fff;
            box-shadow:0 0 0 4px rgba(251,186,42,.18);
        }
        .cet-field.icon { position:relative; }
        .cet-field.icon .pin { position:absolute; left:13px; top:37px; font-size:15px; }
        .cet-field.icon input { padding-left:38px; }

        .cet-two { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media (max-width:460px){ .cet-two { grid-template-columns:1fr; } }

        .cet-price {
            background:linear-gradient(135deg,#fff9ea,#fdf3d6); border:1px solid var(--gold);
            border-radius:13px; padding:14px 16px; margin:10px 0 4px; display:none;
            box-shadow:0 6px 18px -10px rgba(233,164,19,.5);
        }
        .cet-price b { font-size:26px; font-weight:900; letter-spacing:-.5px; }

        .cet-btn {
            width:100%; padding:15px; border:0; border-radius:12px;
            background:linear-gradient(135deg, var(--gold), var(--gold-deep)); color:#0b0b0c;
            font-weight:800; font-size:16px; cursor:pointer; margin-top:8px; letter-spacing:.2px;
            box-shadow:0 10px 24px -10px rgba(233,164,19,.7); transition:transform .12s, box-shadow .12s, filter .12s;
        }
        .cet-btn:hover { transform:translateY(-1px); filter:brightness(1.03); box-shadow:0 14px 30px -10px rgba(233,164,19,.8); }
        .cet-btn:active { transform:translateY(0); }
        .cet-btn:disabled { opacity:.6; cursor:default; transform:none; box-shadow:none; }

        .cet-ghost {
            width:100%; padding:12px; border:1.5px solid var(--ink); border-radius:12px;
            background:#fff; color:var(--ink); font-weight:700; font-size:14.5px; cursor:pointer; margin-top:10px;
            transition:background .12s, color .12s;
        }
        .cet-ghost:hover { background:var(--ink); color:#fff; }

        .cet-err { color:var(--err); font-size:13px; margin-top:10px; background:rgba(192,38,38,.07);
            border:1px solid rgba(192,38,38,.25); border-radius:9px; padding:9px 11px; }

        .cet-foot { text-align:center; color:var(--muted); font-size:11.5px; margin-top:12px; }

        /* Trust strip */
        .cet-trust { display:flex; gap:8px; flex-wrap:wrap; margin-top:16px; padding-top:16px; border-top:1px solid var(--line); }
        .cet-trust span { flex:1; min-width:120px; display:flex; align-items:center; gap:7px;
            font-size:11.5px; color:#4a4a50; font-weight:600; }
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
                    <div>
                        <div class="name">CENTRAL <span>EXECUTIVE</span></div>
                        <div class="sub">TRANSFERS · SHEFFIELD</div>
                    </div>
                </div>
                @if($done)
                    <h1>Thank you</h1>
                    <p>Your request is with our office.</p>
                @else
                    <h1>Book a journey</h1>
                    <p>Fixed prices, professional chauffeurs, confirmed by our office.</p>
                @endif
            </div>

            <div class="cet-body">
        @if($done)
            <div class="cet-done">
                <div class="tick">✓</div>
                <h2>Booking request received</h2>
                <p style="color:var(--muted);margin:0 0 6px">Our office will confirm your journey and price shortly.</p>
                @if(isset($ref))<div class="cet-ref">Ref {{ $ref }}</div>@endif
                @if(!empty($payUrl))
                    <a href="{{ $payUrl }}" class="cet-btn" style="display:block;margin-top:18px;text-decoration:none;text-align:center">
                        Pay now to secure it{{ $payAmount ? ' · £'.number_format($payAmount, 0) : '' }}
                    </a>
                    <p class="cet-foot" style="margin-top:8px">Secure card payment by Square. Prefer to pay later? No problem — we'll be in touch.</p>
                @else
                    <p class="cet-foot" style="margin-top:12px">No payment has been taken.</p>
                @endif
            </div>
        @else
            <form id="cet-book" action="{{ route('widget.book.store') }}" method="POST" autocomplete="off">
                @csrf
                {{-- Honeypot: hidden from real users; bots fill it and get bounced. --}}
                <div class="cet-hp"><label>Company<input name="company" tabindex="-1" autocomplete="off"></label></div>

                <div class="cet-sec"><span class="ic">📍</span> Journey</div>
                <div class="cet-field icon"><label for="b-pickup">Pickup address</label>
                    <span class="pin">🟡</span>
                    <input id="b-pickup" name="pickup_address" required placeholder="e.g. Sheffield S1 2HH"></div>
                <div class="cet-field icon"><label for="b-dropoff">Drop-off address</label>
                    <span class="pin">🏁</span>
                    <input id="b-dropoff" name="destination_address" required placeholder="Where to?"></div>
                <div class="cet-two">
                    <div class="cet-field"><label for="b-when">Date &amp; time</label>
                        <input id="b-when" name="pickup_at" type="datetime-local" required></div>
                    <div class="cet-field"><label for="b-vehicle">Vehicle</label>
                        <select id="b-vehicle" name="vehicle_type_id">
                            @foreach($vehicleTypes as $vt)
                                <option value="{{ $vt->id }}">{{ $vt->name }} (up to {{ $vt->passenger_capacity }})</option>
                            @endforeach
                        </select></div>
                </div>
                <div class="cet-two">
                    <div class="cet-field"><label for="b-pax">Passengers</label>
                        <input id="b-pax" name="passengers" type="number" min="1" max="60" value="1" required></div>
                    <div class="cet-field"><label for="b-flight">Flight number <span class="opt">(optional)</span></label>
                        <input id="b-flight" name="flight_number" placeholder="e.g. BA1234"></div>
                </div>
                <div class="cet-two">
                    <div class="cet-field"><label for="b-suit">Suitcases</label>
                        <input id="b-suit" name="suitcases" type="number" min="0" max="30" value="0"></div>
                    <div class="cet-field"><label for="b-hand">Hand luggage</label>
                        <input id="b-hand" name="hand_luggage" type="number" min="0" max="30" value="0"></div>
                </div>

                <button type="button" class="cet-ghost" id="cet-check">💷 See my price</button>
                <div class="cet-price" id="cet-price"></div>

                <div class="cet-sec"><span class="ic">👤</span> Your details</div>
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

                <button class="cet-btn" type="submit" id="cet-submit">Request booking</button>
                <div class="cet-err" id="cet-err" style="display:none"></div>
                <p class="cet-foot">No payment is taken now — our office confirms your journey and price first.</p>

                <div class="cet-trust">
                    <span><span class="ic">🛡️</span> Licensed Operator OP037</span>
                    <span><span class="ic">💷</span> Fixed, upfront prices</span>
                    <span><span class="ic">🕐</span> 24/7 chauffeur service</span>
                </div>
            </form>
        @endif
            </div>
        </div>
    </div>

    <script>
        (function () {
            // Full premium background only when opened on its own (not embedded).
            try { if (window.self === window.top) document.documentElement.classList.add('standalone'); } catch (e) {
                document.documentElement.classList.add('standalone');
            }

            function reportHeight() {
                try { parent.postMessage({ cetWidgetHeight: document.getElementById('cet-widget').offsetHeight + 24 }, '*'); } catch (e) {}
            }
            window.addEventListener('load', reportHeight);
            window.addEventListener('resize', reportHeight);

            var check = document.getElementById('cet-check');
            if (check) {
                var priceBox = document.getElementById('cet-price');
                var tokenEl = document.querySelector('meta[name="csrf-token"]');
                var token = tokenEl ? tokenEl.getAttribute('content') : '';
                var f = document.getElementById('cet-book');
                check.addEventListener('click', function () {
                    if (!f.pickup_address.value || !f.destination_address.value) {
                        priceBox.style.display = 'block';
                        priceBox.innerHTML = 'Enter pickup and drop-off first.';
                        reportHeight(); return;
                    }
                    check.disabled = true; check.textContent = 'Checking…';
                    fetch('{{ route('widget.price') }}', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                        body: JSON.stringify({ pickup: f.pickup_address.value, destination: f.destination_address.value, vehicle_type_id: f.vehicle_type_id.value })
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        check.disabled = false; check.textContent = '💷 See my price';
                        priceBox.style.display = 'block';
                        priceBox.innerHTML = '<b>' + d.formatted + '</b><br><span style="color:#7a6a3a;font-size:13px">' + d.vehicle + ' · ' + d.basis + ' · guide price, confirmed on booking</span>';
                        reportHeight();
                    })
                    .catch(function () {
                        check.disabled = false; check.textContent = '💷 See my price';
                        priceBox.style.display = 'block'; priceBox.innerHTML = 'Could not price that just now — you can still request the booking.';
                        reportHeight();
                    });
                });
            }
        })();
    </script>
</body>
</html>
