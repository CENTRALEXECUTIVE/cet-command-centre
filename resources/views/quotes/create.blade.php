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
        <fieldset>
            <legend>Journey</legend>
            <div class="grid grid-2">
                <div class="field">
                    <label for="pickup_address">Pickup address <span class="req">*</span></label>
                    <textarea id="pickup_address" name="pickup_address" required>{{ old('pickup_address') }}</textarea>
                </div>
                <div class="field">
                    <label for="destination_address">Destination address <span class="req">*</span></label>
                    <textarea id="destination_address" name="destination_address" required>{{ old('destination_address') }}</textarea>
                </div>
            </div>
            <div class="grid grid-2">
                <div class="field">
                    <label for="vehicle_type_id">Vehicle type <span class="req">*</span></label>
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
@endsection
