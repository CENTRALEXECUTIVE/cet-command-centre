@extends('layouts.app')
@section('title', 'New Booking')

@section('content')
    <div class="form-hero">
        <div class="form-hero-glow"></div>
        <div class="fh-eyebrow">Sales · new job</div>
        <div class="fh-title">Smart Booking</div>
        <div class="fh-sub">Quote to confirmed booking in under 60 seconds.</div>
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

    <form method="POST" action="{{ route('bookings.store') }}" class="eto-form">
        @csrf
        @if($quote)<input type="hidden" name="quote_id" value="{{ $quote->id }}">@endif

        {{-- ───────────── Customer ───────────── --}}
        <div class="eto-section">
            <div class="head"><span class="ico">👤</span> Customer</div>
            <div class="body">
                <div class="grid grid-2">
                    <div class="field">
                        <label for="customer_name">Full name <span class="req">*</span></label>
                        <input id="customer_name" name="customer_name" value="{{ old('customer_name', $customer?->name) }}" required>
                        @error('customer_name') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="customer_phone">Mobile number</label>
                        <input id="customer_phone" name="customer_phone" value="{{ old('customer_phone', $customer?->phone) }}" placeholder="07…">
                        @error('customer_phone') <div class="error">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="field">
                    <label for="customer_email">Email address</label>
                    <input id="customer_email" type="email" name="customer_email" value="{{ old('customer_email', $customer?->email) }}">
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
                    </select>
                </div>

                <div class="field">
                    <label for="pickup_address">Pickup address <span class="req">*</span></label>
                    <div class="loc-row">
                        <span class="pin pickup">A</span>
                        <div class="grow">
                            <textarea id="pickup_address" name="pickup_address" data-places autocomplete="off" placeholder="Start typing an address…" required>{{ old('pickup_address', $quote?->pickup_address) }}</textarea>
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
                            <textarea id="destination_address" name="destination_address" data-places autocomplete="off" placeholder="Start typing an address…" required>{{ old('destination_address', $quote?->destination_address) }}</textarea>
                        </div>
                    </div>
                    @error('destination_address') <div class="error">{{ $message }}</div> @enderror
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
                            <option value="{{ $vt->id }}" @selected(old('vehicle_type_id', $quote?->vehicle_type_id ?? $customer?->preferred_vehicle_type_id)==$vt->id)>
                                {{ $vt->name }} (up to {{ $vt->passenger_capacity }})
                            </option>
                        @endforeach
                    </select>
                    @error('vehicle_type_id') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="stepper-field" style="border-top:1px solid var(--line)">
                    <span class="lbl">Passengers <span class="req">*</span><span class="sub">People travelling</span></span>
                    <div class="stepper" data-stepper>
                        <button type="button" data-dec>−</button>
                        <input id="passengers" type="number" name="passengers" min="1" max="16" value="{{ old('passengers', 1) }}" required>
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

                <div class="field" style="border-top:1px solid var(--line);padding-top:16px;margin-top:8px">
                    <label for="flight_number">Flight number <span class="muted">(if airport)</span></label>
                    <input id="flight_number" name="flight_number" value="{{ old('flight_number') }}" placeholder="e.g. BA1234">
                    @error('flight_number') <div class="error">{{ $message }}</div> @enderror
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
                        <input id="quoted_price" type="number" step="0.01" min="0" name="quoted_price" value="{{ old('quoted_price', $quote?->price) }}">
                        @error('quoted_price') <div class="error">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- ───────────── Advanced ───────────── --}}
        <div class="eto-section collapsible closed" data-collapsible>
            <div class="head"><span class="ico">⚙️</span> Advanced <span class="grow"></span> <span class="chev">▾</span></div>
            <div class="body">
                <div class="field" style="margin-bottom:0">
                    <label for="special_requests">Special requests</label>
                    <textarea id="special_requests" name="special_requests" placeholder="Child seat, meet &amp; greet, name board…">{{ old('special_requests') }}</textarea>
                    @error('special_requests') <div class="error">{{ $message }}</div> @enderror
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
                <button type="submit" class="btn btn-primary">Confirm Booking</button>
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
            // Show the return field only for return journeys.
            var journey = document.getElementById('journey_type');
            var returnField = document.getElementById('return_field');
            function toggleReturn() { returnField.style.display = journey.value === 'return' ? '' : 'none'; }
            if (journey) { journey.addEventListener('change', toggleReturn); toggleReturn(); }

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
