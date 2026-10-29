@extends('layouts.app')
@section('title', 'New Booking')

@section('content')
    @include('partials.smart-form-skin')

    <div class="smart-form sf-booking">
        <div class="smart-hero sf-compact">
            <div>
                <span class="eyebrow">Sales · New job</span>
                <h1>New booking</h1>
            </div>
            <span class="pill">⚡ Live price · checks meet &amp; greet, VAT and payment as you type</span>
        </div>

    @if($errors->any())
        <div class="alert alert-error" id="error-summary" tabindex="-1">
            <strong>Please fix the {{ $errors->count() }} highlighted {{ Str::plural('field', $errors->count()) }} below:</strong>
            <ul style="margin:6px 0 0;padding-left:20px">
                @foreach($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
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
                    <div class="field" style="position:relative">
                        <label for="customer_name">Full name <span class="req">*</span></label>
                        <input id="customer_name" name="customer_name" autocomplete="off" data-customer-search="{{ route('customers.search') }}" value="{{ old('customer_name', $pf['customer_name'] ?? $customer?->name) }}" required>
                        <div id="customer-match" role="listbox" style="display:none;position:absolute;z-index:50;left:0;right:0;top:100%;background:#fff;color:#111;border:1px solid #d9d9d9;border-radius:10px;box-shadow:0 12px 34px rgba(0,0,0,.28);max-height:260px;overflow-y:auto"></div>
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
                    <label class="checkbox-row" style="margin-top:8px;font-size:13px;font-weight:600">
                        <input type="checkbox" name="email_customer" value="1" {{ old('email_customer', '1') ? 'checked' : '' }}>
                        Email the customer a booking confirmation
                    </label>
                    <div class="hint">Sent when they have an email. Untick to create the booking quietly.</div>
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
                    <label for="pickup_postcode">Pickup postcode <span class="muted">— type it to list every address there</span></label>
                    <input id="pickup_postcode" name="pickup_postcode" data-postcode-fill="#pickup_address" autocomplete="off"
                           placeholder="e.g. S10 2PB" value="{{ old('pickup_postcode', $pf['pickup_postcode'] ?? '') }}" style="text-transform:uppercase">
                    <div class="hint">Type a postcode and pick the address from the list, or just type the address below (its postcode fills in automatically).</div>
                </div>
                <div class="field">
                    <label for="pickup_address">Pickup address <span class="req">*</span></label>
                    <div class="loc-row">
                        <span class="pin pickup">A</span>
                        <div class="grow">
                            <textarea id="pickup_address" name="pickup_address" data-places data-postcode-target="#pickup_postcode" autocomplete="off" placeholder="Start typing — or type the full address if it's not listed" required>{{ old('pickup_address', $pf['pickup_address'] ?? $quote?->pickup_address) }}</textarea>
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
                    <label for="destination_postcode">Destination postcode <span class="muted">— type it to list every address there</span></label>
                    <input id="destination_postcode" name="destination_postcode" data-postcode-fill="#destination_address" autocomplete="off"
                           placeholder="e.g. M90 1QX" value="{{ old('destination_postcode', $pf['destination_postcode'] ?? '') }}" style="text-transform:uppercase">
                </div>
                <div class="field">
                    <label for="destination_address">Destination address <span class="req">*</span></label>
                    <div class="loc-row">
                        <span class="pin drop">B</span>
                        <div class="grow">
                            <textarea id="destination_address" name="destination_address" data-places data-postcode-target="#destination_postcode" autocomplete="off" placeholder="Start typing — or type the full address if it's not listed" required>{{ old('destination_address', $pf['destination_address'] ?? $quote?->destination_address) }}</textarea>
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

                {{-- Return-leg addresses. A return defaults to the REVERSE of the outbound;
                     these are pre-filled with that reverse, so a simple return needs no
                     typing. Change them only when the return is from/to a different place. --}}
                <div id="return_locations" style="display:none;border:1px dashed var(--line,#dcdcdc);border-radius:10px;padding:12px;margin:0 0 4px">
                    <div style="font-weight:700;margin-bottom:8px">↩ Return journey
                        <span class="muted" style="font-weight:400">— defaults to the reverse; edit if the return is different</span></div>
                    <div class="field">
                        <label for="return_pickup_postcode">Return pickup postcode</label>
                        <input id="return_pickup_postcode" name="return_pickup_postcode" data-postcode-fill="#return_pickup_address" autocomplete="off"
                               placeholder="e.g. DE45 1SP" value="{{ old('return_pickup_postcode') }}" style="text-transform:uppercase">
                    </div>
                    <div class="field">
                        <label for="return_pickup_address">Return pickup address</label>
                        <div class="loc-row">
                            <span class="pin">A</span>
                            <div class="grow">
                                <textarea id="return_pickup_address" name="return_pickup_address" data-places data-postcode-target="#return_pickup_postcode" autocomplete="off" placeholder="Where the return journey starts">{{ old('return_pickup_address') }}</textarea>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <label for="return_destination_postcode">Return destination postcode</label>
                        <input id="return_destination_postcode" name="return_destination_postcode" data-postcode-fill="#return_destination_address" autocomplete="off"
                               placeholder="e.g. S1 2BP" value="{{ old('return_destination_postcode') }}" style="text-transform:uppercase">
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label for="return_destination_address">Return destination address</label>
                        <div class="loc-row">
                            <span class="pin drop">B</span>
                            <div class="grow">
                                <textarea id="return_destination_address" name="return_destination_address" data-places data-postcode-target="#return_destination_postcode" autocomplete="off" placeholder="Where the return journey ends">{{ old('return_destination_address') }}</textarea>
                            </div>
                        </div>
                    </div>
                    {{-- The return leg's own vehicle + price (ETO-style): defaults to the
                         outbound vehicle and the live quote for the return route; change
                         either, or type your own return price. --}}
                    <div class="grid grid-2" style="margin-top:10px">
                        <div class="field" style="margin-bottom:0">
                            <label for="return_vehicle_type_id">Return vehicle</label>
                            <select id="return_vehicle_type_id" name="return_vehicle_type_id">
                                <option value="">Same as outbound</option>
                                @foreach($vehicleTypes as $vt)
                                    <option value="{{ $vt->id }}" @selected((string) old('return_vehicle_type_id') === (string) $vt->id)>{{ $vt->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field" style="margin-bottom:0">
                            <label for="return_leg_price">Return price (£)</label>
                            <input id="return_leg_price" type="number" step="0.01" min="0" inputmode="decimal" value="{{ old('leg_price_return') }}" placeholder="live quote">
                            <div class="hint" id="return-price-hint" style="margin-top:4px"></div>
                        </div>
                    </div>
                    <div class="field" style="margin:10px 0 0">
                        <label for="return_flight_number">Return flight number <span class="muted">(only if the return collects from an airport)</span></label>
                        <input id="return_flight_number" name="return_flight_number" value="{{ old('return_flight_number') }}" placeholder="e.g. EZY1234" style="text-transform:uppercase">
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
                @php
                    $saloonSvg = '<svg viewBox="0 0 120 60" xmlns="http://www.w3.org/2000/svg"><path fill="#0b0b0c" d="M8 44c-3 0-5-2-5-5v-4c0-2 1-3 3-4l10-3 12-11c3-3 7-4 11-4h28c5 0 9 2 13 5l10 9 14 3c4 1 6 3 6 7v3c0 3-2 5-5 5h-6a10 10 0 01-20 0H34a10 10 0 01-20 0H8zm16-6a6 6 0 100 12 6 6 0 000-12zm64 0a6 6 0 100 12 6 6 0 000-12zM40 20l-9 9h26V20H40zm22 0v9h24l-8-7c-2-1-4-2-7-2H62z"/></svg>';
                    $vanSvg = '<svg viewBox="0 0 120 60" xmlns="http://www.w3.org/2000/svg"><path fill="#0b0b0c" d="M6 46c-2 0-4-2-4-4V22c0-4 3-7 7-7h58c4 0 8 2 11 5l16 15 10 3c4 1 7 4 7 8v0c0 2-2 4-4 4h-7a10 10 0 01-20 0H33a10 10 0 01-20 0H6zm17-7a6 6 0 100 13 6 6 0 000-13zm64 0a6 6 0 100 13 6 6 0 000-13zM14 22v11h20V22H14zm28 0v11h20V22H42zm28 1v10h20l-12-8c-2-1-5-2-8-2z"/></svg>';
                    $selVeh = old('vehicle_type_id', $pf['vehicle_type_id'] ?? $quote?->vehicle_type_id ?? $customer?->preferred_vehicle_type_id);
                @endphp
                <div class="field">
                    <label for="vehicle_type_id">Vehicle type <span class="req">*</span> <span id="quote-note" class="muted" style="font-weight:400"></span></label>
                    {{-- The submitted value + the JS source of capacities. Visually hidden —
                         the picture cards below drive it (like the customer booking page). --}}
                    <select id="vehicle_type_id" name="vehicle_type_id" required class="veh-select-hidden" tabindex="-1" aria-hidden="true">
                        <option value="">— Select —</option>
                        @foreach($vehicleTypes as $vt)
                            <option value="{{ $vt->id }}"
                                data-cap-pax="{{ $vt->passenger_capacity }}"
                                data-cap-lug="{{ $vt->luggage_capacity }}"
                                data-cap-hand="{{ $vt->handLuggageCapacity() }}"
                                @selected((string) $selVeh === (string) $vt->id)>
                                {{ $vt->name }} (up to {{ $vt->passenger_capacity }})
                            </option>
                        @endforeach
                    </select>
                    @error('vehicle_type_id') <div class="error">{{ $message }}</div> @enderror

                    {{-- Picture cards with live prices — tap one to pick it + set the fare.
                         Fast, visual quoting on the phone. --}}
                    <div id="veh-cards" class="veh-cards">
                        @foreach($vehicleTypes as $vt)
                            <button type="button" class="veh-card {{ (string) $selVeh === (string) $vt->id ? 'sel' : '' }}"
                                    data-id="{{ $vt->id }}"
                                    data-cap-pax="{{ $vt->passenger_capacity }}"
                                    data-cap-lug="{{ $vt->luggage_capacity }}"
                                    data-cap-hand="{{ $vt->handLuggageCapacity() }}">
                                <span class="veh-photo">
                                    @if($vt->photoUrl())
                                        <img src="{{ $vt->photoUrl() }}" alt="{{ $vt->name }}" loading="lazy">
                                    @else
                                        {!! $vt->isLargeVehicle() ? $vanSvg : $saloonSvg !!}
                                    @endif
                                </span>
                                <span class="veh-info">
                                    <span class="veh-name">{{ $vt->name }}</span>
                                    @if($vt->tagline())<span class="veh-tag">{{ $vt->tagline() }}</span>@endif
                                    <span class="veh-cap">👤 {{ $vt->passenger_capacity }} &nbsp;·&nbsp; 🧳 {{ $vt->luggage_capacity }} &nbsp;·&nbsp; 👜 {{ $vt->handLuggageCapacity() }}</span>
                                </span>
                                <span class="veh-price"><span class="amt" data-price>—</span></span>
                                <span class="veh-tick">✓</span>
                                <span class="veh-warn">Too small for this party</span>
                            </button>
                        @endforeach
                    </div>
                    <div id="fare-breakdown" class="fare-breakdown" hidden></div>
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
                    <span class="lbl">Cabin bags<span class="sub">Hand luggage / small bags</span></span>
                    <div class="stepper" data-stepper>
                        <button type="button" data-dec>−</button>
                        <input id="hand_luggage" type="number" name="hand_luggage" min="0" max="30" value="{{ old('hand_luggage', 0) }}">
                        <button type="button" data-inc>+</button>
                    </div>
                </div>
                @error('hand_luggage') <div class="error">{{ $message }}</div> @enderror

                <div class="grid grid-2" id="flight-fields" style="border-top:1px solid var(--line);padding-top:16px;margin-top:8px">
                    <div class="field" style="margin-bottom:0">
                        <label for="flight_number"><span id="flight-label-text">Flight number</span> <span class="muted" id="flight-hint">(if airport)</span></label>
                        <input id="flight_number" name="flight_number" value="{{ old('flight_number') }}" placeholder="e.g. BA1234" style="text-transform:uppercase">
                        @error('flight_number') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field" id="flight-landing-field" style="margin-bottom:0" hidden>
                        <label for="flight_landing_at"><span id="flight-time-label">Flight landing time</span> <span class="muted">(so we can track it)</span></label>
                        <input id="flight_landing_at" type="datetime-local" name="flight_landing_at" value="{{ old('flight_landing_at') }}">
                    </div>
                </div>
            </div>
        </div>

        {{-- ───────────── Extras ───────────── --}}
        @php $extrasOpen = old('meet_greet') || (int) old('child_seats', 0) || (int) old('booster_seats', 0) || (int) old('infant_seats', 0) || old('ribbon') || old('wheelchair'); @endphp
        <div class="eto-section">
            <div class="head" style="cursor:pointer;display:flex;justify-content:space-between;align-items:center"
                 onclick="var b=this.nextElementSibling;var show=b.hidden;b.hidden=!show;this.querySelector('.fold-caret').textContent=show?'▾':'▸'">
                <span><span class="ico">✨</span> Extras <span class="muted" style="font-weight:400;font-size:12px">— meet &amp; greet, child seats, ribbons</span> <span class="sf-chip" id="sf-mg-chip" hidden>Meet &amp; greet on</span></span>
                <span class="fold-caret" style="color:#999">{{ $extrasOpen ? '▾' : '▸' }}</span>
            </div>
            <div class="body" {{ $extrasOpen ? '' : 'hidden' }}>
                <div class="checkbox-row" style="margin-bottom:12px">
                    <input id="meet_greet" type="checkbox" name="meet_greet" value="1" {{ old('meet_greet') ? 'checked' : '' }}>
                    <label for="meet_greet">Meet &amp; greet <span class="muted">(driver waits in arrivals with a name board)</span></label>
                </div>
                {{-- ETO-style meeting details — the name the driver holds up and where
                     to meet. Revealed when meet & greet is ticked. --}}
                <div id="meeting-details" class="grid grid-2" style="margin-bottom:12px" {{ old('meet_greet') ? '' : 'hidden' }}>
                    <div class="field" style="margin-bottom:0">
                        <label for="meeting_board">Name board <span class="muted">(held up in arrivals)</span></label>
                        <input id="meeting_board" name="meeting_board" value="{{ old('meeting_board') }}" placeholder="e.g. Mr J Smith" maxlength="160">
                        @error('meeting_board') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label for="meeting_point">Meeting point <span class="muted">(where to wait)</span></label>
                        <input id="meeting_point" name="meeting_point" value="{{ old('meeting_point') }}" placeholder="e.g. Costa, arrivals hall" maxlength="300">
                        @error('meeting_point') <div class="error">{{ $message }}</div> @enderror
                    </div>
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

                {{-- VAT invoice: for a business that wants a VAT receipt (e.g. a
                     phone booking). Routes to Central Executive Transfers Ltd via
                     Square and adds 20% VAT; otherwise the booking is billed by
                     PVT LTD via Stripe. The company details go on the invoice. --}}
                <div class="field" style="margin-top:4px">
                    <label class="checkbox-row" style="font-weight:600">
                        <input type="checkbox" id="vat_invoice" name="vat_invoice" value="1" {{ old('vat_invoice') ? 'checked' : '' }}
                               onchange="document.getElementById('vat-details').hidden = !this.checked">
                        VAT invoice — customer wants a VAT receipt
                    </label>
                    <div class="hint">On = billed by <strong>Central Executive Transfers Ltd</strong> (+20% VAT, card via Square). Off = <strong>PVT LTD</strong> (no VAT, card via Stripe).</div>
                </div>
                <div id="vat-details" class="grid grid-2" {{ old('vat_invoice') ? '' : 'hidden' }} style="margin-top:4px">
                    <div class="field">
                        <label for="vat_company">Company name <span class="muted">(bill to)</span></label>
                        <input id="vat_company" name="vat_company" value="{{ old('vat_company') }}" placeholder="Who the invoice is addressed to">
                    </div>
                    <div class="field">
                        <label for="vat_number">Their VAT number <span class="muted">(optional)</span></label>
                        <input id="vat_number" name="vat_number" value="{{ old('vat_number') }}" placeholder="e.g. GB123456789">
                    </div>
                    <div class="field" style="grid-column:1/-1">
                        <label for="vat_address">Billing address <span class="muted">(optional)</span></label>
                        <input id="vat_address" name="vat_address" value="{{ old('vat_address') }}" placeholder="Company address for the invoice">
                    </div>
                </div>

                <div class="grid grid-2">
                    <div class="field">
                        <label for="payment_method">Payment method <span class="req">*</span></label>
                        <select id="payment_method" name="payment_method" required>
                            <option value="card" @selected(old('payment_method','card')==='card')>Card (payment link)</option>
                            <option value="cash" @selected(old('payment_method')==='cash')>Cash</option>
                            <option value="account" @selected(old('payment_method')==='account')>Account (invoiced)</option>
                        </select>
                        @error('payment_method') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="quoted_price">Quoted price (£)</label>
                        <input id="quoted_price" type="number" step="0.01" min="0" name="quoted_price" value="{{ old('quoted_price', $pf['quoted_price'] ?? $quote?->price) }}">
                        {{-- Per-leg fares from the live quote, so a return stores its own price on each leg. --}}
                        <input type="hidden" id="leg_price_out" name="leg_price_out" value="{{ old('leg_price_out') }}">
                        <input type="hidden" id="leg_price_return" name="leg_price_return" value="{{ old('leg_price_return') }}">
                        <div id="price-nudge" class="price-nudge" hidden></div>
                        @error('quoted_price') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="voucher">Discount code</label>
                        <input id="voucher" name="voucher" maxlength="40" placeholder="e.g. WELCOME10" value="{{ old('voucher') }}" style="text-transform:uppercase">
                        <p class="hint" style="margin:4px 0 0">A valid code comes off the customer price. Driver pay is left blank for you to set by hand on a discounted job.</p>
                        @error('voucher') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="deposit_amount">Deposit taken (£) <span class="muted">if any</span></label>
                        <input id="deposit_amount" type="number" step="0.01" min="0" name="deposit_amount" value="{{ old('deposit_amount') }}" placeholder="e.g. 15">
                        <label style="display:flex;align-items:center;gap:8px;margin-top:8px;font-weight:500">
                            <input type="checkbox" name="deposit_paid" value="1" @checked(old('deposit_paid')) style="width:auto">
                            Deposit has been paid
                        </label>
                        <p class="hint" style="margin:4px 0 0">The balance (what a cash driver collects) is the fare minus this deposit.</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- ───────────── Advanced ───────────── --}}
        <div class="eto-section collapsible closed" data-collapsible>
            <div class="head"><span class="ico">⚙️</span> Advanced <span class="grow"></span> <span class="chev">▾</span></div>
            <div class="body">
                <div class="field">
                    <label for="booking_source">How did this booking come in? <span class="muted">(for the office)</span></label>
                    <select id="booking_source" name="booking_source">
                        <option value="">— Not recorded —</option>
                        @foreach(['phone' => 'By phone', 'whatsapp' => 'WhatsApp', 'email' => 'Email', 'website' => 'Website', 'repeat' => 'Repeat / direct', 'other' => 'Other'] as $val => $label)
                            <option value="{{ $val }}" @selected(old('booking_source')===$val)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('booking_source') <div class="error">{{ $message }}</div> @enderror
                </div>
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
                    {{-- Office attestation: the operator confirms consent was taken when the
                         booking was made (phone/WhatsApp/email), so it's pre-ticked and the
                         office just leaves it on. Untick only if consent wasn't obtained. --}}
                    <input id="privacy_consent" type="checkbox" name="privacy_consent" value="1" {{ old('privacy_consent', '1') ? 'checked' : '' }} required>
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
            <div class="total-main">
                {{-- Live summary of what's being booked (desktop side panel) — read-only,
                     filled from the fields above so the office can check before confirming. --}}
                <dl class="sf-summary" id="sf-summary" aria-live="polite"></dl>
                <div class="total-label">Total</div>
                <div class="total-amount" id="total-amount">£{{ number_format(old('quoted_price', $quote?->price ?? 0), 2) }}<span class="basis" id="total-basis">enter journey for a live price</span></div>
                {{-- Itemised breakdown right by the figure so the office sees what
                     makes up the total (base + each extra), not just a number. --}}
                <div class="total-breakdown" id="total-breakdown" hidden></div>
                {{-- VAT invoice ticked → VAT goes ON TOP of the price above, so show
                     exactly what the customer will be charged (net + VAT = total). --}}
                <div class="total-breakdown" id="total-vat" data-vat-rate="{{ app(\App\Services\Payments\VatService::class)->rate() ?: 0.20 }}" hidden></div>
            </div>
            <div class="actions">
                <a href="{{ route('bookings.index') }}" class="btn btn-ghost">Cancel</a>
                {{-- See the customer's confirmation email BEFORE confirming — opens in a new
                     tab from the details entered so far. Sends nothing; nothing is saved. --}}
                <button type="submit" class="btn btn-light"
                        formaction="{{ route('bookings.confirmation-email.preview-new') }}"
                        formmethod="post" formtarget="_blank" formnovalidate
                        title="Preview the customer's email before confirming — nothing is sent">👁 Preview email</button>
                <button type="submit" class="btn btn-primary">Confirm booking →</button>
            </div>
        </div>
    </form>
    </div>{{-- /.smart-form --}}

    <style>
        /* The native select is driven by the picture cards — keep it in the DOM
           (it submits the value and the JS reads capacities from it) but hidden. */
        .smart-form .veh-select-hidden { position: absolute; width: 1px; height: 1px;
            padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); border: 0; }

        /* Picture vehicle cards, like the customer booking page. */
        .smart-form .veh-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 10px; margin-top: 10px; }
        .smart-form .veh-card { position: relative; display: grid;
            grid-template-columns: 76px 1fr auto; align-items: center; gap: 12px;
            text-align: left; background: #fff; border: 1.5px solid var(--sf-line, #e6e6e6);
            border-radius: 14px; padding: 10px 12px; cursor: pointer; width: 100%;
            transition: border-color .12s, box-shadow .12s, transform .12s; }
        .smart-form .veh-card:hover { border-color: var(--sf-gold, #FBBA2A); transform: translateY(-1px); }
        .smart-form .veh-card.sel { border-color: var(--sf-gold, #FBBA2A);
            box-shadow: 0 0 0 3px rgba(251,186,42,.2); }
        .smart-form .veh-photo { width: 76px; height: 52px; display: grid; place-items: center;
            background: #f4f4f2; border-radius: 10px; overflow: hidden; }
        .smart-form .veh-photo img { width: 100%; height: 100%; object-fit: cover; }
        .smart-form .veh-photo svg { width: 60px; height: 30px; opacity: .85; }
        .smart-form .veh-info { min-width: 0; display: flex; flex-direction: column; gap: 2px; }
        .smart-form .veh-name { font-weight: 700; font-size: 14px; color: #1a1a1a; }
        .smart-form .veh-tag { font-size: 11.5px; color: var(--sf-muted, #777); }
        .smart-form .veh-cap { font-size: 11.5px; color: var(--sf-muted, #777); white-space: nowrap; }
        .smart-form .veh-price { font-weight: 800; font-size: 16px; color: #111; text-align: right;
            white-space: nowrap; align-self: center; }
        .smart-form .veh-price .amt.poa { font-size: 12px; font-weight: 600; color: var(--sf-muted, #777); }
        .smart-form .veh-tick { position: absolute; top: 7px; left: 7px; width: 20px; height: 20px;
            border-radius: 50%; background: var(--sf-gold, #FBBA2A); color: #111; font-size: 12px;
            display: none; place-items: center; font-weight: 800; box-shadow: 0 1px 4px rgba(0,0,0,.3); }
        .smart-form .veh-card.sel .veh-tick { display: grid; }
        .smart-form .veh-warn { display: none; grid-column: 1 / -1; font-size: 11.5px; color: #c02626;
            font-weight: 600; margin-top: 2px; }
        /* A vehicle too small for the passengers/luggage entered — greyed, like the
           customer booking page, so the agent sees what actually fits. */
        .smart-form .veh-card.unfit { opacity: .5; }
        .smart-form .veh-card.unfit .veh-warn { display: block; }
        @media (max-width: 560px) {
            .smart-form .veh-cards { grid-template-columns: 1fr; }
        }
        html[data-theme="dark"] .smart-form .veh-card { background: #151b2c; border-color: rgba(255,255,255,.12); }
        html[data-theme="dark"] .smart-form .veh-name { color: #f2f5fa; }
        html[data-theme="dark"] .smart-form .veh-price { color: #f2f5fa; }
        html[data-theme="dark"] .smart-form .veh-photo { background: #0f1420; }

        /* Price-drift nudge: the typed price differs from the live full quote. */
        .price-nudge { margin-top: 6px; font-size: 13px; color: #8a6d00; background: #fff0cc;
            border: 1px solid #FBBA2A; border-radius: 6px; padding: 6px 10px; }
        .price-nudge .link { background: none; border: 0; padding: 0; color: #1d4ed8;
            font: inherit; font-weight: 600; text-decoration: underline; cursor: pointer; }
        /* Itemised fare breakdown for the chosen vehicle. */
        .fare-breakdown { margin-top: 8px; font-size: 13px; border: 1px solid #e6e6e6;
            border-radius: 8px; padding: 8px 12px; background: #fafafa; max-width: 320px; }
        .fare-breakdown .row { display: flex; justify-content: space-between; padding: 2px 0; }
        .fare-breakdown .row.total { border-top: 1px solid #e0e0e0; margin-top: 4px;
            padding-top: 5px; font-weight: 700; }
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
    <script src="{{ asset('js/cet-forms.js') }}?v=36"></script>
    @verbatim
    <script>
        (function () {
            // On a validation failure, jump to the summary and the first bad field
            // so the operator can see exactly what's missing (no more "nothing's wrong").
            var summary = document.getElementById('error-summary');
            if (summary) {
                summary.scrollIntoView({ behavior: 'smooth', block: 'center' });
                var firstInvalid = document.querySelector('[aria-invalid="true"]');
                try { (firstInvalid || summary).focus({ preventScroll: true }); } catch (e) {}
            }
        })();
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
                    // A stop added after load must also re-quote the price strip
                    // (the strip only wired the stops present at load otherwise).
                    input.addEventListener('change', function () { if (window.CETrequote) window.CETrequote(); });
                    input.addEventListener('blur', function () { if (window.CETrequote) window.CETrequote(); });
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
            var returnLocations = document.getElementById('return_locations');

            // The return addresses default to the REVERSE of the outbound. We keep them
            // in sync with the outbound ONLY until the office edits them by hand — after
            // that their entry wins (a genuinely different return is never clobbered).
            var retPickup = document.getElementById('return_pickup_address');
            var retPickupPc = document.getElementById('return_pickup_postcode');
            var retDest = document.getElementById('return_destination_address');
            var retDestPc = document.getElementById('return_destination_postcode');
            var outPickup = document.getElementById('pickup_address');
            var outPickupPc = document.getElementById('pickup_postcode');
            var outDest = document.getElementById('destination_address');
            var outDestPc = document.getElementById('destination_postcode');
            var returnEdited = false;
            // A Google/postcode pick arrives as 'change' (not 'input') — count it as
            // the office's own entry too, so it's never overwritten afterwards.
            [retPickup, retPickupPc, retDest, retDestPc].forEach(function (el) {
                if (el) {
                    el.addEventListener('input', function () { returnEdited = true; });
                    el.addEventListener('change', function () { returnEdited = true; });
                }
            });
            function prefillReturnFromOutbound() {
                if (returnEdited) return; // never overwrite the office's own entry
                if (retPickup && outDest) retPickup.value = outDest.value;
                if (retPickupPc && outDestPc) retPickupPc.value = outDestPc.value;
                if (retDest && outPickup) retDest.value = outPickup.value;
                if (retDestPc && outPickupPc) retDestPc.value = outPickupPc.value;
            }
            // Sync on 'change' as well as typing: picking a Google suggestion fires
            // 'change', so the return now gets the FULL picked address, not the
            // half-typed text from the last keystroke.
            [outPickup, outPickupPc, outDest, outDestPc].forEach(function (el) {
                if (el) ['input', 'change'].forEach(function (evt) {
                    el.addEventListener(evt, function () {
                        if (journey && journey.value === 'return') prefillReturnFromOutbound();
                    });
                });
            });

            function toggleJourney() {
                var isReturn = journey.value === 'return';
                var isHourly = journey.value === 'hourly';
                if (returnField) returnField.style.display = isReturn ? '' : 'none';
                if (returnLocations) {
                    returnLocations.style.display = isReturn ? '' : 'none';
                    // Seed the reverse the first time it's shown (unless old() restored
                    // values after a validation error, or the office already typed some).
                    if (isReturn && !returnEdited
                        && retPickup && !retPickup.value && retDest && !retDest.value) {
                        prefillReturnFromOutbound();
                    }
                }
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
                var flightLabel = document.getElementById('flight-label-text');
                var flightTimeLabel = document.getElementById('flight-time-label');
                var landingField = document.getElementById('flight-landing-field');
                var meet = document.getElementById('meet_greet');
                var meetingDetails = document.getElementById('meeting-details');
                if (!flightIn) return;
                var mgAuto = false; // only auto-manage the tick we set ourselves

                // The meet & greet name board / meeting point only matter when meet
                // & greet is on — reveal them with the tick.
                function syncMeetingDetails() {
                    if (meetingDetails && meet) meetingDetails.hidden = !meet.checked;
                }

                // Same rules as App\Support\AirportMatcher and the customer widget: never a
                // bare town ("Doncaster", "Luton"), a road named after an airport ("Gatwick
                // Road") or an office ("Terminal House") — those auto-ticked meet & greet.
                var AIR_STREET = '(?:road|rd|street|lane|avenue|drive|close|crescent|grove|terrace|retail\\s+park|business\\s+park|shopping\\s+park|industrial\\s+estate|trading\\s+estate)\\b';
                var AIR_NAME = new RegExp('(?:^|[^a-z0-9])(?:airport|heathrow|gatwick|london\\s+stansted|stansted\\s+airport|london\\s+luton|luton\\s+airport|manchester\\s+airport|east\\s+midlands\\s+airport|nottingham\\s+east\\s+midlands|leeds\\s+bradford|doncaster\\s+sheffield|robin\\s+hood\\s+airport|liverpool\\s+john\\s+lennon)(?![a-z0-9])(?!\\s+' + AIR_STREET + ')');
                function airportIn(v) {
                    var s = String(v || '').toLowerCase();
                    if (AIR_NAME.test(s)) return true;
                    if (/\((man|lhr|lgw|stn|ema|lba|bhx|lpl|huy|ltn|dsa|ncl|edi|gla|brs)\)/.test(s)) return true;
                    if (/\bm90\b|\btw6\b|\bde74\s*2sa\b|\blu2\s*9ly\b|\bb26\s*3qj\b|\bls19\s*7tu\b|\bl24\s*1yd\b|\bcm24\s*1qw\b|\brh6\s*0(np|pj)\b|\bdn39\s*6yh\b/.test(s)) return true;
                    // Private-jet handlers / FBOs / hangars at an airport ("Advantage Flight
                    // Support, DE74 2SA") — only inside that airport's own area.
                    if (/(?:^|[^a-z0-9])(?:flight\s+support|aviation|fbo|jet\s+cent(?:re|er)|private\s+jets?|business\s+jets?|executive\s+jets?|general\s+aviation|hangar|airside)(?![a-z0-9])/.test(s)
                        && (/\b(?:de74|lu2|rh6|m90|m22|b26|b40|ls19|l24|cm24|tw6|dn9|dn39)\s*\d[a-z]{2}\b/.test(s)
                            || /\b(?:castle donington|kegworth|east midlands|gatwick|horley|ringway|elmdon|marston green|yeadon|speke|stansted|heathrow|finningley|kirmington)\b/.test(s))) return true;
                    if (/\bt[1-5]\b/.test(s)) return true;
                    var t = s.replace(/\b(bus|coach|ferry|freight|cargo|container|cruise|rail|oil|lorry|truck)\s+terminal\b/g, ' ')
                        .replace(/\bterminal\s+(house|buildings?|chambers|court|works|road|rd|street|lane|avenue|drive|close|place|business\s+park|industrial\s+estate|trading\s+estate)\b/g, ' ');
                    return /\bterminal\b/.test(t);
                }
                function looksAirport(v) { return airportIn(v); }
                // Direction is decided by WHICH END is the airport. The Airport dropdown
                // only flags that a job involves an airport (for driver rotation) — it does
                // NOT mean the PICKUP is the airport. Meet & greet is an ARRIVALS thing, so
                // it must key off the pickup address only, never the dropdown or the dropoff.
                function pickupIsAirport() { return looksAirport(pickup && pickup.value); }
                function isAnyAirport() { return pickupIsAirport() || looksAirport(dest && dest.value) || (airportSel && airportSel.value !== ''); }

                function apply() {
                    var any = isAnyAirport();
                    var arrival = pickupIsAirport();
                    if (note) note.hidden = !any;
                    if (landingField) landingField.hidden = !any;
                    // RECOMMENDED on an airport job, never forced — the office (admin)
                    // often doesn't have the flight to hand and can add it later.
                    flightIn.required = false;
                    if (flightHint) flightHint.textContent = any ? '— recommended for airport jobs' : '(if airport)';
                    // Arrival vs departure: picking up FROM an airport tracks the arriving
                    // flight; dropping off TO an airport is a departure flight.
                    if (flightLabel) flightLabel.textContent = !any
                        ? 'Flight number'
                        : (arrival ? 'Arrival flight number' : 'Departure flight number');
                    if (flightTimeLabel) flightTimeLabel.textContent = arrival ? 'Flight landing time' : 'Flight departure time';
                    if (noteText) noteText.textContent = arrival
                        ? 'Add the flight number and landing time below so the office can track the flight — meet & greet has been ticked for the arrivals pickup.'
                        : 'Add the flight number and landing time below so the office can track the flight.';
                    // Pre-tick meet & greet for an ARRIVAL (airport pickup); untick the
                    // auto-tick if it stops being an arrival, but never fight a manual tick.
                    if (meet) {
                        if (arrival && !meet.checked) { meet.checked = true; mgAuto = true; }
                        else if (!arrival && mgAuto && meet.checked) { meet.checked = false; mgAuto = false; }
                    }
                    syncMeetingDetails();
                }
                if (meet) meet.addEventListener('change', function () { mgAuto = false; syncMeetingDetails(); });

                // Collecting FROM the airport: the pickup time IS the flight landing
                // time. Keep the two in step both ways (the flight is tracked, so the
                // driver adjusts to delays). Departures and other jobs are untouched.
                var pickupAtEl = document.getElementById('pickup_at');
                var landingEl = document.getElementById('flight_landing_at');
                function isAirportArrival() {
                    // The outbound leg's pickup (one-way or return): its landing time is the outbound flight.
                    return pickupIsAirport();
                }
                if (pickupAtEl && landingEl) {
                    landingEl.addEventListener('change', function () {
                        if (isAirportArrival() && landingEl.value && pickupAtEl.value !== landingEl.value) {
                            pickupAtEl.value = landingEl.value;
                            pickupAtEl.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    });
                    pickupAtEl.addEventListener('change', function () {
                        if (isAirportArrival() && pickupAtEl.value && landingEl.value !== pickupAtEl.value) {
                            landingEl.value = pickupAtEl.value;
                        }
                    });
                }
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
                var strip = document.getElementById('veh-cards');
                var pickup = document.getElementById('pickup_address');
                var dest = document.getElementById('destination_address');
                var vehSel = document.getElementById('vehicle_type_id');
                var priceEl = document.getElementById('quoted_price');
                var url = window.CET_STRIP_URL || window.CET_PRICES_URL;
                if (!strip || !pickup || !dest || !vehSel || !url) return;
                var cards = Array.prototype.slice.call(strip.querySelectorAll('.veh-card'));
                var tokenEl = document.querySelector('meta[name="csrf-token"]');
                var token = tokenEl ? tokenEl.getAttribute('content') : '';
                var whenEl = document.getElementById('pickup_at');
                var journeyEl = document.getElementById('journey_type');
                var returnEl = document.getElementById('return_pickup_at');
                var retPickupEl = document.getElementById('return_pickup_address');
                var retDestEl = document.getElementById('return_destination_address');
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

                // Grey out the cards for vehicles too small for the party entered.
                function applyFit() {
                    var pax = num(paxEl), suit = num(suitEl), hand = num(handEl);
                    cards.forEach(function (card) {
                        var c = caps[card.dataset.id];
                        var fits = !c || (pax <= c.pax && suit <= c.lug && hand <= c.hand);
                        card.classList.toggle('unfit', !fits);
                        card.title = fits ? '' : 'Too small for ' + pax + ' passengers / ' + (suit + hand) + ' bags';
                    });
                }

                // Reflect the chosen vehicle on the cards.
                function selectCard(id) {
                    cards.forEach(function (card) {
                        card.classList.toggle('sel', String(card.dataset.id) === String(id));
                    });
                }

                // Clicking a card picks that vehicle and drops its live fare into the
                // price box (same as tapping a price chip did before).
                cards.forEach(function (card) {
                    card.addEventListener('click', function () {
                        var id = card.dataset.id;
                        vehSel.value = String(id);
                        vehSel.dispatchEvent(new Event('change'));
                        var p = card.dataset.price;
                        if (priceEl && p !== '' && p != null) {
                            priceEl.value = Number(p).toFixed(2);
                            autoPrice = Number(p); // keep tracking the live quote for this vehicle
                            priceEl.dispatchEvent(new Event('input'));
                        }
                        selectCard(id);
                    });
                });

                var priceNudge = document.getElementById('price-nudge');
                var breakdownEl = document.getElementById('fare-breakdown');
                var totalBreakdownEl = document.getElementById('total-breakdown');
                var lastOptions = [];
                // The last live price we auto-wrote into the price box. While the box
                // still matches it (or is empty), we keep it in sync with the live
                // quote so ticking an add-on updates the total automatically. Once the
                // operator types their own figure it stops tracking (the nudge takes
                // over) so a negotiated price is never clobbered.
                var autoPrice = null;

                // Itemised fare for the CHOSEN vehicle: base + surcharge + each extra
                // = total, so the office sees what makes up the price, not just a figure.
                // Painted both under the vehicle cards and beside the big Total.
                // Capture the per-leg fares from the chosen vehicle's breakdown, so a
                // return stores its own price on each leg (the office never types them).
                var legOutEl = document.getElementById('leg_price_out');
                var legRetEl = document.getElementById('leg_price_return');
                // The RETURN leg's own vehicle + price (ETO-style). The return price box
                // follows the live quote for the return route + return vehicle until the
                // office types over it; then their figure wins.
                var retVehSel = document.getElementById('return_vehicle_type_id');
                var retPriceEl = document.getElementById('return_leg_price');
                var retHint = document.getElementById('return-price-hint');
                var autoReturn = null;
                function optFor(id) { return lastOptions.filter(function (o) { return String(o.id) === String(id); })[0]; }
                function legAmount(opt, label) {
                    var a = null;
                    (opt && opt.breakdown ? opt.breakdown : []).forEach(function (l) { if (l.label === label) a = Number(l.amount); });
                    return a;
                }
                function isReturnTrip() { return !!(journeyEl && journeyEl.value === 'return'); }
                function liveReturn() {
                    var o = optFor((retVehSel && retVehSel.value) || vehSel.value);
                    return o && !o.poa ? legAmount(o, 'Return leg') : null;
                }
                function syncReturnPrice() {
                    if (!retPriceEl) return;
                    var live = liveReturn();
                    var typed = parseFloat(retPriceEl.value);
                    var tracking = isNaN(typed) || (autoReturn != null && Math.abs(typed - autoReturn) < 0.01);
                    if (tracking && live != null) { retPriceEl.value = live.toFixed(2); autoReturn = live; }
                    if (retHint) {
                        retHint.textContent = live == null ? ''
                            : (tracking ? 'Live quote — type to change' : 'Live quote £' + live.toFixed(2) + ' · using your price');
                    }
                }
                // [outbound, return] on a return trip — the return is the office's
                // figure (or the live quote for the return vehicle). Null otherwise.
                function returnLegs() {
                    if (!isReturnTrip()) return null;
                    var o = legAmount(optFor(vehSel.value), 'Outbound leg');
                    var r = retPriceEl ? parseFloat(retPriceEl.value) : NaN;
                    if (isNaN(r)) r = liveReturn();
                    return (o != null && r != null) ? [o, r] : null;
                }
                function liveTotal(opt) {
                    var legs = returnLegs();
                    if (legs) return Math.round((legs[0] + legs[1]) * 100) / 100;
                    return opt && opt.price != null && !opt.poa ? Number(opt.price) : null;
                }
                function setLegPrices(opt) {
                    if (!legOutEl || !legRetEl) return;
                    var legs = returnLegs();
                    if (legs) { legOutEl.value = legs[0].toFixed(2); legRetEl.value = legs[1].toFixed(2); return; }
                    var o = legAmount(opt, 'Outbound leg'), r = legAmount(opt, 'Return leg');
                    legOutEl.value = o == null ? '' : o.toFixed(2);
                    legRetEl.value = r == null ? '' : r.toFixed(2);
                }

                function showBreakdown() {
                    syncReturnPrice();
                    var opt = optFor(vehSel.value);
                    setLegPrices(opt);
                    var lines = opt && opt.breakdown ? opt.breakdown : [];
                    var legs = returnLegs();
                    if (legs) {
                        var rv = retVehSel && retVehSel.value && retVehSel.value !== vehSel.value
                            ? ' (' + retVehSel.options[retVehSel.selectedIndex].text + ')' : '';
                        lines = [{ label: 'Outbound leg', amount: legs[0] }, { label: 'Return leg' + rv, amount: legs[1] }];
                    }
                    var total = liveTotal(opt);
                    var ok = opt && !opt.poa && lines.length >= 2 && total != null;
                    var cardHtml = '', totalHtml = '';
                    if (ok) {
                        lines.forEach(function (l) {
                            var row = '<span>' + l.label + '</span><span>£' + Number(l.amount).toFixed(2) + '</span>';
                            cardHtml += '<div class="row">' + row + '</div>';
                            totalHtml += '<div class="row">' + row + '</div>';
                        });
                        var tot = '<span>Total</span><span>£' + total.toFixed(2) + '</span>';
                        cardHtml += '<div class="row total">' + tot + '</div>';
                        totalHtml += '<div class="row total">' + tot + '</div>';
                    }
                    if (breakdownEl) { breakdownEl.hidden = !ok; breakdownEl.innerHTML = cardHtml; }
                    if (totalBreakdownEl) { totalBreakdownEl.hidden = !ok; totalBreakdownEl.innerHTML = totalHtml; }
                }

                // Keep the price box (and so the big Total) in step with the live quote
                // for the chosen vehicle — unless the operator has typed a custom price.
                function applyLive() {
                    if (!priceEl) return;
                    syncReturnPrice();
                    var live = liveTotal(optFor(vehSel.value));
                    if (live == null) return;
                    var typed = parseFloat(priceEl.value);
                    var tracking = isNaN(typed) || (autoPrice != null && Math.abs(typed - autoPrice) < 0.01);
                    if (tracking) {
                        priceEl.value = live.toFixed(2);
                        autoPrice = live;
                        priceEl.dispatchEvent(new Event('input'));
                    }
                }

                // Nudge when the typed price doesn't match the live full quote for
                // the CHOSEN vehicle (incl. via-stop fee + ticked extras). Catches
                // both a stale typed price and extras ticked but not folded in.
                function nudge() {
                    if (!priceNudge || !priceEl) return;
                    var opt = optFor(vehSel.value);
                    var live = liveTotal(opt);
                    if (live == null) { priceNudge.hidden = true; return; }
                    var typed = parseFloat(priceEl.value);
                    if (isNaN(typed) || Math.abs(typed - live) < 0.01) { priceNudge.hidden = true; return; }
                    var extra = Number(opt && opt.extras_total || 0) > 0 ? ' (incl. stop / extras)' : '';
                    priceNudge.innerHTML = '';
                    var span = document.createElement('span');
                    span.textContent = 'Live quote ' + (returnLegs() ? 'for both legs' : 'for this vehicle') + ' is £' + live.toFixed(2) + extra + '. ';
                    var btn = document.createElement('button');
                    btn.type = 'button'; btn.className = 'link'; btn.textContent = 'Use £' + live.toFixed(2);
                    btn.addEventListener('click', function () { priceEl.value = live.toFixed(2); priceEl.dispatchEvent(new Event('input')); nudge(); });
                    priceNudge.appendChild(span); priceNudge.appendChild(btn);
                    priceNudge.hidden = false;
                }
                // Changing the return vehicle or typing a return price updates the
                // legs, the breakdown and the total straight away.
                function returnChanged() { applyLive(); nudge(); showBreakdown(); }
                if (retVehSel) retVehSel.addEventListener('change', function () { autoReturn = null; if (retPriceEl) retPriceEl.value = ''; returnChanged(); });
                if (retPriceEl) { retPriceEl.addEventListener('input', returnChanged); retPriceEl.addEventListener('change', returnChanged); }

                // Drop the live price onto each vehicle card (the cards are rendered
                // server-side; here we just fill in / refresh their prices).
                function render(options) {
                    lastOptions = options || [];
                    var byId = {};
                    lastOptions.forEach(function (o) { byId[String(o.id)] = o; });
                    cards.forEach(function (card) {
                        var o = byId[String(card.dataset.id)];
                        var amt = card.querySelector('[data-price]');
                        if (!o) { card.dataset.price = ''; if (amt) amt.textContent = '—'; return; }
                        card.dataset.price = (o.price == null ? '' : o.price);
                        if (amt) {
                            amt.textContent = o.formatted;
                            amt.classList.toggle('poa', !!o.poa);
                        }
                    });
                    selectCard(vehSel.value);
                    applyFit();
                    applyLive();
                    nudge();
                    showBreakdown();
                }

                function refresh() {
                    var p = pickup.value.trim(), d = dest.value.trim();
                    if (p.length < 4 || d.length < 4) { strip.hidden = true; return; }
                    var when = whenEl ? (whenEl.value || '') : '';
                    var journey = journeyEl ? (journeyEl.value || 'one_way') : 'one_way';
                    var returnAt = (journey === 'return' && returnEl) ? (returnEl.value || '') : '';
                    // Different return addresses (when given) price the return leg on its
                    // own route, not a blind reverse of the outbound.
                    var rp = (journey === 'return' && retPickupEl) ? (retPickupEl.value || '').trim() : '';
                    var rd = (journey === 'return' && retDestEl) ? (retDestEl.value || '').trim() : '';
                    var ex = extras();
                    // Re-quote when the journey, time, stops, return route or priced extras
                    // change — including switching to a return, so the price covers both legs.
                    var key = [p, d, when, journey, returnAt, rp, rd, ex.stops, ex.meet_greet, ex.child_seats, ex.booster_seats, ex.infant_seats, ex.ribbons].join('||');
                    if (key === lastKey) return;
                    lastKey = key;
                    var body = { pickup: p, destination: d, pickup_at: when, journey_type: journey, return_pickup_at: returnAt, return_pickup: rp, return_destination: rd };
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
                // The journey-type select and return time matter too: switching to a
                // return must re-price for both legs.
                var priceInputs = [pickup, dest, whenEl, journeyEl, returnEl, retPickupEl, retDestEl];
                document.querySelectorAll('input[name="via_stops[]"], #meet_greet, #child_seats, #booster_seats, #infant_seats, #ribbon').forEach(function (el) { priceInputs.push(el); });
                priceInputs.forEach(function (el) { if (!el) return; el.addEventListener('change', function () { clearTimeout(timer); timer = setTimeout(refresh, 300); }); el.addEventListener('blur', refresh); });
                // Exposed so a dynamically-added via stop can trigger a re-quote too.
                window.CETrequote = function () { clearTimeout(timer); timer = setTimeout(refresh, 300); };
                // Passengers / luggage change only the FIT, not the price.
                [paxEl, suitEl, handEl, vehSel].forEach(function (el) { if (el) el.addEventListener('change', applyFit); });
                // Choosing a vehicle, or editing the price, re-checks the drift nudge.
                if (vehSel) vehSel.addEventListener('change', function () { nudge(); showBreakdown(); });
                if (priceEl) { priceEl.addEventListener('input', nudge); priceEl.addEventListener('change', nudge); }
                // Reflect the preselected vehicle + grey any that don't fit, on load.
                selectCard(vehSel.value);
                applyFit();

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
                        if (hand > c.hand) why.push(hand + ' cabin bags (holds ' + c.hand + ')');
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
            var vatBox = document.getElementById('total-vat');
            var vatTick = document.getElementById('vat_invoice');
            function syncTotal() {
                if (!totalAmt) return;
                var v = parseFloat(price.value);
                totalAmt.childNodes[0].nodeValue = '£' + (isNaN(v) ? '0.00' : v.toFixed(2));
                // VAT invoice: the price is ex-VAT; the customer pays price + VAT.
                if (vatBox) {
                    var on = vatTick && vatTick.checked && !isNaN(v) && v > 0;
                    vatBox.hidden = !on;
                    if (on) {
                        var rate = parseFloat(vatBox.getAttribute('data-vat-rate')) || 0.20;
                        var vat = Math.round(v * rate * 100) / 100;
                        vatBox.innerHTML = '<div class="row"><span>Price (ex VAT)</span><span>£' + v.toFixed(2) + '</span></div>'
                            + '<div class="row"><span>VAT ' + Math.round(rate * 100) + '%</span><span>£' + vat.toFixed(2) + '</span></div>'
                            + '<div class="row total"><span>Customer pays (incl. VAT)</span><span>£' + (v + vat).toFixed(2) + '</span></div>';
                    }
                }
            }
            if (price) { price.addEventListener('input', syncTotal); price.addEventListener('change', syncTotal); }
            if (vatTick) { vatTick.addEventListener('change', syncTotal); }
            syncTotal();
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

    {{-- Existing-customer lookup: as the office types a name, matching returning
         customers drop down; picking one fills name, phone and email so a repeat
         client is one tap, not retyped. Self-contained; reads the endpoint from
         the field's data attribute. --}}
    <script>
    (function () {
        var box = document.getElementById('customer_name');
        var results = document.getElementById('customer-match');
        if (!box || !results || !box.dataset.customerSearch) return;
        var timer = null, lastQ = '';

        function esc(s) {
            return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
            });
        }
        function hide() { results.style.display = 'none'; }

        function render(list) {
            if (!list.length) { hide(); return; }
            results.innerHTML = list.map(function (r) {
                var sub = [r.phone, r.email].filter(Boolean).join(' · ');
                var tag = (r.vip ? '⭐ ' : '') + (r.bookings ? r.bookings + ' booking' + (r.bookings === 1 ? '' : 's') : 'new');
                return '<div class="cm-opt" role="option" tabindex="0"'
                    + ' data-name="' + esc(r.name) + '" data-phone="' + esc(r.phone || '') + '" data-email="' + esc(r.email || '') + '"'
                    + ' style="padding:9px 12px;border-bottom:1px solid #eee;cursor:pointer;font-size:13px;color:#111">'
                    + '<strong>' + esc(r.name) + '</strong> <span style="color:#888;font-size:11px">· ' + esc(tag) + '</span>'
                    + (sub ? '<div style="color:#666;font-size:12px;margin-top:2px">' + esc(sub) + '</div>' : '')
                    + '</div>';
            }).join('');
            results.style.display = 'block';
        }

        function look() {
            var q = box.value.trim();
            if (q === lastQ) return;
            lastQ = q;
            if (q.length < 2) { hide(); return; }
            fetch(box.dataset.customerSearch + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (box.value.trim() === q) render(d.results || []); })
                .catch(hide);
        }

        box.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(look, 220); });
        results.addEventListener('click', pick);
        results.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); pick(e); } });
        function pick(e) {
            var opt = e.target.closest('.cm-opt');
            if (!opt) return;
            box.value = opt.dataset.name;
            var ph = document.getElementById('customer_phone');
            var em = document.getElementById('customer_email');
            if (ph && opt.dataset.phone) ph.value = opt.dataset.phone;
            if (em && opt.dataset.email) em.value = opt.dataset.email;
            lastQ = box.value.trim();
            hide();
        }
        document.addEventListener('click', function (e) {
            if (!results.contains(e.target) && e.target !== box) hide();
        });
    })();
    </script>
    {{-- ───── Layout refresh (Oct 2026): compact header, numbered steps, and on wide
         screens a two-column form with the total as a sticky summary panel. Pure
         presentation — every field id/name and script hook is unchanged. ───── --}}
    <style>
        .sf-booking .smart-hero.sf-compact{ display:flex; align-items:flex-end; justify-content:space-between;
            gap:12px 20px; flex-wrap:wrap; padding:16px 20px 18px; }
        .sf-booking .smart-hero.sf-compact h1{ margin:2px 0 0; font-size:24px; }
        .sf-booking .smart-hero.sf-compact .pill{ margin-top:0; }

        /* Numbered steps — the sections are filled in this order. */
        .sf-booking .eto-form{ counter-reset:sfstep; }
        .sf-booking .eto-section > .head{ counter-increment:sfstep; }
        .sf-booking .eto-section > .head .ico{ display:none; }
        .sf-booking .eto-section > .head::before{ content:counter(sfstep); flex:0 0 auto; width:24px; height:24px;
            border-radius:50%; display:inline-grid; place-items:center; font-size:12px; letter-spacing:0;
            background:#0b0b0c; color:#FBBA2A; margin-right:2px; }
        html[data-theme="dark"] .sf-booking .eto-section > .head::before{ background:#FBBA2A; color:#0b0b0c; }

        /* Meet & greet status shown on the Extras header even when folded. */
        .sf-booking .sf-chip{ display:inline-flex; align-items:center; margin-left:8px; padding:3px 9px; border-radius:999px;
            background:rgba(251,186,42,.18); border:1px solid rgba(233,164,19,.6); color:#6b4e00;
            font-size:11px; font-weight:800; letter-spacing:.4px; text-transform:uppercase; vertical-align:middle; }
        html[data-theme="dark"] .sf-booking .sf-chip{ color:#FBBA2A; }
        .sf-booking .sf-chip[hidden]{ display:none; }

        /* Summary list inside the dark total panel. */
        .sf-booking .sf-summary{ display:none; margin:0 0 14px; padding:0 0 12px; border-bottom:1px solid rgba(255,255,255,.12);
            font-size:13px; line-height:1.45; }
        .sf-booking .sf-summary div{ display:grid; grid-template-columns:86px minmax(0,1fr); gap:8px; padding:3px 0; }
        .sf-booking .sf-summary dt{ color:#9d9c96; font-size:11px; letter-spacing:.6px; text-transform:uppercase; padding-top:2px; }
        .sf-booking .sf-summary dd{ margin:0; color:#f4f2ec; overflow-wrap:anywhere; }
        .sf-booking .sf-summary dd.empty{ color:#6f6e69; }
        .sf-booking .sf-summary dd.warn{ color:#FBBA2A; font-weight:700; }

        @media (min-width:1100px){
            .sf-booking .eto-form{ display:grid; grid-template-columns:minmax(0,1fr) 340px; column-gap:22px; align-items:start; max-width:1240px; }
            .sf-booking .eto-form > *{ grid-column:1; }
            .sf-booking .eto-form > .total-bar{ grid-column:2; grid-row:1 / span 40; position:sticky; top:84px; bottom:auto;
                flex-direction:column; align-items:stretch; margin-top:0; padding:18px; }
            .sf-booking .eto-form > .total-bar .actions{ flex-direction:column; align-items:stretch; }
            .sf-booking .eto-form > .total-bar .actions > *{ width:100%; text-align:center; justify-content:center; }
            .sf-booking .eto-form > .total-bar .btn-primary{ order:-1; padding:15px 18px; }
            .sf-booking .sf-summary{ display:block; }
            .sf-booking .veh-cards{ grid-template-columns:repeat(auto-fill, minmax(210px, 1fr)); }
        }
        @media (max-width:560px){
            .sf-booking .smart-hero.sf-compact .pill{ display:none; }
            .sf-booking .total-bar{ padding:12px 14px; gap:10px; }
            .sf-booking .total-bar .total-amount{ font-size:24px; }
            .sf-booking .total-bar .actions{ display:grid; grid-template-columns:1fr 1fr; gap:8px; }
            .sf-booking .total-bar .actions .btn-primary{ grid-column:1 / -1; order:-1; }
        }
    </style>
    <script>
    // Live booking summary + meet & greet chip (read-only mirrors of the fields).
    (function () {
        var box = document.getElementById('sf-summary');
        var chip = document.getElementById('sf-mg-chip');
        var form = box && box.closest('form');
        if (!form) return;
        function val(id) { var el = document.getElementById(id); return el ? String(el.value || '').trim() : ''; }
        function short(a) { a = a.replace(/,\s*UK$/i, ''); return a.length > 70 ? a.slice(0, 68) + '…' : a; }
        function when(v) {
            if (!v) return '';
            var d = new Date(v);
            if (isNaN(d)) return v;
            return d.toLocaleString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
        }
        function plural(n, w) { n = parseInt(n, 10) || 0; return n + ' ' + w + (n === 1 ? '' : 's'); }
        function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
        function row(label, text, cls) {
            return '<div><dt>' + label + '</dt><dd class="' + (cls || (text ? '' : 'empty')) + '">' + esc(text || '—') + '</dd></div>';
        }
        function render() {
            var jt = document.getElementById('journey_type');
            var journey = jt ? jt.options[jt.selectedIndex].text : '';
            if (jt && jt.value === 'hourly') journey += ' · ' + (val('hours') || '?') + ' h';
            var veh = document.getElementById('vehicle_type_id');
            var vehText = veh && veh.value ? veh.options[veh.selectedIndex].text : '';
            var mg = document.getElementById('meet_greet');
            var mgOn = !!(mg && mg.checked);
            var pm = document.getElementById('payment_method');
            var vat = document.getElementById('vat_invoice');
            var pay = pm && pm.value ? pm.options[pm.selectedIndex].text : '';
            if (vat && vat.checked) pay += ' · VAT invoice';
            var html = row('Customer', val('customer_name'))
                + row('Journey', journey)
                + row('Pickup', short(val('pickup_address')))
                + row(jt && jt.value === 'hourly' ? 'Then' : 'Drop-off', short(val('destination_address')) || (jt && jt.value === 'hourly' ? 'As directed' : ''))
                + row('When', when(val('pickup_at')));
            if (jt && jt.value === 'return') html += row('Return', when(val('return_pickup_at')));
            html += row('Vehicle', vehText)
                + row('People', plural(val('passengers'), 'passenger') + ' · ' + plural(val('suitcases'), 'suitcase') + ' · ' + plural(val('hand_luggage'), 'cabin bag'))
                + row('Meet & greet', mgOn ? 'Yes' : 'No', mgOn ? 'warn' : '')
                + row('Payment', pay);
            if (box.innerHTML !== html) box.innerHTML = html;
            if (chip) chip.hidden = !mgOn;
        }
        form.addEventListener('input', render);
        form.addEventListener('change', render);
        document.addEventListener('click', function () { setTimeout(render, 0); });
        setInterval(render, 1500); // picks up values set by the address/price scripts
        render();
    })();
    </script>
@endsection
