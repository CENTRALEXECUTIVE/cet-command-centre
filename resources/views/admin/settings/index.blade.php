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
            <p class="muted" style="margin-top:0">Powers the booking form's address dropdown and distance-based free-roam pricing. In Google Cloud enable <strong>Places API (New)</strong> and <strong>Routes API</strong> (the legacy "Places API" and "Distance Matrix API" no longer work for new projects), then paste the API key here and press <strong>Save</strong> before testing.</p>
            <label>API key
                <input type="text" name="google_maps_key" value="{{ $mapsKey }}" placeholder="AIza…" autocomplete="off" spellcheck="false">
            </label>
            @if($mapsKey)
                <p class="muted" style="font-size:12px;margin-bottom:8px;color:#1f7a44">✓ Key saved — address autocomplete and distance pricing are active.</p>
            @else
                <p class="muted" style="font-size:12px;margin-bottom:8px">Not set — the booking form uses plain text boxes until a key is added.</p>
            @endif
            <button type="button" id="test-places" class="btn btn-light" style="padding:7px 14px">Test address search</button>
            <span id="test-result" class="muted" style="font-size:13px;margin-left:8px"></span>
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>

    <script>window.CET_PLACES_URL = "{{ route('places.autocomplete') }}";</script>
    <script>
        document.getElementById('test-places').addEventListener('click', function () {
            var out = document.getElementById('test-result');
            out.textContent = 'testing…'; out.style.color = '';
            fetch(window.CET_PLACES_URL + '?q=Meadowhall Sheffield', { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.suggestions && d.suggestions.length) {
                        out.style.color = '#1f7a44';
                        out.textContent = '✓ Working — e.g. "' + d.suggestions[0] + '"';
                    } else {
                        out.style.color = '#b32020';
                        out.textContent = '✗ No results — save the key above, or check it allows Places API (New) with no HTTP-referrer restriction.';
                    }
                })
                .catch(function () { out.style.color = '#b32020'; out.textContent = '✗ Request failed.'; });
        });
    </script>
@endsection
