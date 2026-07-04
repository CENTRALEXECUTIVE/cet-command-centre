@extends('layouts.app')
@section('title', 'New Booking')

@section('content')
    <h1 class="page-title">Smart Booking</h1>
    <p class="page-sub">Quote to confirmed booking in under 60 seconds.</p>

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

    <form method="POST" action="{{ route('bookings.store') }}">
        @csrf
        @if($quote)<input type="hidden" name="quote_id" value="{{ $quote->id }}">@endif

        <fieldset>
            <legend>Passenger</legend>
            <div class="grid grid-2">
                <div class="field">
                    <label for="customer_name">Full name <span class="req">*</span></label>
                    <input id="customer_name" name="customer_name" value="{{ old('customer_name') }}" required>
                    @error('customer_name') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="customer_phone">Mobile number</label>
                    <input id="customer_phone" name="customer_phone" value="{{ old('customer_phone') }}" placeholder="07…">
                    @error('customer_phone') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="field">
                <label for="customer_email">Email address</label>
                <input id="customer_email" type="email" name="customer_email" value="{{ old('customer_email') }}">
                <div class="hint">Provide a phone number or an email so we can send confirmations.</div>
                @error('customer_email') <div class="error">{{ $message }}</div> @enderror
            </div>
        </fieldset>

        <fieldset>
            <legend>Journey</legend>
            <div class="field">
                <label for="journey_type">Journey type <span class="req">*</span></label>
                <select id="journey_type" name="journey_type" required>
                    <option value="one_way" @selected(old('journey_type','one_way')==='one_way')>One way</option>
                    <option value="return" @selected(old('journey_type')==='return')>Return</option>
                </select>
            </div>

            <div class="grid grid-2">
                <div class="field">
                    <label for="pickup_at">Pickup date &amp; time <span class="req">*</span></label>
                    <input id="pickup_at" type="datetime-local" name="pickup_at" value="{{ old('pickup_at', $quote?->pickup_at?->format('Y-m-d\TH:i')) }}" required>
                    @error('pickup_at') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field" id="return_field">
                    <label for="return_pickup_at">Return pickup date &amp; time</label>
                    <input id="return_pickup_at" type="datetime-local" name="return_pickup_at" value="{{ old('return_pickup_at') }}">
                    @error('return_pickup_at') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="grid grid-2">
                <div class="field">
                    <label for="pickup_address">Pickup address <span class="req">*</span></label>
                    <textarea id="pickup_address" name="pickup_address" data-places autocomplete="off" required>{{ old('pickup_address', $quote?->pickup_address) }}</textarea>
                    @error('pickup_address') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="destination_address">Destination address <span class="req">*</span></label>
                    <textarea id="destination_address" name="destination_address" data-places autocomplete="off" required>{{ old('destination_address', $quote?->destination_address) }}</textarea>
                    @error('destination_address') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="field">
                <label>Via stops <span class="muted">(optional)</span></label>
                <div id="via-stops">
                    @php $oldStops = old('via_stops', ['']); @endphp
                    @foreach($oldStops as $stop)
                        <input name="via_stops[]" value="{{ $stop }}" data-places autocomplete="off" placeholder="Add a stop along the way" style="margin-bottom:8px">
                    @endforeach
                </div>
                <button type="button" class="btn btn-ghost" id="add-stop" style="padding:6px 14px;font-size:13px">+ Add stop</button>
            </div>

            <div class="field">
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
        </fieldset>

        <fieldset>
            <legend>Vehicle &amp; Details</legend>
            <div class="grid grid-2">
                <div class="field">
                    <label for="vehicle_type_id">Vehicle type <span class="req">*</span></label>
                    <select id="vehicle_type_id" name="vehicle_type_id" required>
                        <option value="">— Select —</option>
                        @foreach($vehicleTypes as $vt)
                            <option value="{{ $vt->id }}" @selected(old('vehicle_type_id', $quote?->vehicle_type_id)==$vt->id)>
                                {{ $vt->name }} (up to {{ $vt->passenger_capacity }})
                            </option>
                        @endforeach
                    </select>
                    @error('vehicle_type_id') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="flight_number">Flight number <span class="muted">(if airport)</span></label>
                    <input id="flight_number" name="flight_number" value="{{ old('flight_number') }}" placeholder="e.g. BA1234">
                    @error('flight_number') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="grid grid-2">
                <div class="field">
                    <label for="passengers">Passengers <span class="req">*</span></label>
                    <input id="passengers" type="number" name="passengers" min="1" max="16" value="{{ old('passengers', 1) }}" required>
                    @error('passengers') <div class="error">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="luggage">Luggage items</label>
                    <input id="luggage" type="number" name="luggage" min="0" max="30" value="{{ old('luggage', 0) }}">
                    @error('luggage') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="field">
                <label for="special_requests">Special requests</label>
                <textarea id="special_requests" name="special_requests" placeholder="Child seat, meet &amp; greet, name board…">{{ old('special_requests') }}</textarea>
                @error('special_requests') <div class="error">{{ $message }}</div> @enderror
            </div>
        </fieldset>

        <fieldset>
            <legend>Account &amp; Payment</legend>
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
                    <label for="quoted_price">Quoted price (£) <span id="quote-note" class="muted" style="font-weight:400"></span></label>
                    <input id="quoted_price" type="number" step="0.01" min="0" name="quoted_price" value="{{ old('quoted_price', $quote?->price) }}">
                    @error('quoted_price') <div class="error">{{ $message }}</div> @enderror
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Consent</legend>
            <div class="checkbox-row">
                <input id="privacy_consent" type="checkbox" name="privacy_consent" value="1" {{ old('privacy_consent') ? 'checked' : '' }} required>
                <label for="privacy_consent">
                    I confirm the passenger consents to their data being processed to fulfil this booking,
                    in line with the CET Privacy Notice (UK GDPR). <span class="req">*</span>
                </label>
            </div>
            @error('privacy_consent') <div class="error">{{ $message }}</div> @enderror
        </fieldset>

        <button type="submit" class="btn btn-primary">Confirm Booking</button>
        <a href="{{ route('bookings.index') }}" class="btn btn-ghost">Cancel</a>
    </form>

    <script>
        window.CET_PLACES_URL = "{{ route('places.autocomplete') }}";
        window.CET_ESTIMATE_URL = "{{ route('pricing.estimate') }}";
    </script>
    @verbatim
    <script>
        (function () {
            // ---- Google address autocomplete (via the server-side proxy) ----
            function attachPlaces(el) {
                if (!el || el.dataset.placesReady) return;
                el.dataset.placesReady = '1';
                var box = el.parentElement;
                box.style.position = 'relative';
                var menu = document.createElement('div');
                menu.className = 'places-menu';
                menu.style.cssText = 'position:absolute;left:0;right:0;z-index:50;background:var(--card,#fff);border:1px solid rgba(128,128,128,.35);border-radius:6px;margin-top:2px;max-height:240px;overflow:auto;display:none;box-shadow:0 6px 20px rgba(0,0,0,.12)';
                box.appendChild(menu);
                var timer = null, controller = null;

                function close() { menu.style.display = 'none'; menu.innerHTML = ''; }
                function render(items) {
                    menu.innerHTML = '';
                    if (!items.length) { close(); return; }
                    items.forEach(function (text) {
                        var opt = document.createElement('div');
                        opt.textContent = text;
                        opt.style.cssText = 'padding:9px 12px;cursor:pointer;font-size:14px;border-bottom:1px solid rgba(128,128,128,.12)';
                        opt.addEventListener('mousedown', function (e) { e.preventDefault(); el.value = text; close(); });
                        opt.addEventListener('mouseenter', function () { opt.style.background = 'rgba(251,186,42,.18)'; });
                        opt.addEventListener('mouseleave', function () { opt.style.background = ''; });
                        menu.appendChild(opt);
                    });
                    menu.style.display = 'block';
                }
                el.addEventListener('input', function () {
                    var q = el.value.trim();
                    clearTimeout(timer);
                    if (q.length < 3) { close(); return; }
                    timer = setTimeout(function () {
                        if (controller) controller.abort();
                        controller = new AbortController();
                        fetch(window.CET_PLACES_URL + '?q=' + encodeURIComponent(q), { signal: controller.signal, headers: { 'Accept': 'application/json' } })
                            .then(function (r) { return r.json(); })
                            .then(function (d) { render(d.suggestions || []); })
                            .catch(function () {});
                    }, 250);
                });
                el.addEventListener('blur', function () { setTimeout(close, 150); });
                el.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
            }
            document.querySelectorAll('[data-places]').forEach(attachPlaces);

            // Add / remove via-stop inputs.
            var addBtn = document.getElementById('add-stop');
            var wrap = document.getElementById('via-stops');
            if (addBtn) {
                addBtn.addEventListener('click', function () {
                    var input = document.createElement('input');
                    input.name = 'via_stops[]';
                    input.placeholder = 'Add a stop along the way';
                    input.setAttribute('data-places', '');
                    input.setAttribute('autocomplete', 'off');
                    input.style.marginBottom = '8px';
                    wrap.appendChild(input);
                    attachPlaces(input);
                });
            }

            // ---- Auto-quote: fixed airport price / free-roam distance ----
            var pickupEl = document.getElementById('pickup_address');
            var destEl = document.getElementById('destination_address');
            var vehEl = document.getElementById('vehicle_type_id');
            var priceEl = document.getElementById('quoted_price');
            var noteEl = document.getElementById('quote-note');
            var quoteTimer = null, quoteEdited = false;
            if (priceEl) priceEl.addEventListener('input', function () { quoteEdited = true; });

            function refreshQuote() {
                if (!pickupEl || !destEl || !vehEl || !priceEl) return;
                var pickup = pickupEl.value.trim(), dest = destEl.value.trim(), veh = vehEl.value;
                if (!pickup || !dest || !veh) return;
                clearTimeout(quoteTimer);
                quoteTimer = setTimeout(function () {
                    noteEl.textContent = '· calculating…';
                    var url = window.CET_ESTIMATE_URL + '?pickup=' + encodeURIComponent(pickup) +
                        '&destination=' + encodeURIComponent(dest) + '&vehicle_type_id=' + encodeURIComponent(veh);
                    fetch(url, { headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (d.price == null) { noteEl.textContent = '· ' + (d.basis || 'price on request'); return; }
                            noteEl.textContent = '· ' + d.basis;
                            // Only auto-fill if the operator hasn't typed their own price.
                            if (!quoteEdited || priceEl.value === '') { priceEl.value = Number(d.price).toFixed(2); quoteEdited = false; }
                        })
                        .catch(function () { noteEl.textContent = ''; });
                }, 400);
            }
            [pickupEl, destEl, vehEl].forEach(function (el) {
                if (el) { el.addEventListener('change', refreshQuote); el.addEventListener('blur', refreshQuote); }
            });

            // Show the return field only for return journeys.
            var journey = document.getElementById('journey_type');
            var returnField = document.getElementById('return_field');
            function toggleReturn() {
                returnField.style.display = journey.value === 'return' ? '' : 'none';
            }
            if (journey) { journey.addEventListener('change', toggleReturn); toggleReturn(); }
        })();
    </script>
    @endverbatim
@endsection
