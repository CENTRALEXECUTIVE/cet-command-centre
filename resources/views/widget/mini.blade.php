<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Get a price · Central Executive Transfers</title>
    <style>
        :root { --gold:#FBBA2A; --ink:#111; --line:#e6e6e6; --muted:#666; }
        * { box-sizing:border-box; }
        html,body { margin:0; }
        body { font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:var(--ink); background:transparent; }
        .cet-widget { max-width:520px; margin:0 auto; background:#fff; border:1px solid var(--line); border-radius:14px; padding:16px; }
        .cet-head { display:flex; align-items:center; gap:8px; margin-bottom:12px; }
        .cet-dot { width:10px; height:10px; border-radius:50%; background:var(--gold); }
        .cet-brand { font-weight:800; letter-spacing:.5px; font-size:14px; }
        .cet-brand span { color:var(--gold); }
        .cet-field { margin-bottom:10px; }
        .cet-field label { display:block; font-size:12px; font-weight:600; color:var(--muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:.4px; }
        .cet-field input, .cet-field select { width:100%; padding:11px 12px; border:1px solid var(--line); border-radius:9px; font-size:15px; background:#fff; }
        .cet-field input:focus, .cet-field select:focus { outline:2px solid var(--gold); border-color:var(--gold); }
        .cet-btn { width:100%; padding:13px; border:0; border-radius:10px; background:var(--gold); color:#111; font-weight:800; font-size:15px; cursor:pointer; }
        .cet-btn:disabled { opacity:.6; cursor:default; }
        .cet-result { margin-top:14px; border-top:1px solid var(--line); padding-top:14px; display:none; }
        .cet-price { font-size:30px; font-weight:800; }
        .cet-basis { color:var(--muted); font-size:13px; margin-top:2px; }
        .cet-cta { display:block; text-align:center; margin-top:12px; padding:12px; border-radius:10px; background:#111; color:#fff; text-decoration:none; font-weight:700; }
        .cet-err { color:#b32020; font-size:13px; margin-top:8px; }
        .cet-foot { text-align:center; color:var(--muted); font-size:11px; margin-top:12px; }
        .cet-two { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        @media (max-width:420px){ .cet-two { grid-template-columns:1fr; } }
    </style>
</head>
<body>
    <div class="cet-widget" id="cet-widget">
        <div class="cet-head"><span class="cet-dot"></span><span class="cet-brand">CENTRAL <span>EXECUTIVE</span> TRANSFERS</span></div>

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
            <div class="cet-price" id="cet-price">£—</div>
            <div class="cet-basis" id="cet-basis"></div>
            <a class="cet-cta" id="cet-cta" target="_blank" rel="noopener">Book this journey →</a>
        </div>

        <div class="cet-foot">Powered by Central Executive Transfers · prices are a guide, confirmed on booking</div>
    </div>

    <script>
        (function () {
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
