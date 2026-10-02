@extends('layouts.app')
@section('title', 'Instant Quote')

@section('content')
    <style>
        .veh-prices { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
        .veh-prices .vp { display:flex; align-items:center; gap:8px; border:1px solid var(--line); border-radius:10px;
            padding:8px 12px; background:#fff; cursor:pointer; font-size:13px; transition:border-color .12s, box-shadow .12s; }
        .veh-prices .vp:hover { border-color:var(--gold, #FBBA2A); }
        .veh-prices .vp.sel { border-color:var(--gold, #FBBA2A); box-shadow:0 0 0 3px rgba(251,186,42,.18); }
        .veh-prices .vp .n { font-weight:600; } .veh-prices .vp .p { font-weight:800; }
        .veh-prices .vp .p.poa { font-weight:600; color:var(--muted, #666); }
    </style>
    <h1 class="page-title">Instant Quote</h1>
    <p class="page-sub">Smart AI pricing — distance, time of day, demand and bank holidays. All vehicle prices shown side by side.</p>

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
                </div>
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
                <div class="grid grid-3">
                    <div class="field">
                        <label for="distance_miles">Distance (miles) <span class="muted">opt.</span></label>
                        <input id="distance_miles" type="number" step="0.1" min="0" name="distance_miles" value="{{ old('distance_miles') }}">
                        <div class="hint">Left blank, the system estimates it.</div>
                    </div>
                    <div class="field">
                        <label for="duration_minutes">Duration (mins) <span class="muted">opt.</span></label>
                        <input id="duration_minutes" type="number" min="0" name="duration_minutes" value="{{ old('duration_minutes') }}">
                    </div>
                    <div class="field">
                        <label>&nbsp;</label>
                        <div class="checkbox-row" style="padding-top:8px">
                            <input id="is_airport" type="checkbox" name="is_airport" value="1" {{ old('is_airport') ? 'checked' : '' }}>
                            <label for="is_airport">Airport job</label>
                        </div>
                    </div>
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
                        <input id="stopovers" type="number" name="stopovers" min="0" max="8" value="{{ old('stopovers', 0) }}" style="width:110px">
                    </div>
                </div>
                <div class="field">
                    <label for="stopover_addresses">Stopover address(es) — one per line</label>
                    <textarea id="stopover_addresses" name="stopover_addresses" rows="2" placeholder="e.g. 12 Ecclesall Road, Sheffield">{{ old('stopover_addresses') }}</textarea>
                </div>
                <div class="grid grid-3">
                    <div class="field">
                        <label for="child_seats">Child seats (£{{ number_format($sur['child_seat'], 0) }})</label>
                        <input id="child_seats" type="number" name="child_seats" min="0" max="8" value="{{ old('child_seats', 0) }}">
                    </div>
                    <div class="field">
                        <label for="booster_seats">Booster seats (£{{ number_format($sur['booster_seat'], 0) }})</label>
                        <input id="booster_seats" type="number" name="booster_seats" min="0" max="8" value="{{ old('booster_seats', 0) }}">
                    </div>
                    <div class="field">
                        <label for="infant_seats">Infant seats (£{{ number_format($sur['infant_seat'], 0) }})</label>
                        <input id="infant_seats" type="number" name="infant_seats" min="0" max="8" value="{{ old('infant_seats', 0) }}">
                    </div>
                </div>
            </div>
        </div>

        {{-- ───────────── Fixed-price route (advanced) ───────────── --}}
        @if($zones->isNotEmpty())
        <div class="eto-section collapsible closed" data-collapsible>
            <div class="head"><span class="ico">⚙️</span> Fixed-price route <span class="grow"></span> <span class="chev">▾</span></div>
            <div class="body">
                <p class="hint" style="margin-top:0">Optional — overrides distance pricing for known routes.</p>
                <div class="grid grid-2">
                    <div class="field">
                        <label for="pricing_zone_id">Origin zone</label>
                        <select id="pricing_zone_id" name="pricing_zone_id">
                            <option value="">— Use distance pricing —</option>
                            @foreach($zones as $zone)
                                <option value="{{ $zone->id }}" @selected(old('pricing_zone_id')==$zone->id)>{{ $zone->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="pickup_postcode">…or pickup postcode</label>
                        <input id="pickup_postcode" name="pickup_postcode" value="{{ old('pickup_postcode') }}" placeholder="e.g. S20 1AA">
                        <div class="hint">If the postcode maps to a zone, the fixed price is used.</div>
                    </div>
                </div>
                <p class="hint" style="margin-bottom:0">Set the destination above to a known fixed destination (e.g. {{ $destinations->take(3)->join(', ') }}) to use the matrix price.</p>
            </div>
        </div>
        @endif

        {{-- ───────────── Total + submit ───────────── --}}
        <div class="total-bar">
            <div>
                <div class="total-label">Estimated</div>
                <div class="total-amount" id="total-amount">£0.00<span class="basis" id="total-basis">enter journey for a live price</span></div>
            </div>
            <div class="actions">
                <button type="submit" class="btn btn-primary">Generate Quote</button>
            </div>
        </div>
    </form>

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

            // Live "all vehicles" price strip — tap a chip to pick that vehicle.
            (function () {
                var strip = document.getElementById('veh-prices');
                var pickup = document.getElementById('pickup_address');
                var dest = document.getElementById('destination_address');
                var vehSel = document.getElementById('vehicle_type_id');
                var whenEl = document.getElementById('pickup_at');
                if (!strip || !pickup || !dest || !vehSel || !window.CET_PRICES_URL) return;
                var tokenEl = document.querySelector('meta[name="csrf-token"]');
                var token = tokenEl ? tokenEl.getAttribute('content') : '';
                var names = {};
                Array.prototype.forEach.call(vehSel.options, function (o) { if (o.value) names[o.value] = o.textContent.trim(); });
                var timer = null, lastKey = '';
                function render(options) {
                    strip.innerHTML = '';
                    options.forEach(function (o) {
                        var chip = document.createElement('button');
                        chip.type = 'button'; chip.className = 'vp' + (String(vehSel.value) === String(o.id) ? ' sel' : '');
                        chip.innerHTML = '<span class="n"></span> <span class="p' + (o.poa ? ' poa' : '') + '"></span>';
                        chip.querySelector('.n').textContent = (names[o.id] || 'Vehicle');
                        chip.querySelector('.p').textContent = o.formatted;
                        chip.addEventListener('click', function () {
                            vehSel.value = String(o.id); vehSel.dispatchEvent(new Event('change'));
                            strip.querySelectorAll('.vp').forEach(function (c) { c.classList.toggle('sel', c === chip); });
                        });
                        strip.appendChild(chip);
                    });
                    strip.hidden = options.length === 0;
                }
                function refresh() {
                    var p = pickup.value.trim(), d = dest.value.trim();
                    if (p.length < 4 || d.length < 4) { strip.hidden = true; return; }
                    var when = whenEl ? (whenEl.value || '') : '';
                    var key = p + '||' + d + '||' + when;
                    if (key === lastKey) return;
                    lastKey = key;
                    fetch(window.CET_PRICES_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                        body: JSON.stringify({ pickup: p, destination: d, pickup_at: when })
                    }).then(function (r) { return r.ok ? r.json() : Promise.reject(); })
                      .then(function (d) { if (d && d.options) render(d.options); })
                      .catch(function () {});
                }
                [pickup, dest, whenEl].forEach(function (el) { if (!el) return; el.addEventListener('change', function () { clearTimeout(timer); timer = setTimeout(refresh, 300); }); el.addEventListener('blur', refresh); });
            })();
            // Mirror the auto-quote basis/price (written to #quote-note) into the total bar.
            var note = document.getElementById('quote-note');
            var totalAmt = document.getElementById('total-amount');
            var totalBasis = document.getElementById('total-basis');
            if (note && totalAmt && window.MutationObserver) {
                new MutationObserver(function () {
                    var t = note.textContent.replace(/^·\s*/, '').trim();
                    if (!t) return;
                    totalBasis.textContent = t;
                    var m = t.match(/£([0-9]+(?:\.[0-9]+)?)/);
                    totalAmt.childNodes[0].nodeValue = m ? '£' + parseFloat(m[1]).toFixed(2) : '£0.00';
                }).observe(note, { childList: true, characterData: true, subtree: true });
            }
        })();
    </script>
    @endverbatim
@endsection
