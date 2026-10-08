@extends('layouts.app')
@section('title', 'Instant Quote')

@section('content')
    @include('partials.smart-form-skin')

    <div class="smart-form">
        <div class="smart-hero">
            <div class="brand"><span class="mark">C</span><span class="name">CENTRAL <span>EXECUTIVE</span> TRANSFERS</span></div>
            <span class="eyebrow">Sales · Instant price</span>
            <h1>Get an instant price</h1>
            <p>Smart pricing — distance, time of day, demand and bank holidays. Every vehicle priced side by side so you can quote in seconds.</p>
            <span class="pill">💷 Prices are a guide · confirmed on booking</span>
        </div>

        @if($errors->any())
            <div class="alert alert-error">{{ $errors->first() }}</div>
        @endif

    <form method="POST" action="{{ route('quotes.store') }}" class="eto-form">
        @csrf

        {{-- ───────────── Locations ───────────── --}}
        <div class="eto-section">
            <div class="head"><span class="ico">📍</span> Locations</div>
            <div class="body">
                <div class="field">
                    <label for="pickup_address">Pickup address <span class="req">*</span></label>
                    <div class="loc-row">
                        <span class="pin pickup">A</span>
                        <div class="grow">
                            <textarea id="pickup_address" name="pickup_address" data-places autocomplete="off" placeholder="Start typing an address…" required>{{ old('pickup_address') }}</textarea>
                        </div>
                    </div>
                    <input id="pickup_postcode" name="pickup_postcode" value="{{ old('pickup_postcode') }}" placeholder="Pickup postcode (e.g. S20 1AA) — handy for a quick quote" style="text-transform:uppercase;margin-top:6px">
                </div>

                <div style="margin:4px 0 10px"><button type="button" id="swap-addr" title="Swap pickup and destination" style="border:1px solid var(--line,#ddd);background:#fff;border-radius:999px;padding:6px 13px;font-weight:700;font-size:12.5px;cursor:pointer">⇅ Swap pickup &amp; destination</button></div>

                <div class="field" style="margin-bottom:0">
                    <label for="destination_address">Destination address <span class="req">*</span></label>
                    <div class="loc-row">
                        <span class="pin drop">B</span>
                        <div class="grow">
                            <textarea id="destination_address" name="destination_address" list="destinations" data-places autocomplete="off" placeholder="Start typing an address…" required>{{ old('destination_address') }}</textarea>
                            <datalist id="destinations">
                                @foreach($destinations as $d)<option value="{{ $d }}">@endforeach
                            </datalist>
                        </div>
                    </div>
                    <input id="destination_postcode" name="destination_postcode" value="{{ old('destination_postcode') }}" placeholder="Destination postcode (optional)" style="text-transform:uppercase;margin-top:6px">
                </div>
            </div>
        </div>

        {{-- ───────────── Journey ───────────── --}}
        <div class="eto-section">
            <div class="head"><span class="ico">🚘</span> Journey</div>
            <div class="body">
                <div class="grid grid-2">
                    <div class="field">
                        <label for="vehicle_type_id">Vehicle type <span class="req">*</span> <span id="quote-note" class="muted" style="font-weight:400"></span></label>
                        <select id="vehicle_type_id" name="vehicle_type_id" required>
                            @foreach($vehicleTypes as $vt)
                                <option value="{{ $vt->id }}" @selected(old('vehicle_type_id')==$vt->id)>{{ $vt->name }}</option>
                            @endforeach
                        </select>
                        {{-- Live price for every vehicle at once — tap to pick. --}}
                        <div id="veh-prices" class="veh-prices" hidden></div>
                    </div>
                    <div class="field">
                        <label for="pickup_at">Pickup date &amp; time <span class="req">*</span></label>
                        <input id="pickup_at" type="datetime-local" name="pickup_at" value="{{ old('pickup_at') }}" required>
                    </div>
                </div>
                <div class="checkbox-row" style="padding-top:4px">
                    <input id="is_airport" type="checkbox" name="is_airport" value="1" {{ old('is_airport') ? 'checked' : '' }}>
                    <label for="is_airport">Airport job <span class="muted" style="font-weight:400">— ticks itself for an airport pickup</span></label>
                </div>
            </div>
        </div>

        {{-- ───────────── Extras (CET surcharge list) ───────────── --}}
        @php $sur = config('cet.surcharges'); @endphp
        <div class="eto-section">
            <div class="head"><span class="ico">➕</span> Extras &amp; stopovers</div>
            <div class="body">
                <div class="grid grid-2">
                    <div class="field">
                        <label>&nbsp;</label>
                        <div class="checkbox-row" style="padding-top:8px">
                            <input id="meet_greet" type="checkbox" name="meet_greet" value="1" {{ old('meet_greet') ? 'checked' : '' }}>
                            <label for="meet_greet">Meet &amp; greet (£{{ number_format($sur['meet_greet'], 0) }})</label>
                        </div>
                    </div>
                    <div class="field">
                        <label for="stopovers">Stopovers / via points (£{{ number_format($sur['stopover'], 0) }} each)</label>
                        <select id="stopovers" name="stopovers">
                            @for($i = 0; $i <= 8; $i++)<option value="{{ $i }}" @selected((int) old('stopovers', 0) === $i)>{{ $i }}</option>@endfor
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label for="stopover_addresses">Stopover address(es) — one per line</label>
                    <textarea id="stopover_addresses" name="stopover_addresses" rows="2" placeholder="e.g. 12 Ecclesall Road, Sheffield">{{ old('stopover_addresses') }}</textarea>
                </div>
                <div class="grid grid-3">
                    @foreach(['child_seats' => 'Child seats ('.($sur['child_seat']).')', 'booster_seats' => 'Booster seats ('.($sur['booster_seat']).')', 'infant_seats' => 'Infant seats ('.($sur['infant_seat']).')'] as $field => $label)
                        <div class="field">
                            <label for="{{ $field }}">{{ str_replace(['(', ')'], ['(£', ')'], $label) }}</label>
                            <select id="{{ $field }}" name="{{ $field }}">
                                @for($i = 0; $i <= 2; $i++)<option value="{{ $i }}" @selected((int) old($field, 0) === $i)>{{ $i }}</option>@endfor
                            </select>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ───────────── Fixed-price route (advanced) ───────────── --}}
        @if($zones->isNotEmpty())
        <div class="eto-section collapsible closed" data-collapsible>
            <div class="head"><span class="ico">⚙️</span> Fixed-price route <span class="grow"></span> <span class="chev">▾</span></div>
            <div class="body">
                <p class="hint" style="margin-top:0">Optional — overrides distance pricing for known routes.</p>
                <div class="field">
                    <label for="pricing_zone_id">Origin zone</label>
                    <select id="pricing_zone_id" name="pricing_zone_id">
                        <option value="">— Use distance pricing —</option>
                        @foreach($zones as $zone)
                            <option value="{{ $zone->id }}" @selected(old('pricing_zone_id')==$zone->id)>{{ $zone->name }}</option>
                        @endforeach
                    </select>
                    <div class="hint">The <strong>pickup postcode</strong> above also maps to a zone automatically. Set the destination to a known fixed destination (e.g. {{ $destinations->take(3)->join(', ') }}) to use the matrix price.</div>
                </div>
            </div>
        </div>
        @endif

        {{-- ───────────── Send to customer (optional) ───────────── --}}
        <div class="eto-section">
            <div class="head"><span class="ico">✉️</span> Email the quote <span class="muted" style="font-weight:400">— optional</span></div>
            <div class="body">
                <div class="grid grid-2">
                    <div class="field">
                        <label for="customer_name">Customer name <span class="muted">opt.</span></label>
                        <input id="customer_name" name="customer_name" value="{{ old('customer_name') }}" placeholder="e.g. Jane McGuinness">
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label for="customer_email">Customer email</label>
                        <input id="customer_email" type="email" name="customer_email" value="{{ old('customer_email') }}" placeholder="name@example.com">
                        <div class="hint">Fill this in and the quote is emailed to them when you generate it. Tick VAT above to email the VAT-inclusive price.</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ───────────── Total + submit ───────────── --}}
        <div class="total-bar">
            <div>
                <div class="total-label">Estimated</div>
                <div class="total-amount" id="total-amount">£0.00<span class="basis" id="total-basis">enter journey for a live price</span></div>
            </div>
            <div class="actions" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <label class="checkbox-row" style="margin:0;font-weight:600;font-size:13px"><input type="checkbox" id="apply-vat" name="apply_vat" value="1" {{ old('apply_vat') ? 'checked' : '' }}> Show +20% VAT</label>
                <button type="submit" class="btn btn-primary">Generate quote →</button>
            </div>
        </div>
    </form>
    </div>{{-- /.smart-form --}}

    <script>
        window.CET_MAPS_KEY = "{{ \App\Models\Setting::mapsKey() }}";
        window.CET_PLACES_URL = "{{ route('places.autocomplete') }}";
        window.CET_ADDRESSES_URL = "{{ route('places.addresses') }}";
        window.CET_RESOLVE_URL = "{{ route('places.resolve') }}";
        window.CET_ESTIMATE_URL = "{{ route('pricing.estimate') }}";
        window.CET_PRICES_URL = "{{ route('widget.prices') }}";
    </script>
    <script src="{{ asset('js/cet-forms.js') }}?v=35"></script>
    @verbatim
    <script>
        (function () {
            // Collapsible sections.
            document.querySelectorAll('[data-collapsible] > .head').forEach(function (h) {
                h.addEventListener('click', function () { h.parentNode.classList.toggle('closed'); });
            });

            var pickup = document.getElementById('pickup_address');
            var dest = document.getElementById('destination_address');
            var puPc = document.getElementById('pickup_postcode');
            var dpPc = document.getElementById('destination_postcode');
            var vatChk = document.getElementById('apply-vat');
            function vatOn() { return !!(vatChk && vatChk.checked); }
            function money(n) { return '£' + (Math.round(n * 100) / 100).toFixed(2); }
            function applyVat(n) { return vatOn() ? n * 1.2 : n; }

            // Append the postcode to an address for pricing, like the customer widget —
            // so a quick quote works from just a postcode, and fixed-price zones resolve.
            function withPc(addr, pcEl) {
                var pc = pcEl ? String(pcEl.value || '').trim() : '';
                if (pc && addr.toUpperCase().indexOf(pc.toUpperCase()) === -1) {
                    return (addr ? addr + ', ' : '') + pc;
                }
                return addr;
            }

            // Auto-detect an AIRPORT PICKUP and tick "Airport job" + "Meet & greet"
            // (an arrival gets a name-board greet), just like the customer side.
            var AIRPORT_RE = /\b(airport|terminal\s*\d?|heathrow|gatwick|stansted|luton|\(?(lhr|lgw|stn|ltn|man|lba|ema|bhx|lpl|ncl|gla|edi|brs|exe|dsa)\)?|leeds\s*bradford|east\s*midlands|manchester\s*airport|birmingham\s*airport|liverpool\s*john\s*lennon|newcastle\s*airport|glasgow\s*airport|bristol\s*airport|doncaster\s*sheffield)\b/i;
            var isAirportEl = document.getElementById('is_airport');
            var meetEl = document.getElementById('meet_greet');
            if (meetEl) meetEl.addEventListener('change', function () { meetEl.dataset.userset = '1'; });
            function autoAirport() {
                if (!pickup) return;
                if (!AIRPORT_RE.test(pickup.value || '')) return;
                if (isAirportEl && !isAirportEl.checked) isAirportEl.checked = true;
                // Only auto-tick meet & greet if the operator hasn't set it by hand.
                if (meetEl && !meetEl.dataset.userset && !meetEl.checked) {
                    meetEl.checked = true;
                    refreshStrip(true);
                }
            }
            if (pickup) { pickup.addEventListener('change', autoAirport); pickup.addEventListener('blur', autoAirport); }

            // Swap pickup <-> destination (addresses + postcodes).
            var swap = document.getElementById('swap-addr');
            if (swap) swap.addEventListener('click', function () {
                if (pickup && dest) { var t = pickup.value; pickup.value = dest.value; dest.value = t; pickup.dispatchEvent(new Event('change')); dest.dispatchEvent(new Event('change')); }
                if (puPc && dpPc) { var p = puPc.value; puPc.value = dpPc.value; dpPc.value = p; }
                autoAirport();
            });

            // Live "all vehicles" price strip — tap a chip to pick that vehicle.
            var strip = document.getElementById('veh-prices');
            var vehSel = document.getElementById('vehicle_type_id');
            var whenEl = document.getElementById('pickup_at');
            var names = {}, lastOptions = [], stripHasPrice = false, tokenEl = document.querySelector('meta[name="csrf-token"]');
            var token = tokenEl ? tokenEl.getAttribute('content') : '';
            if (vehSel) Array.prototype.forEach.call(vehSel.options, function (o) { if (o.value) names[o.value] = o.textContent.trim(); });

            function renderStrip() {
                if (!strip) return;
                strip.innerHTML = '';
                lastOptions.forEach(function (o) {
                    var chip = document.createElement('button');
                    chip.type = 'button'; chip.className = 'vp' + (vehSel && String(vehSel.value) === String(o.id) ? ' sel' : '');
                    chip.innerHTML = '<span class="n"></span> <span class="p' + (o.poa ? ' poa' : '') + '"></span>';
                    chip.querySelector('.n').textContent = (names[o.id] || 'Vehicle');
                    chip.querySelector('.p').textContent = (o.poa || o.price == null) ? o.formatted : (money(applyVat(o.price)) + (vatOn() ? ' inc VAT' : ''));
                    chip.addEventListener('click', function () {
                        if (vehSel) { vehSel.value = String(o.id); vehSel.dispatchEvent(new Event('change')); }
                        strip.querySelectorAll('.vp').forEach(function (c) { c.classList.toggle('sel', c === chip); });
                    });
                    strip.appendChild(chip);
                });
                strip.hidden = lastOptions.length === 0;
                setTotalFromStrip();
            }

            // Drive the headline total from the selected vehicle's (postcode-aware) price.
            function setTotalFromStrip() {
                var sel = lastOptions.filter(function (o) { return vehSel && String(o.id) === String(vehSel.value); })[0];
                if (sel && !sel.poa && sel.price != null) { baseTotal = sel.price; stripHasPrice = true; paintTotal(); }
                else { stripHasPrice = false; }
            }

            var timer = null, lastKey = '';
            function refreshStrip(force) {
                if (!strip || !pickup || !dest || !window.CET_PRICES_URL) return;
                var p = withPc(pickup.value.trim(), puPc), d = withPc(dest.value.trim(), dpPc);
                if (p.length < 4 || d.length < 4) { strip.hidden = true; return; }
                var when = whenEl ? (whenEl.value || '') : '';
                var key = p + '||' + d + '||' + when;
                if (key === lastKey && !force) { renderStrip(); return; }
                lastKey = key;
                fetch(window.CET_PRICES_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ pickup: p, destination: d, pickup_at: when })
                }).then(function (r) { return r.ok ? r.json() : Promise.reject(); })
                  .then(function (res) { if (res && res.options) { lastOptions = res.options; renderStrip(); } })
                  .catch(function () {});
            }
            [pickup, dest, puPc, dpPc, whenEl].forEach(function (el) { if (!el) return; el.addEventListener('change', function () { clearTimeout(timer); timer = setTimeout(function () { refreshStrip(false); }, 300); }); el.addEventListener('blur', function () { refreshStrip(false); }); });

            // Total bar — mirror the auto-quote price (#quote-note), applying VAT when on.
            var note = document.getElementById('quote-note');
            var totalAmt = document.getElementById('total-amount');
            var totalBasis = document.getElementById('total-basis');
            var baseTotal = 0;
            function paintTotal() {
                if (!totalAmt) return;
                totalAmt.childNodes[0].nodeValue = money(applyVat(baseTotal));
                if (totalBasis && vatOn() && baseTotal > 0) {
                    if (totalBasis.textContent.indexOf('inc VAT') === -1) totalBasis.textContent += ' · inc 20% VAT';
                }
            }
            if (note && totalAmt && window.MutationObserver) {
                new MutationObserver(function () {
                    var t = note.textContent.replace(/^·\s*/, '').trim();
                    if (!t) return;
                    if (totalBasis) totalBasis.textContent = t;
                    // The postcode-aware vehicle strip is the source of truth when it has
                    // a price; otherwise fall back to the base estimate text.
                    if (!stripHasPrice) {
                        var m = t.match(/£([0-9]+(?:\.[0-9]+)?)/);
                        baseTotal = m ? parseFloat(m[1]) : 0;
                        paintTotal();
                    }
                }).observe(note, { childList: true, characterData: true, subtree: true });
            }

            if (vehSel) vehSel.addEventListener('change', function () { renderStrip(); });
            if (vatChk) vatChk.addEventListener('change', function () { paintTotal(); renderStrip(); });
        })();
    </script>
    @endverbatim
@endsection
