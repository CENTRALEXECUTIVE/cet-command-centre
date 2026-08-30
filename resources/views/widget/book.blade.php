<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Book a journey · Central Executive Transfers</title>
    <style>
        :root { --gold:#FBBA2A; --ink:#111; --line:#e6e6e6; --muted:#666; }
        * { box-sizing:border-box; }
        html,body { margin:0; }
        body { font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:var(--ink); background:transparent; }
        .cet-widget { max-width:560px; margin:0 auto; background:#fff; border:1px solid var(--line); border-radius:14px; padding:18px; }
        .cet-head { display:flex; align-items:center; gap:8px; margin-bottom:14px; }
        .cet-dot { width:10px; height:10px; border-radius:50%; background:var(--gold); }
        .cet-brand { font-weight:800; letter-spacing:.5px; font-size:14px; }
        .cet-brand span { color:var(--gold); }
        .cet-sec { font-size:12px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.5px; margin:14px 0 8px; }
        .cet-field { margin-bottom:10px; }
        .cet-field label { display:block; font-size:12px; font-weight:600; color:var(--muted); margin-bottom:4px; }
        .cet-field input, .cet-field select, .cet-field textarea { width:100%; padding:11px 12px; border:1px solid var(--line); border-radius:9px; font-size:15px; background:#fff; font-family:inherit; }
        .cet-field input:focus, .cet-field select:focus, .cet-field textarea:focus { outline:2px solid var(--gold); border-color:var(--gold); }
        .cet-two { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        @media (max-width:440px){ .cet-two { grid-template-columns:1fr; } }
        .cet-price { background:#faf7ee; border:1px solid var(--gold); border-radius:10px; padding:12px; margin:8px 0 4px; display:none; }
        .cet-price b { font-size:22px; }
        .cet-btn { width:100%; padding:14px; border:0; border-radius:10px; background:var(--gold); color:#111; font-weight:800; font-size:16px; cursor:pointer; margin-top:6px; }
        .cet-btn:disabled { opacity:.6; cursor:default; }
        .cet-ghost { width:100%; padding:11px; border:1px solid var(--line); border-radius:10px; background:#fff; font-weight:700; cursor:pointer; margin-top:8px; }
        .cet-err { color:#b32020; font-size:13px; margin-top:8px; }
        .cet-foot { text-align:center; color:var(--muted); font-size:11px; margin-top:12px; }
        .cet-hp { position:absolute; left:-9999px; width:1px; height:1px; overflow:hidden; }
        .cet-done { text-align:center; padding:24px 8px; }
        .cet-done .tick { font-size:44px; }
    </style>
</head>
<body>
    <div class="cet-widget" id="cet-widget">
        <div class="cet-head"><span class="cet-dot"></span><span class="cet-brand">CENTRAL <span>EXECUTIVE</span> TRANSFERS</span></div>

        @if($done)
            <div class="cet-done">
                <div class="tick">✅</div>
                <h2 style="margin:8px 0 4px">Booking request received</h2>
                <p style="color:var(--muted);margin:0">
                    Thank you{{ isset($ref) ? ' — your reference is '.$ref : '' }}.
                    Our office will confirm your journey and price shortly. No payment has been taken.
                </p>
            </div>
        @else
            <form id="cet-book" action="{{ route('widget.book.store') }}" method="POST" autocomplete="off">
                @csrf
                {{-- Honeypot: hidden from real users; bots fill it and get bounced. --}}
                <div class="cet-hp"><label>Company<input name="company" tabindex="-1" autocomplete="off"></label></div>

                <div class="cet-sec">Journey</div>
                <div class="cet-field"><label for="b-pickup">Pickup address</label>
                    <input id="b-pickup" name="pickup_address" required placeholder="e.g. Sheffield S1 2HH"></div>
                <div class="cet-field"><label for="b-dropoff">Drop-off address</label>
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
                    <div class="cet-field"><label for="b-flight">Flight number <span style="font-weight:400">(optional)</span></label>
                        <input id="b-flight" name="flight_number" placeholder="e.g. BA1234"></div>
                </div>
                <div class="cet-two">
                    <div class="cet-field"><label for="b-suit">Suitcases</label>
                        <input id="b-suit" name="suitcases" type="number" min="0" max="30" value="0"></div>
                    <div class="cet-field"><label for="b-hand">Hand luggage</label>
                        <input id="b-hand" name="hand_luggage" type="number" min="0" max="30" value="0"></div>
                </div>

                <button type="button" class="cet-ghost" id="cet-check">See my price</button>
                <div class="cet-price" id="cet-price"></div>

                <div class="cet-sec">Your details</div>
                <div class="cet-field"><label for="b-name">Full name</label>
                    <input id="b-name" name="customer_name" required></div>
                <div class="cet-two">
                    <div class="cet-field"><label for="b-phone">Mobile number</label>
                        <input id="b-phone" name="customer_phone" placeholder="07…"></div>
                    <div class="cet-field"><label for="b-email">Email</label>
                        <input id="b-email" name="customer_email" type="email"></div>
                </div>
                <div class="cet-field"><label for="b-notes">Notes for us <span style="font-weight:400">(optional)</span></label>
                    <textarea id="b-notes" name="notes" rows="2" placeholder="Meet &amp; greet, child seat, extra stops…"></textarea></div>

                <button class="cet-btn" type="submit" id="cet-submit">Request booking</button>
                <div class="cet-err" id="cet-err" style="display:none"></div>
                <p class="cet-foot">No payment is taken now — our office confirms your journey and price first.</p>
            </form>
        @endif
    </div>

    <script>
        (function () {
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
                        check.disabled = false; check.textContent = 'See my price';
                        priceBox.style.display = 'block';
                        priceBox.innerHTML = '<b>' + d.formatted + '</b><br><span style="color:#666;font-size:13px">' + d.vehicle + ' · ' + d.basis + ' · guide price, confirmed on booking</span>';
                        reportHeight();
                    })
                    .catch(function () {
                        check.disabled = false; check.textContent = 'See my price';
                        priceBox.style.display = 'block'; priceBox.innerHTML = 'Could not price that just now — you can still request the booking.';
                        reportHeight();
                    });
                });
            }
        })();
    </script>
</body>
</html>
