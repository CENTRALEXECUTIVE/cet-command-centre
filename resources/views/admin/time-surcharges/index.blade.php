@extends('layouts.app')
@section('title', 'Time-based pricing')

@section('content')
    <div class="list-head" style="align-items:center">
        <div class="form-hero" style="flex:1;margin-bottom:0">
            <div class="form-hero-glow"></div>
            <div class="fh-eyebrow">Fleet &amp; admin · pricing</div>
            <div class="fh-title">Time-based pricing</div>
            <div class="fh-sub">A night surcharge and dated uplifts (Christmas, New Year), added to the fare for the pickup time.</div>
        </div>
    </div>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08)"><ul style="margin:0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    <form method="POST" action="{{ route('time-surcharges.update') }}">
        @csrf @method('PUT')

        <div class="card">
            <h2 style="margin:0 0 10px">Night surcharge</h2>
            <label class="cet-agree" style="margin-bottom:10px"><input type="checkbox" name="night_enabled" value="1" {{ ($night['enabled'] ?? false) ? 'checked' : '' }}> Apply a surcharge for pickups during the night window</label>
            <div class="grid grid-2" style="gap:12px">
                <div class="field"><label>From</label><input name="night_from" type="time" value="{{ old('night_from', $night['from'] ?? '22:00') }}" required></div>
                <div class="field"><label>To</label><input name="night_to" type="time" value="{{ old('night_to', $night['to'] ?? '06:00') }}" required></div>
                <div class="field"><label>Type</label>
                    <select name="night_type">
                        <option value="percent" @selected(($night['type'] ?? 'percent')==='percent')>% of fare</option>
                        <option value="fixed" @selected(($night['type'] ?? '')==='fixed')>£ fixed</option>
                    </select>
                </div>
                <div class="field"><label>Value</label><input name="night_value" type="number" step="0.01" min="0" value="{{ old('night_value', $night['value'] ?? 0) }}" required></div>
            </div>
            <p class="hint" style="margin:6px 0 0">A window that crosses midnight (e.g. 22:00 → 06:00) is handled automatically.</p>
        </div>

        <div class="card">
            <h2 style="margin:0 0 6px">Dated uplifts</h2>
            <p class="hint" style="margin:0 0 10px">One per line, as <code>date | label | percent or fixed | value</code>. Example:<br>
                <code>2026-12-25 | Christmas Day | percent | 50</code><br>
                <code>2026-12-31 | New Year's Eve | fixed | 20</code></p>
            <textarea name="dates" rows="5" style="width:100%;font-family:monospace">{{ old('dates', $datesText) }}</textarea>
        </div>

        <button class="btn btn-primary" style="padding:10px 20px">Save</button>
    </form>
@endsection
