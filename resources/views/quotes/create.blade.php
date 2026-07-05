@extends('layouts.app')
@section('title', 'Instant Quote')

@section('content')
    <h1 class="page-title">Instant Quote</h1>
    <p class="page-sub">AI pricing powered by {{ config('cet.ai_model') }} — distance, time of day, demand and bank holidays.</p>

    @if($errors->any())
        <div class="alert alert-error">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('quotes.store') }}">
        @csrf
        @if($zones->isNotEmpty())
        <fieldset>
            <legend>Fixed-price route <span class="muted" style="font-weight:400">(optional — overrides distance pricing)</span></legend>
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
            <p class="hint">Set the destination below to a known fixed destination (e.g. {{ $destinations->take(3)->join(', ') }}) to use the matrix price.</p>
        </fieldset>
        @endif

        <fieldset>
            <legend>Journey</legend>
            <div class="grid grid-2">
                <div class="field">
                    <label for="pickup_address">Pickup address <span class="req">*</span></label>
                    <textarea id="pickup_address" name="pickup_address" data-places autocomplete="off" required>{{ old('pickup_address') }}</textarea>
                </div>
                <div class="field">
                    <label for="destination_address">Destination address <span class="req">*</span></label>
                    <textarea id="destination_address" name="destination_address" list="destinations" data-places autocomplete="off" required>{{ old('destination_address') }}</textarea>
                    <datalist id="destinations">
                        @foreach($destinations as $d)<option value="{{ $d }}">@endforeach
                    </datalist>
                </div>
            </div>
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
        </fieldset>
        <button type="submit" class="btn btn-primary">Generate Quote</button>
    </form>

    <script>
        window.CET_MAPS_KEY = "{{ \App\Models\Setting::mapsKey() }}";
        window.CET_PLACES_URL = "{{ route('places.autocomplete') }}";
        window.CET_ESTIMATE_URL = "{{ route('pricing.estimate') }}";
    </script>
    <script src="{{ asset('js/cet-forms.js') }}?v=3"></script>
@endsection
