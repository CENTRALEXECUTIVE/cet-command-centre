<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Get a price · Central Executive Transfers</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='22' fill='%230b0b0c'/><text x='50' y='72' font-size='64' text-anchor='middle' fill='%23FBBA2A' font-family='Georgia,serif' font-weight='bold'>C</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --gold:#FBBA2A; --gold-deep:#E9A413;
            --ink:#0b0b0c; --paper:#ffffff; --cream:#fbfaf6;
            --line:#e9e7e0; --muted:#6a6a70; --muted-2:#9a9aa2;
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

        .cet-widget { max-width:520px; margin:0 auto; background:var(--paper);
            border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; }

        .cet-hero {
            background:radial-gradient(600px 200px at 12% -40%, rgba(251,186,42,.30), transparent 60%),
                linear-gradient(135deg, #17171a 0%, #0b0b0c 100%);
            color:#fff; padding:18px 22px 20px; position:relative;
        }
        .cet-hero::after { content:""; position:absolute; left:0; right:0; bottom:0; height:3px;
            background:linear-gradient(90deg, var(--gold), var(--gold-deep)); }
        .cet-brand { display:flex; align-items:center; gap:10px; }
        .cet-mark { width:32px; height:32px; border-radius:9px; background:var(--gold); color:#0b0b0c;
            font-family:Georgia,serif; font-weight:800; font-size:21px; display:grid; place-items:center; flex:0 0 auto; }
        .cet-brand .name { font-weight:800; letter-spacing:1.4px; font-size:14px; line-height:1.1; }
        .cet-brand .name span { color:var(--gold); }
        .cet-hero h1 { margin:14px 0 2px; font-size:21px; font-weight:800; letter-spacing:-.3px; }
        .cet-hero p { margin:0; color:#c9c8c3; font-size:13px; }

        .cet-body { padding:18px 22px 20px; }
        .cet-field { margin-bottom:12px; }
        .cet-field label { display:block; font-size:11px; font-weight:700; color:var(--muted); margin-bottom:6px; text-transform:uppercase; letter-spacing:.6px; }
        .cet-field input, .cet-field select {
            width:100%; padding:13px 14px; border:1px solid var(--line); border-radius:12px; font-size:15px;
            background:var(--cream); color:var(--ink); transition:border-color .12s, box-shadow .12s, background .12s; font-family:inherit;
        }
        .cet-field input::placeholder { color:var(--muted-2); }
        .cet-field input:focus, .cet-field select:focus {
            outline:none; border-color:var(--gold); background:#fff; box-shadow:0 0 0 4px rgba(251,186,42,.18);
        }
        .cet-two { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media (max-width:430px){ .cet-two { grid-template-columns:1fr; } }

        .cet-btn {
            width:100%; padding:15px; border:0; border-radius:13px; cursor:pointer; margin-top:4px;
            background:linear-gradient(180deg, var(--gold), var(--gold-deep)); color:#0b0b0c; font-weight:800; font-size:15.5px;
            box-shadow:0 10px 22px -10px rgba(233,164,19,.75); transition:transform .08s, box-shadow .12s; font-family:inherit; letter-spacing:.2px;
        }
        .cet-btn:hover { transform:translateY(-1px); box-shadow:0 14px 26px -10px rgba(233,164,19,.85); }
        .cet-btn:active { transform:translateY(0); }
        .cet-btn:disabled { opacity:.65; cursor:default; transform:none; box-shadow:none; }

        .cet-result { margin-top:16px; display:none; }
        .cet-price-card {
            background:
                radial-gradient(400px 120px at 90% -20%, rgba(251,186,42,.18), transparent 60%),
                linear-gradient(135deg, #17171a 0%, #0b0b0c 100%);
            color:#fff; border-radius:14px; padding:18px 20px; text-align:center; position:relative; overflow:hidden;
        }
        .cet-price-card .lbl { font-size:11px; font-weight:700; letter-spacing:1.4px; text-transform:uppercase; color:var(--gold); }
        .cet-price { font-size:38px; font-weight:900; letter-spacing:-1px; line-height:1.1; margin-top:2px; }
        .cet-basis { color:#c9c8c3; font-size:13px; margin-top:4px; }
        .cet-cta {
            display:block; text-align:center; margin-top:12px; padding:14px; border-radius:13px;
            background:#0b0b0c; color:#fff; text-decoration:none; font-weight:800; font-size:15px;
            transition:transform .08s, background .12s;
        }
        .cet-cta:hover { transform:translateY(-1px); background:#000; }
        .cet-err { color:#c02626; font-size:13px; margin-top:10px; font-weight:600; }
        .cet-foot { text-align:center; color:var(--muted-2); font-size:11px; margin-top:16px; }
    </style>
</head>
<body>
    <div class="cet-widget" id="cet-widget">
        <div class="cet-hero">
            <div class="cet-brand"><span class="cet-mark">C</span><span class="name">CENTRAL <span>EXECUTIVE</span> TRANSFERS</span></div>
            <h1>Get an instant price</h1>
            <p>Fixed fares &middot; professional chauffeurs &middot; Sheffield</p>
        </div>

        <div class="cet-body">
            <form id="cet-form" autocomplete="off">
                <div class="cet-field">
                    <label for="cet-pickup">Pickup</label>
                    <input id="cet-pickup" name="pickup" placeholder="e.g. Sheffield S1 or Manchester Airport" required>
                </div>
                <div class="cet-field">
                    <label for="cet-dropoff">Drop-off</label>
                    <input id="cet-dropoff" name="destination" placeholder="Where to?" required>
                </div>
                <div class="cet-two">
                    <div class="cet-field">
                        <label for="cet-service">Vehicle</label>
                        <select id="cet-service" name="vehicle_type_id">
                            @foreach($vehicleTypes as $vt)
                                <option value="{{ $vt->id }}">{{ $vt->name }} (up to {{ $vt->passenger_capacity }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="cet-field">
                        <label for="cet-when">Date &amp; time</label>
                        <input id="cet-when" name="pickup_at" type="datetime-local">
                    </div>
                </div>
                <button class="cet-btn" type="submit" id="cet-go">Get my price</button>
                <div class="cet-err" id="cet-err" style="display:none"></div>
            </form>

            <div class="cet-result" id="cet-result">
                <div class="cet-price-card">
                    <div class="lbl">Your guide price</div>
                    <div class="cet-price" id="cet-price">£—</div>
                    <div class="cet-basis" id="cet-basis"></div>
                </div>
                <a class="cet-cta" id="cet-cta" target="_blank" rel="noopener">Book this journey →</a>
            </div>

            <div class="cet-foot">Powered by Central Executive Transfers · prices are a guide, confirmed on booking</div>
        </div>
    </div>

    <script>
        (function () {
            if (window.self === window.top) { document.documentElement.classList.add('standalone'); }

            var form = document.getElementById('cet-form');
            var go = document.getElementById('cet-go');
            var err = document.getElementById('cet-err');
            var result = document.getElementById('cet-result');
            var priceEl = document.getElementById('cet-price');
            var basisEl = document.getElementById('cet-basis');
            var cta = document.getElementById('cet-cta');
            var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            var OFFICE_WA = '447405172435';

            // Auto-resize the iframe on the host page (works with the simple listener
            // in the embed snippet, and harmless if there isn't one).
            function reportHeight() {
                try {
                    var h = document.getElementById('cet-widget').offsetHeight + 24;
                    parent.postMessage({ cetWidgetHeight: h }, '*');
                } catch (e) {}
            }
            window.addEventListener('load', reportHeight);
            window.addEventListener('resize', reportHeight);

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                err.style.display = 'none';
                go.disabled = true; go.textContent = 'Checking…';

                var payload = {
                    pickup: form.pickup.value,
                    destination: form.destination.value,
                    vehicle_type_id: form.vehicle_type_id.value
                };

                fetch('{{ route('widget.price') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify(payload)
                })
                .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                .then(function (res) {
                    go.disabled = false; go.textContent = 'Get my price';
                    if (!res.ok) { throw new Error((res.d && res.d.message) || 'Could not price that journey.'); }
                    var d = res.d;
                    priceEl.textContent = d.formatted;
                    basisEl.textContent = d.vehicle + ' · ' + d.basis;
                    // "Book this" opens WhatsApp to the office with the details filled in.
                    var when = form.pickup_at.value ? (' on ' + form.pickup_at.value.replace('T', ' ')) : '';
                    var msg = 'Hi, I\'d like to book: ' + payload.pickup + ' → ' + payload.destination +
                        when + ' (' + d.vehicle + '). Quoted ' + d.formatted + '. Please confirm.';
                    cta.href = 'https://wa.me/' + OFFICE_WA + '?text=' + encodeURIComponent(msg);
                    result.style.display = 'block';
                    reportHeight();
                })
                .catch(function (ex) {
                    go.disabled = false; go.textContent = 'Get my price';
                    err.textContent = ex.message || 'Something went wrong — please try again.';
                    err.style.display = 'block';
                    reportHeight();
                });
            });
        })();
    </script>
</body>
</html>
