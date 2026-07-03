@extends('layouts.app')
@section('title', 'Settings')

@section('content')
    <h1 class="page-title">Settings</h1>
    <p class="page-sub">Integration keys — paste them here, no server access needed.</p>

    @if(session('status'))
        <div class="card" style="border-left:4px solid #1f7a44;background:rgba(31,122,68,.08);margin-bottom:16px">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('settings.update') }}">
        @csrf
        @method('PUT')
        <div class="card">
            <h2>🗺️ Google Maps</h2>
            <p class="muted" style="margin-top:0">Powers the booking form's address dropdown and distance-based free-roam pricing. Enable <strong>Places API</strong>, <strong>Maps JavaScript API</strong> and <strong>Distance Matrix API</strong> in Google Cloud, then paste the API key here.</p>
            <label>API key
                <input type="text" name="google_maps_key" value="{{ $mapsKey }}" placeholder="AIza…" autocomplete="off" spellcheck="false">
            </label>
            @if($mapsKey)
                <p class="muted" style="font-size:12px;margin-bottom:0;color:#1f7a44">✓ Key saved — address autocomplete and distance pricing are active.</p>
            @else
                <p class="muted" style="font-size:12px;margin-bottom:0">Not set — the booking form uses plain text boxes until a key is added.</p>
            @endif
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
@endsection
