@extends('layouts.app')
@section('title', 'Instant Quote')

@section('content')
    <h1 class="page-title">Instant Quote</h1>
    <p class="page-sub">AI pricing powered by {{ config('cet.ai_model') }} — distance, time of day, demand and bank holidays.</p>

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
        window.CET_ESTIMATE_URL = "{{ route('pricing.estimate') }}";
    </script>
    <script src="{{ asset('js/cet-forms.js') }}?v=4"></script>
    @verbatim
    <script>
        (function () {
            // Collapsible sections.
            document.querySelectorAll('[data-collapsible] > .head').forEach(function (h) {
                h.addEventListener('click', function () { h.parentNode.classList.toggle('closed'); });
            });
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
