@extends('layouts.app')
@section('title', 'Edit Booking ' . $booking->reference)

@section('content')
    <p class="page-sub"><a href="{{ route('bookings.show', $booking) }}">← Booking {{ $booking->reference }}</a></p>
    <h1 class="page-title">Edit Booking <span class="mono">{{ $booking->reference }}</span></h1>
    <p class="page-sub">Amend the journey, passenger or price. The driver and rotation stay as they are.</p>

    @if($errors->any())
        <div class="alert alert-error">
            <ul style="margin:0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    @if($booking->linked_booking_id)
        <div class="card" style="border-left:4px solid #FBBA2A;background:rgba(251,186,42,.08);margin-bottom:16px">
            This booking is part of a <strong>return pair</strong>. Editing here changes this leg only — amend the other leg separately.
        </div>
    @endif

    <form method="POST" action="{{ route('bookings.update', $booking) }}" class="eto-form">
        @csrf
        @method('PUT')

        {{-- Customer --}}
        <div class="eto-section">
            <div class="head"><span class="ico">👤</span> Customer</div>
            <div class="body">
                <div class="grid grid-2">
                    <div class="field">
                        <label for="customer_name">Full name <span class="req">*</span></label>
                        <input id="customer_name" name="customer_name" value="{{ old('customer_name', $booking->customer?->name) }}" required>
                    </div>
                    <div class="field">
                        <label for="customer_phone">Mobile number</label>
                        <input id="customer_phone" name="customer_phone" value="{{ old('customer_phone', $booking->customer?->phone) }}" placeholder="07…">
                    </div>
                </div>
                <div class="field" style="margin-bottom:0">
                    <label for="customer_email">Email address</label>
                    <input id="customer_email" type="email" name="customer_email" value="{{ old('customer_email', $booking->customer?->email) }}">
                </div>
            </div>
        </div>

        {{-- Locations --}}
        <div class="eto-section">
            <div class="head"><span class="ico">📍</span> Locations</div>
            <div class="body">
                <div class="field">
                    <label for="pickup_address">Pickup address <span class="req">*</span></label>
                    <div class="loc-row">
                        <span class="pin pickup">A</span>
                        <div class="grow"><textarea id="pickup_address" name="pickup_address" data-places autocomplete="off" required>{{ old('pickup_address', $booking->pickup_address) }}</textarea></div>
                    </div>
                </div>

                @unless($booking->is_return_leg)
                <div class="field">
                    <label>Via stops <span class="muted">(optional)</span></label>
                    <div id="via-stops">
                        @php $oldStops = old('via_stops', $booking->stops->pluck('address')->all() ?: ['']); @endphp
                        @foreach($oldStops as $stop)
                            <div class="loc-row" style="margin-bottom:8px">
                                <span class="pin via">•</span>
                                <div class="grow"><input name="via_stops[]" value="{{ $stop }}" data-places autocomplete="off" placeholder="Add a stop along the way"></div>
                            </div>
                        @endforeach
                    </div>
                    <button type="button" class="btn btn-ghost" id="add-stop" style="padding:6px 14px;font-size:13px">+ Add stop</button>
                </div>
                @endunless

                <div class="field">
                    <label for="destination_address">Destination address <span class="req">*</span></label>
                    <div class="loc-row">
                        <span class="pin drop">B</span>
                        <div class="grow"><textarea id="destination_address" name="destination_address" data-places autocomplete="off" required>{{ old('destination_address', $booking->destination_address) }}</textarea></div>
                    </div>
                </div>

                <div class="grid grid-2">
                    <div class="field">
                        <label for="pickup_at">Pickup date &amp; time <span class="req">*</span></label>
                        <input id="pickup_at" type="datetime-local" name="pickup_at" value="{{ old('pickup_at', $booking->pickup_at->format('Y-m-d\TH:i')) }}" required>
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label for="airport_id">Airport</label>
                        <select id="airport_id" name="airport_id">
                            <option value="">— Not an airport job —</option>
                            @foreach($airports as $airport)
                                <option value="{{ $airport->id }}" @selected(old('airport_id', $booking->airport_id)==$airport->id)>{{ $airport->code }} — {{ $airport->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </div>

        {{-- Passengers & vehicle --}}
        <div class="eto-section">
            <div class="head"><span class="ico">🧳</span> Passengers &amp; Vehicle</div>
            <div class="body">
                <div class="grid grid-2">
                    <div class="field">
                        <label for="vehicle_type_id">Vehicle type <span class="req">*</span></label>
                        <select id="vehicle_type_id" name="vehicle_type_id" required>
                            @foreach($vehicleTypes as $vt)
                                <option value="{{ $vt->id }}" @selected(old('vehicle_type_id', $booking->vehicle_type_id)==$vt->id)>{{ $vt->name }} (up to {{ $vt->passenger_capacity }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="flight_number">Flight number</label>
                        <input id="flight_number" name="flight_number" value="{{ old('flight_number', $booking->flight_number) }}" placeholder="e.g. BA1234">
                    </div>
                    <div class="field">
                        <label for="passengers">Passengers <span class="req">*</span></label>
                        <input id="passengers" type="number" name="passengers" min="1" max="16" value="{{ old('passengers', $booking->passengers) }}" required>
                    </div>
                    <div class="field">
                        <label for="luggage">Luggage items</label>
                        <input id="luggage" type="number" name="luggage" min="0" max="30" value="{{ old('luggage', $booking->luggage) }}">
                    </div>
                </div>
                <div class="field" style="margin-bottom:0">
                    <label for="special_requests">Special requests</label>
                    <textarea id="special_requests" name="special_requests" placeholder="Child seat, meet &amp; greet, name board…">{{ old('special_requests', $booking->special_requests) }}</textarea>
                </div>
            </div>
        </div>

        {{-- Payment --}}
        <div class="eto-section">
            <div class="head"><span class="ico">💳</span> Payment</div>
            <div class="body">
                <div class="grid grid-2">
                    <div class="field">
                        <label for="payment_method">Payment method <span class="req">*</span></label>
                        <select id="payment_method" name="payment_method" required>
                            <option value="card" @selected(old('payment_method', $booking->payment_method?->value)==='card')>Card (Tide link)</option>
                            <option value="cash" @selected(old('payment_method', $booking->payment_method?->value)==='cash')>Cash</option>
                            <option value="account" @selected(old('payment_method', $booking->payment_method?->value)==='account')>Account (invoiced)</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="payment_status">Payment status</label>
                        <select id="payment_status" name="payment_status">
                            <option value="pending" @selected(old('payment_status', $booking->payment_status)==='pending')>Pending</option>
                            <option value="paid" @selected(old('payment_status', $booking->payment_status)==='paid')>Paid</option>
                            <option value="refunded" @selected(old('payment_status', $booking->payment_status)==='refunded')>Refunded</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="quoted_price">Quoted price (£)</label>
                        <input id="quoted_price" type="number" step="0.01" min="0" name="quoted_price" value="{{ old('quoted_price', $booking->quoted_price) }}">
                    </div>
                    <div class="field">
                        <label for="final_price">Final price (£) <span class="muted">if different</span></label>
                        <input id="final_price" type="number" step="0.01" min="0" name="final_price" value="{{ old('final_price', $booking->final_price) }}">
                    </div>
                </div>
            </div>
        </div>

        <div class="toolbar">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('bookings.show', $booking) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

    <script>
        window.CET_MAPS_KEY = "{{ \App\Models\Setting::mapsKey() }}";
        window.CET_PLACES_URL = "{{ route('places.autocomplete') }}";
    </script>
    <script src="{{ asset('js/cet-forms.js') }}?v=4"></script>
    @verbatim
    <script>
        (function () {
            var addBtn = document.getElementById('add-stop');
            var wrap = document.getElementById('via-stops');
            if (addBtn) {
                addBtn.addEventListener('click', function () {
                    var row = document.createElement('div');
                    row.className = 'loc-row'; row.style.marginBottom = '8px';
                    var pin = document.createElement('span'); pin.className = 'pin via'; pin.textContent = '•';
                    var grow = document.createElement('div'); grow.className = 'grow';
                    var input = document.createElement('input');
                    input.name = 'via_stops[]';
                    input.placeholder = 'Add a stop along the way';
                    input.setAttribute('data-places', ''); input.setAttribute('autocomplete', 'off');
                    grow.appendChild(input); row.appendChild(pin); row.appendChild(grow); wrap.appendChild(row);
                    if (window.CETattachPlaces) window.CETattachPlaces(input);
                });
            }
        })();
    </script>
    @endverbatim
@endsection
