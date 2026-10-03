@extends('layouts.app')
@section('title', 'New Booking')

@section('content')
    @include('partials.smart-form-skin')

    <div class="smart-form">
        <div class="smart-hero">
            <div class="brand"><span class="mark">C</span><span class="name">CENTRAL <span>EXECUTIVE</span> TRANSFERS</span></div>
            <span class="eyebrow">Sales · New job</span>
            <h1>New booking</h1>
            <p>The customer booking form, with your admin controls built in — quote to confirmed booking in under 60 seconds.</p>
            <span class="pill">⚡ Live price · driver &amp; payment control</span>
        </div>

    @if($errors->any())
        <div class="alert alert-error">
            Please correct the {{ $errors->count() }} highlighted {{ Str::plural('field', $errors->count()) }} below.
        </div>
    @endif

    @if($quote)
        <div class="alert alert-success">
            Prefilled from quote <span class="mono">{{ $quote->reference }}</span> —
            £{{ number_format($quote->price, 2) }}{{ $quote->ai_generated ? ' (AI priced)' : '' }}.
        </div>
    @endif

    @if(($enquiry ?? null))
        <div class="alert alert-success">
            ✉️ Prefilled from the email enquiry from <strong>{{ $enquiry->from_name ?: $enquiry->from_email }}</strong> — check the details and confirm.
        </div>
    @endif

    <form method="POST" action="{{ route('bookings.store') }}" class="eto-form">
        @csrf
        @if($quote)<input type="hidden" name="quote_id" value="{{ $quote->id }}">@endif
        @if(($enquiry ?? null))<input type="hidden" name="enquiry_id" value="{{ $enquiry->id }}">@endif
        @php $pf = $prefill ?? []; @endphp

        {{-- ───────────── Customer ───────────── --}}
        <div class="eto-section">
            <div class="head"><span class="ico">👤</span> Customer</div>
            <div class="body">
                <div class="grid grid-2">
                    <div class="field">
                        <label for="customer_name">Full name <span class="req">*</span></label>
                        <input id="customer_name" name="customer_name" value="{{ old('customer_name', $pf['customer_name'] ?? $customer?->name) }}" required>
                        @error('customer_name') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="customer_phone">Mobile number</label>
                        <input id="customer_phone" name="customer_phone" value="{{ old('customer_phone', $pf['customer_phone'] ?? $customer?->phone) }}" placeholder="07…">
                        @error('customer_phone') <div class="error">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="field">
                    <label for="customer_email">Email address</label>
                    <input id="customer_email" type="email" name="customer_email" value="{{ old('customer_email', $pf['customer_email'] ?? $customer?->email) }}">
                    <div class="hint">Provide a phone number or an email so we can send confirmations.</div>
                    @error('customer_email') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        {{-- ───────────── Locations ───────────── --}}
        <div class="eto-section">
            <div class="head"><span class="ico">📍</span> Locations</div>
            <div class="body">
                <div class="field">
                    <label for="journey_type">Journey type <span class="req">*</span></label>
                    <select id="journey_type" name="journey_type" required>
                        <option value="one_way" @selected(old('journey_type','one_way')==='one_way')>One way</option>
                        <option value="return" @selected(old('journey_type')==='return')>Return</option>
                        <option value="hourly" @selected(old('journey_type')==='hourly')>Hourly hire (as directed)</option>
                    </select>
                </div>

                <div class="field" id="hours_field" style="display:none">
                    <label for="hours">Hours booked <span class="req">*</span></label>
                    <div class="stepper" data-stepper style="max-width:200px">
                        <button type="button" data-dec>−</button>
                        <input id="hours" type="number" name="hours" min="1" max="24" step="1" value="{{ old('hours', 3) }}" style="text-align:center">
                        <button type="button" data-inc>+</button>
                    </div>
                    <p class="muted" style="font-size:12px;margin:6px 0 0">Car and driver booked by the hour — no fixed destination. Set the price below.</p>
                    @error('hours') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="pickup_address">Pickup address <span class="req">*</span></label>
                    <div class="loc-row">
                        <span class="pin pickup">A</span>
                        <div class="grow">
                            <textarea id="pickup_address" name="pickup_address" data-places autocomplete="off" placeholder="Start typing an address…" required>{{ old('pickup_address', $pf['pickup_address'] ?? $quote?->pickup_address) }}</textarea>
                        </div>
                    </div>
                    @error('pickup_address') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label>Via stops <span class="muted">(optional)</span></label>
                    <div id="via-stops">
                        @php $oldStops = old('via_stops', ['']); @endphp
                        @foreach($oldStops as $stop)
                            <div class="loc-row" style="margin-bottom:8px">
                                <span class="pin via">•</span>
                                <div class="grow"><input name="via_stops[]" value="{{ $stop }}" data-places autocomplete="off" placeholder="Add a stop along the way"></div>
                            </div>
                        @endforeach
                    </div>
                    <button type="button" class="btn btn-ghost" id="add-stop" style="padding:6px 14px;font-size:13px">+ Add stop</button>
                </div>

                <div class="field">
                    <label for="destination_address">Destination address <span class="req">*</span></label>
                    <div class="loc-row">
                        <span class="pin drop">B</span>
                        <div class="grow">
                            <textarea id="destination_address" name="destination_address" data-places autocomplete="off" placeholder="Start typing an address…" required>{{ old('destination_address', $pf['destination_address'] ?? $quote?->destination_address) }}</textarea>
                        </div>
                    </div>
                    @error('destination_address') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="grid grid-2">
                    <div class="field">
                        <label for="pickup_at">Pickup date &amp; time <span class="req">*</span></label>
                        <input id="pickup_at" type="datetime-local" name="pickup_at" value="{{ old('pickup_at', $pf['pickup_at'] ?? $quote?->pickup_at?->format('Y-m-d\TH:i')) }}" required>
                        @error('pickup_at') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field" id="return_field">
                        <label for="return_pickup_at">Return pickup date &amp; time</label>
                        <input id="return_pickup_at" type="datetime-local" name="return_pickup_at" value="{{ old('return_pickup_at') }}">
                        @error('return_pickup_at') <div class="error">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="field" style="margin-bottom:0">
                    <label for="airport_id">Airport <span class="muted">(drives driver rotation)</span></label>
                    <select id="airport_id" name="airport_id">
                        <option value="">— Not an airport job —</option>
                        @foreach($airports as $airport)
                            <option value="{{ $airport->id }}" @selected(old('airport_id')==$airport->id)>
                                {{ $airport->code }} — {{ $airport->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Airport prompt — appears automatically when the pickup/drop-off is an
                     airport, so the flight number, landing time and meet & greet are never
                     missed on an airport job. --}}
                <div id="airport-note" class="airport-note" hidden>
                    ✈ <strong>Airport job detected.</strong> <span id="airport-note-text">Add the flight number and landing time below so the office can track the flight — meet &amp; greet has been ticked for the arrivals pickup.</span>
                </div>
            </div>
        </div>

        {{-- ───────────── Passengers & vehicle ───────────── --}}
        <div class="eto-section">
            <div class="head"><span class="ico">🧳</span> Passengers &amp; Vehicle</div>
            <div class="body">
                <div class="field">
                    <label for="vehicle_type_id">Vehicle type <span class="req">*</span> <span id="quote-note" class="muted" style="font-weight:400"></span></label>
                    <select id="vehicle_type_id" name="vehicle_type_id" required>
                        <option value="">— Select —</option>
                        @foreach($vehicleTypes as $vt)
                            <option value="{{ $vt->id }}"
                                data-cap-pax="{{ $vt->passenger_capacity }}"
                                data-cap-lug="{{ $vt->luggage_capacity }}"
                                data-cap-hand="{{ $vt->handLuggageCapacity() }}"
                                @selected(old('vehicle_type_id', $pf['vehicle_type_id'] ?? $quote?->vehicle_type_id ?? $customer?->preferred_vehicle_type_id)==$vt->id)>
                                {{ $vt->name }} (up to {{ $vt->passenger_capacity }})
                            </option>
                        @endforeach
                    </select>
                    @error('vehicle_type_id') <div class="error">{{ $message }}</div> @enderror
                    {{-- Live prices for every vehicle — tap one to pick it + set the fare.
                         Fast quoting on the phone. --}}
                    <div id="veh-prices" class="veh-prices" hidden></div>
                </div>

                <div class="stepper-field" style="border-top:1px solid var(--line)">
                    <span class="lbl">Passengers <span class="req">*</span><span class="sub">People travelling</span></span>
                    <div class="stepper" data-stepper>
                        <button type="button" data-dec>−</button>
                        <input id="passengers" type="number" name="passengers" min="1" max="60" value="{{ old('passengers', $pf['passengers'] ?? 1) }}" required>
                        <button type="button" data-inc>+</button>
                    </div>
                </div>
                @error('passengers') <div class="error">{{ $message }}</div> @enderror

                <div class="stepper-field" style="border-top:1px solid var(--line)">
                    <span class="lbl">Suitcases<span class="sub">Large / hold bags</span></span>
                    <div class="stepper" data-stepper>
                        <button type="button" data-dec>−</button>
                        <input id="suitcases" type="number" name="suitcases" min="0" max="30" value="{{ old('suitcases', 0) }}">
                        <button type="button" data-inc>+</button>
                    </div>
                </div>
                @error('suitcases') <div class="error">{{ $message }}</div> @enderror

                <div class="stepper-field" style="border-top:1px solid var(--line)">
                    <span class="lbl">Hand luggage<span class="sub">Cabin / small bags</span></span>
                    <div class="stepper" data-stepper>
                        <button type="button" data-dec>−</button>
                        <input id="hand_luggage" type="number" name="hand_luggage" min="0" max="30" value="{{ old('hand_luggage', 0) }}">
                        <button type="button" data-inc>+</button>
                    </div>
                </div>
                @error('hand_luggage') <div class="error">{{ $message }}</div> @enderror

                <div class="grid grid-2" id="flight-fields" style="border-top:1px solid var(--line);padding-top:16px;margin-top:8px">
                    <div class="field" style="margin-bottom:0">
                        <label for="flight_number">Flight number <span class="muted" id="flight-hint">(if airport)</span></label>
                        <input id="flight_number" name="flight_number" value="{{ old('flight_number') }}" placeholder="e.g. BA1234" style="text-transform:uppercase">
                        @error('flight_number') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field" id="flight-landing-field" style="margin-bottom:0" hidden>
                        <label for="flight_landing_at">Flight landing time <span class="muted">(so we can track it)</span></label>
                        <input id="flight_landing_at" type="datetime-local" name="flight_landing_at" value="{{ old('flight_landing_at') }}">
                    </div>
                </div>
            </div>
        </div>

        {{-- ───────────── Extras ───────────── --}}
        <div class="eto-section">
            <div class="head"><span class="ico">✨</span> Extras</div>
            <div class="body">
                <div class="checkbox-row" style="margin-bottom:12px">
                    <input id="meet_greet" type="checkbox" name="meet_greet" value="1" {{ old('meet_greet') ? 'checked' : '' }}>
                    <label for="meet_greet">Meet &amp; greet <span class="muted">(driver waits in arrivals with a name board)</span></label>
                </div>
                <div class="stepper-field" style="border-top:1px solid var(--line)">
                    <span class="lbl">Child seats<span class="sub">Ages ~4–7</span></span>
                    <div class="stepper" data-stepper><button type="button" data-dec>−</button>
                        <input id="child_seats" type="number" name="child_seats" min="0" max="8" value="{{ old('child_seats', 0) }}"><button type="button" data-inc>+</button></div>
                </div>
                <div class="stepper-field" style="border-top:1px solid var(--line)">
                    <span class="lbl">Booster seats<span class="sub">Ages ~7–11</span></span>
                    <div class="stepper" data-stepper><button type="button" data-dec>−</button>
                        <input id="booster_seats" type="number" name="booster_seats" min="0" max="8" value="{{ old('booster_seats', 0) }}"><button type="button" data-inc>+</button></div>
                </div>
                <div class="stepper-field" style="border-top:1px solid var(--line)">
                    <span class="lbl">Infant seats<span class="sub">Rear-facing</span></span>
                    <div class="stepper" data-stepper><button type="button" data-dec>−</button>
                        <input id="infant_seats" type="number" name="infant_seats" min="0" max="8" value="{{ old('infant_seats', 0) }}"><button type="button" data-inc>+</button></div>
                </div>
                <div class="checkbox-row" style="border-top:1px solid var(--line);padding-top:12px;margin-top:4px">
                    <input id="ribbon" type="checkbox" name="ribbon" value="1" {{ old('ribbon') ? 'checked' : '' }}>
                    <label for="ribbon">Wedding ribbons</label>
                </div>
                <div class="checkbox-row">
                    <input id="wheelchair" type="checkbox" name="wheelchair" value="1" {{ old('wheelchair') ? 'checked' : '' }}>
                    <label for="wheelchair">Wheelchair accessible</label>
                </div>
            </div>
        </div>

        {{-- ───────────── Payment & driver ───────────── --}}
        <div class="eto-section">
            <div class="head"><span class="ico">💳</span> Payment &amp; Driver</div>
            <div class="body">
                @if($corporateAccounts->isNotEmpty())
                    <div class="grid grid-2">
                        <div class="field">
                            <label for="corporate_account_id">Corporate account</label>
                            <select id="corporate_account_id" name="corporate_account_id">
                                <option value="">— Private booking —</option>
                                @foreach($corporateAccounts as $acc)
                                    <option value="{{ $acc->id }}"
                                        data-cost-code-required="{{ $acc->cost_code_required ? '1' : '0' }}"
                                        @selected(old('corporate_account_id')==$acc->id)>{{ $acc->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="cost_code">Cost code</label>
                            <input id="cost_code" name="cost_code" value="{{ old('cost_code') }}">
                            @error('cost_code') <div class="error">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="field">
                        <label for="corporate_reference">Corporate reference / PO</label>
                        <input id="corporate_reference" name="corporate_reference" value="{{ old('corporate_reference') }}">
                    </div>
                @endif

                <div class="grid grid-2">
                    <div class="field">
                        <label for="payment_method">Payment method <span class="req">*</span></label>
                        <select id="payment_method" name="payment_method" required>
                            <option value="card" @selected(old('payment_method','card')==='card')>Card (Tide link)</option>
                            <option value="cash" @selected(old('payment_method')==='cash')>Cash</option>
                            <option value="account" @selected(old('payment_method')==='account')>Account (invoiced)</option>
                        </select>
                        @error('payment_method') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="quoted_price">Quoted price (£)</label>
                        <input id="quoted_price" type="number" step="0.01" min="0" name="quoted_price" value="{{ old('quoted_price', $pf['quoted_price'] ?? $quote?->price) }}">
                        <div id="price-nudge" class="price-nudge" hidden></div>
                        @error('quoted_price') <div class="error">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- ───────────── Advanced ───────────── --}}
        <div class="eto-section collapsible closed" data-collapsible>
            <div class="head"><span class="ico">⚙️</span> Advanced <span class="grow"></span> <span class="chev">▾</span></div>
            <div class="body">
                <div class="field">
                    <label for="special_requests">Special requests</label>
                    <textarea id="special_requests" name="special_requests" placeholder="Child seat, meet &amp; greet, name board…">{{ old('special_requests', $pf['special_requests'] ?? null) }}</textarea>
                    @error('special_requests') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field" style="margin-bottom:0">
                    <label for="driver_notes">📝 Notes for the driver</label>
                    <textarea id="driver_notes" name="driver_notes" placeholder="Extra info the customer gave — e.g. call on arrival, side entrance, luggage help needed…">{{ old('driver_notes') }}</textarea>
                    @error('driver_notes') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        {{-- ───────────── Consent ───────────── --}}
        <div class="eto-section">
            <div class="head"><span class="ico">🔒</span> Consent</div>
            <div class="body">
                <div class="checkbox-row">
                    <input id="privacy_consent" type="checkbox" name="privacy_consent" value="1" {{ old('privacy_consent') ? 'checked' : '' }} required>
                    <label for="privacy_consent">
                        I confirm the passenger consents to their data being processed to fulfil this booking,
                        in line with the CET Privacy Notice (UK GDPR), and to being contacted about it via a
                        masked CET contact number. <span class="req">*</span>
                    </label>
                </div>
                @error('privacy_consent') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>

        {{-- ───────────── Total + submit ───────────── --}}
        <div class="total-bar">
            <div>
                <div class="total-label">Total</div>
                <div class="total-amount" id="total-amount">£{{ number_format(old('quoted_price', $quote?->price ?? 0), 2) }}<span class="basis" id="total-basis">enter journey for a live price</span></div>
            </div>
            <div class="actions">
                <a href="{{ route('bookings.index') }}" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">Confirm booking →</button>
            </div>
        </div>
    </form>
    </div>{{-- /.smart-form --}}

    <style>
        /* A vehicle too small for the passengers/luggage entered — greyed, like
           the customer booking page, so the agent sees what actually fits. */
        .veh-prices .vp.unfit { opacity: .4; }
        .veh-prices .vp.unfit .n::after { content: ' ⚠'; }
        /* Price-drift nudge: the typed price differs from the live full quote. */
        .price-nudge { margin-top: 6px; font-size: 13px; color: #8a6d00; background: #fff0cc;
            border: 1px solid #FBBA2A; border-radius: 6px; padding: 6px 10px; }
        .price-nudge .link { background: none; border: 0; padding: 0; color: #1d4ed8;
            font: inherit; font-weight: 600; text-decoration: underline; cursor: pointer; }
    </style>

    <script>
        window.CET_MAPS_KEY = "{{ \App\Models\Setting::mapsKey() }}";
        window.CET_PLACES_URL = "{{ route('places.autocomplete') }}";
        window.CET_ADDRESSES_URL = "{{ route('places.addresses') }}";
        window.CET_RESOLVE_URL = "{{ route('places.resolve') }}";
        window.CET_ESTIMATE_URL = "{{ route('pricing.estimate') }}";
        window.CET_PRICES_URL = "{{ route('widget.prices') }}";
        window.CET_STRIP_URL = "{{ route('pricing.strip') }}";
    </script>
    <script src="{{ asset('js/cet-forms.js') }}?v=35"></script>
    @verbatim
    <script>
        (function () {
            // Add via-stop inputs (autocomplete attaches via the shared helper).
            var addBtn = document.getElementById('add-stop');
            var wrap = document.getElementById('via-stops');
            if (addBtn) {
                addBtn.addEventListener('click', function () {
                    var row = document.createElement('div');
                    row.className = 'loc-row';
                    row.style.marginBottom = '8px';
                    var pin = document.createElement('span');
                    pin.className = 'pin via'; pin.textContent = '•';
                    var grow = document.createElement('div');
                    grow.className = 'grow';
                    var input = document.createElement('input');
                    input.name = 'via_stops[]';
                    input.placeholder = 'Add a stop along the way';
                    input.setAttribute('data-places', '');
                    input.setAttribute('autocomplete', 'off');
                    grow.appendChild(input);
                    row.appendChild(pin); row.appendChild(grow);
                    wrap.appendChild(row);
                    if (window.CETattachPlaces) window.CETattachPlaces(input);
                });
            }
            // Show the return field for returns, the hours field for hourly hire,
            // and drop the "destination required" rule when it's an as-directed job.
            var journey = document.getElementById('journey_type');
            var returnField = document.getElementById('return_field');
            var hoursField = document.getElementById('hours_field');
            var destField = document.getElementById('destination_address');
            var destLabel = destField ? destField.closest('.field').querySelector('label') : null;
            var destLabelHtml = destLabel ? destLabel.innerHTML : '';
            function toggleJourney() {
                var isReturn = journey.value === 'return';
                var isHourly = journey.value === 'hourly';
                if (returnField) returnField.style.display = isReturn ? '' : 'none';
                if (hoursField) hoursField.style.display = isHourly ? '' : 'none';
                if (destField) {
                    destField.required = !isHourly;
                    destField.placeholder = isHourly ? 'As directed — leave blank for hourly hire' : 'Start typing an address…';
                    if (destLabel) destLabel.innerHTML = isHourly ? 'Destination address <span class="muted">(optional — as directed)</span>' : destLabelHtml;
                }
            }
            if (journey) { journey.addEventListener('change', toggleJourney); toggleJourney(); }

            // ── Airport reactivity ─────────────────────────────────────────────
            // Mirror the customer side: when the pickup/drop-off is an airport, prompt
            // for the flight number (required) + landing time, and pre-tick meet &
            // greet for an arrivals pickup — so key info is never missed.
            (function () {
                var airportSel = document.getElementById('airport_id');
                var pickup = document.getElementById('pickup_address');
                var dest = document.getElementById('destination_address');
                var note = document.getElementById('airport-note');
                var noteText = document.getElementById('airport-note-text');
                var flightIn = document.getElementById('flight_number');
                var flightHint = document.getElementById('flight-hint');
                var landingField = document.getElementById('flight-landing-field');
                var meet = document.getElementById('meet_greet');
                if (!flightIn) return;
                var mgAuto = false; // only auto-manage the tick we set ourselves

                var rx = /\bairport\b|terminal|heathrow|gatwick|stansted|luton|\bman\b|manchester airport|east midlands|\bema\b|\blba\b|leeds bradford|doncaster|\bt[1-5]\b/i;
                function looksAirport(v) { return rx.test(String(v || '')); }
                function isPickupAirport() { return (airportSel && airportSel.value !== '') || looksAirport(pickup && pickup.value); }
                function isAnyAirport() { return isPickupAirport() || looksAirport(dest && dest.value); }

                function apply() {
                    var any = isAnyAirport();
                    var arrival = isPickupAirport();
                    if (note) note.hidden = !any;
                    if (landingField) landingField.hidden = !any;
                    // Flight number becomes required on any airport job.
                    flightIn.required = any;
                    if (flightHint) flightHint.textContent = any ? '— required for airport jobs' : '(if airport)';
                    if (noteText) noteText.textContent = arrival
                        ? 'Add the flight number and landing time below so the office can track the flight — meet & greet has been ticked for the arrivals pickup.'
                        : 'Add the flight number and landing time below so the office can track the flight.';
                    // Pre-tick meet & greet for an ARRIVAL (airport pickup); untick the
                    // auto-tick if it stops being an arrival, but never fight a manual tick.
                    if (meet) {
                        if (arrival && !meet.checked) { meet.checked = true; mgAuto = true; }
                        else if (!arrival && mgAuto && meet.checked) { meet.checked = false; mgAuto = false; }
                    }
                }
                if (meet) meet.addEventListener('change', function () { mgAuto = false; });
                [airportSel, pickup, dest].forEach(function (el) {
                    if (!el) return;
                    el.addEventListener('change', apply);
                    el.addEventListener('blur', apply);
                    el.addEventListener('input', apply);
                });
                apply();
            })();

            // Number steppers.
            document.querySelectorAll('[data-stepper]').forEach(function (s) {
                var inp = s.querySelector('input');
                function clamp(v) {
                    var min = inp.min !== '' ? +inp.min : -Infinity;
                    var max = inp.max !== '' ? +inp.max : Infinity;
                    return Math.max(min, Math.min(max, v));
                }
                s.querySelector('[data-dec]').addEventListener('click', function () { inp.value = clamp((+inp.value || 0) - 1); inp.dispatchEvent(new Event('change')); });
                s.querySelector('[data-inc]').addEventListener('click', function () { inp.value = clamp((+inp.value || 0) + 1); inp.dispatchEvent(new Event('change')); });
            });

            // Collapsible sections.
            document.querySelectorAll('[data-collapsible] > .head').forEach(function (h) {
                h.addEventListener('click', function () { h.parentNode.classList.toggle('closed'); });
            });

            // Live FULL prices for EVERY vehicle (incl. via-stop fee + ticked
            // extras) as the agent types the journey — tap a chip to pick that
            // vehicle and drop its fare in. Also greys out vehicles too small for
            // the passengers/luggage entered (like the customer booking page).
            (function () {
                var strip = document.getElementById('veh-prices');
                var pickup = document.getElementById('pickup_address');
                var dest = document.getElementById('destination_address');
                var vehSel = document.getElementById('vehicle_type_id');
                var priceEl = document.getElementById('quoted_price');
                var url = window.CET_STRIP_URL || window.CET_PRICES_URL;
                if (!strip || !pickup || !dest || !vehSel || !url) return;
                var tokenEl = document.querySelector('meta[name="csrf-token"]');
                var token = tokenEl ? tokenEl.getAttribute('content') : '';
                var whenEl = document.getElementById('pickup_at');
                var paxEl = document.getElementById('passengers');
                var suitEl = document.getElementById('suitcases');
                var handEl = document.getElementById('hand_luggage');

                // id -> name + capacities, from the select options.
                var names = {}, caps = {};
                Array.prototype.forEach.call(vehSel.options, function (o) {
                    if (!o.value) return;
                    names[o.value] = o.textContent.trim();
                    caps[o.value] = {
                        pax: parseInt(o.getAttribute('data-cap-pax'), 10) || 0,
                        lug: parseInt(o.getAttribute('data-cap-lug'), 10) || 0,
                        hand: parseInt(o.getAttribute('data-cap-hand'), 10) || 0
                    };
                });
                var timer = null, lastKey = '';

                function num(el) { var n = parseInt(el && el.value, 10); return isNaN(n) ? 0 : n; }

                // Count via stops with an address + read the ticked priced extras.
                function extras() {
                    var stops = 0;
                    document.querySelectorAll('input[name="via_stops[]"]').forEach(function (s) { if (s.value.trim()) stops++; });
                    return {
                        stops: stops,
                        meet_greet: (document.getElementById('meet_greet') || {}).checked ? 1 : 0,
                        child_seats: num(document.getElementById('child_seats')),
                        booster_seats: num(document.getElementById('booster_seats')),
                        infant_seats: num(document.getElementById('infant_seats')),
                        ribbons: (document.getElementById('ribbon') || {}).checked ? 1 : 0
                    };
                }

                // Grey out the chips for vehicles too small for the party entered.
                function applyFit() {
                    var pax = num(paxEl), suit = num(suitEl), hand = num(handEl);
                    strip.querySelectorAll('.vp').forEach(function (chip) {
                        var c = caps[chip.dataset.id];
                        var fits = !c || (pax <= c.pax && suit <= c.lug && hand <= c.hand);
                        chip.classList.toggle('unfit', !fits);
                        chip.title = fits ? '' : 'Too small for ' + pax + ' passengers / ' + (suit + hand) + ' bags';
                    });
                }

                var priceNudge = document.getElementById('price-nudge');
                var lastOptions = [];

                // Nudge when the typed price doesn't match the live full quote for
                // the CHOSEN vehicle (incl. via-stop fee + ticked extras). Catches
                // both a stale typed price and extras ticked but not folded in.
                function nudge() {
                    if (!priceNudge || !priceEl) return;
                    var opt = lastOptions.filter(function (o) { return String(o.id) === String(vehSel.value); })[0];
                    if (!opt || opt.price == null || opt.poa) { priceNudge.hidden = true; return; }
                    var typed = parseFloat(priceEl.value);
                    var live = Number(opt.price);
                    if (isNaN(typed) || Math.abs(typed - live) < 0.01) { priceNudge.hidden = true; return; }
                    var extra = Number(opt.extras_total || 0) > 0 ? ' (incl. stop / extras)' : '';
                    priceNudge.innerHTML = '';
                    var span = document.createElement('span');
                    span.textContent = 'Live quote for this vehicle is £' + live.toFixed(2) + extra + '. ';
                    var btn = document.createElement('button');
                    btn.type = 'button'; btn.className = 'link'; btn.textContent = 'Use £' + live.toFixed(2);
                    btn.addEventListener('click', function () { priceEl.value = live.toFixed(2); priceEl.dispatchEvent(new Event('input')); nudge(); });
                    priceNudge.appendChild(span); priceNudge.appendChild(btn);
                    priceNudge.hidden = false;
                }

                function render(options) {
                    lastOptions = options || [];
                    strip.innerHTML = '';
                    options.forEach(function (o) {
                        var chip = document.createElement('button');
                        chip.type = 'button'; chip.className = 'vp' + (String(vehSel.value) === String(o.id) ? ' sel' : '');
                        chip.dataset.id = o.id; chip.dataset.price = (o.price == null ? '' : o.price);
                        chip.innerHTML = '<span class="n"></span> <span class="p' + (o.poa ? ' poa' : '') + '"></span>';
                        chip.querySelector('.n').textContent = (names[o.id] || 'Vehicle').replace(/\s*\(up to.*\)$/, '');
                        chip.querySelector('.p').textContent = o.formatted;
                        chip.addEventListener('click', function () {
                            vehSel.value = String(o.id); vehSel.dispatchEvent(new Event('change'));
                            if (priceEl && o.price != null) { priceEl.value = Number(o.price).toFixed(2); priceEl.dispatchEvent(new Event('input')); }
                            strip.querySelectorAll('.vp').forEach(function (c) { c.classList.toggle('sel', c === chip); });
                        });
                        strip.appendChild(chip);
                    });
                    strip.hidden = options.length === 0;
                    applyFit();
                    nudge();
                }

                function refresh() {
                    var p = pickup.value.trim(), d = dest.value.trim();
                    if (p.length < 4 || d.length < 4) { strip.hidden = true; return; }
                    var when = whenEl ? (whenEl.value || '') : '';
                    var ex = extras();
                    // Re-quote when the journey, time, stops or priced extras change.
                    var key = [p, d, when, ex.stops, ex.meet_greet, ex.child_seats, ex.booster_seats, ex.infant_seats, ex.ribbons].join('||');
                    if (key === lastKey) return;
                    lastKey = key;
                    var body = { pickup: p, destination: d, pickup_at: when };
                    for (var k in ex) { body[k] = ex[k]; }
                    fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                        body: JSON.stringify(body)
                    }).then(function (r) { return r.ok ? r.json() : Promise.reject(); })
                      .then(function (d) { if (d && d.options) render(d.options); })
                      .catch(function () {});
                }

                // Journey / time / stops / extras change the PRICE → re-quote.
                var priceInputs = [pickup, dest, whenEl];
                document.querySelectorAll('input[name="via_stops[]"], #meet_greet, #child_seats, #booster_seats, #infant_seats, #ribbon').forEach(function (el) { priceInputs.push(el); });
                priceInputs.forEach(function (el) { if (!el) return; el.addEventListener('change', function () { clearTimeout(timer); timer = setTimeout(refresh, 300); }); el.addEventListener('blur', refresh); });
                // Passengers / luggage change only the FIT, not the price.
                [paxEl, suitEl, handEl, vehSel].forEach(function (el) { if (el) el.addEventListener('change', applyFit); });
                // Choosing a vehicle, or editing the price, re-checks the drift nudge.
                if (vehSel) vehSel.addEventListener('change', nudge);
                if (priceEl) { priceEl.addEventListener('input', nudge); priceEl.addEventListener('change', nudge); }

                // Soft confirm on submit if the chosen vehicle is too small for the
                // party entered — the office can override (it sometimes knows better),
                // but it won't save an over-capacity job by accident.
                var formEl = vehSel.form;
                if (formEl) {
                    formEl.addEventListener('submit', function (e) {
                        var c = caps[vehSel.value];
                        if (!c) return;
                        var pax = num(paxEl), suit = num(suitEl), hand = num(handEl);
                        if (pax <= c.pax && suit <= c.lug && hand <= c.hand) return;
                        var name = (names[vehSel.value] || 'This vehicle').replace(/\s*\(up to.*\)$/, '');
                        var why = [];
                        if (pax > c.pax) why.push(pax + ' passengers (seats ' + c.pax + ')');
                        if (suit > c.lug) why.push(suit + ' suitcases (holds ' + c.lug + ')');
                        if (hand > c.hand) why.push(hand + ' hand bags (holds ' + c.hand + ')');
                        if (!confirm(name + ' is too small for ' + why.join(' and ') + '.\n\nSave this booking anyway?')) {
                            e.preventDefault();
                        }
                    });
                }
            })();

            // Mirror the quoted price into the total bar.
            var price = document.getElementById('quoted_price');
            var totalAmt = document.getElementById('total-amount');
            var totalBasis = document.getElementById('total-basis');
            function syncTotal() {
                if (!totalAmt) return;
                var v = parseFloat(price.value);
                totalAmt.childNodes[0].nodeValue = '£' + (isNaN(v) ? '0.00' : v.toFixed(2));
            }
            if (price) { price.addEventListener('input', syncTotal); price.addEventListener('change', syncTotal); }
            // The auto-quote helper writes the basis into #quote-note; mirror it here too.
            var note = document.getElementById('quote-note');
            if (note && totalBasis && window.MutationObserver) {
                new MutationObserver(function () {
                    var t = note.textContent.replace(/^·\s*/, '').trim();
                    if (t) totalBasis.textContent = t;
                    syncTotal();
                }).observe(note, { childList: true, characterData: true, subtree: true });
            }
        })();
    </script>
    @endverbatim
@endsection
