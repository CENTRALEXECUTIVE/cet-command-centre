@extends('layouts.app')
@section('title', 'Fixed Prices')

@section('content')
    <h1 class="page-title">Fixed Prices</h1>
    <p class="page-sub">Set a fixed fare per zone → destination, per vehicle type — mirrors your ETO Fixed Prices screen.</p>

    @if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif

    <div class="card">
        <h2>Add / update a route</h2>
        <form method="POST" action="{{ route('pricing.store') }}">
            @csrf
            <div class="grid grid-3">
                <div class="field">
                    <label for="pricing_zone_id">From (zone) <span class="req">*</span></label>
                    <select id="pricing_zone_id" name="pricing_zone_id" required>
                        @foreach($zones as $zone)
                            <option value="{{ $zone->id }}" @selected(old('pricing_zone_id')==$zone->id)>{{ $zone->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="destination">To (destination) <span class="req">*</span></label>
                    <input id="destination" name="destination" value="{{ old('destination') }}" placeholder="e.g. Manchester Airport" required>
                </div>
                <div class="field">
                    <label for="deposit">Deposit (£)</label>
                    <input id="deposit" type="number" step="0.01" min="0" name="deposit" value="{{ old('deposit') }}">
                </div>
            </div>
            <div class="grid grid-3">
                @foreach($vehicleTypes as $vt)
                    <div class="field">
                        <label for="p{{ $vt->id }}">{{ $vt->name }} (£)</label>
                        <input id="p{{ $vt->id }}" type="number" step="0.01" min="0" name="prices[{{ $vt->id }}]" value="{{ old('prices.'.$vt->id) }}">
                    </div>
                @endforeach
            </div>
            <button type="submit" class="btn btn-primary">Save route</button>
            <span class="hint">Leave a vehicle blank to skip/clear it. Re-saving the same zone+destination updates the prices.</span>
        </form>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('pricing.index') }}">
            <select name="zone" onchange="this.form.submit()" style="width:auto">
                <option value="">All zones</option>
                @foreach($zones as $zone)
                    <option value="{{ $zone->id }}" @selected(request('zone')==$zone->id)>{{ $zone->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="card">
        <h2>Current fixed prices</h2>
        @if($rows->isEmpty())
            <p class="muted mb-0">No fixed prices yet — add one above.</p>
        @else
            <table>
                <thead>
                    <tr><th>From</th><th>To</th>
                        @foreach($vehicleTypes as $vt)<th class="right">{{ $vt->name }}</th>@endforeach
                        <th></th></tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr>
                            <td>{{ $row['zone']?->name }}</td>
                            <td>{{ $row['destination'] }}</td>
                            @foreach($vehicleTypes as $vt)
                                <td class="right">{{ isset($row['prices'][$vt->id]) ? '£'.number_format($row['prices'][$vt->id]->price, 2) : '—' }}</td>
                            @endforeach
                            <td class="right">
                                <form method="POST" action="{{ route('pricing.destroy') }}" onsubmit="return confirm('Remove this route?');">
                                    @csrf @method('DELETE')
                                    <input type="hidden" name="pricing_zone_id" value="{{ $row['zone']?->id }}">
                                    <input type="hidden" name="destination_slug" value="{{ \Illuminate\Support\Str::slug($row['destination']) }}">
                                    <button class="btn-xs" style="background:#b32020" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
