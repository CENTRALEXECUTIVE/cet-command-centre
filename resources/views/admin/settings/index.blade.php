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

        <div class="card">
            <h2>📞 Phone lines (number masking)</h2>
            <p class="muted" style="margin-top:0">Your two permanent Twilio numbers. The <strong>customer line</strong> is the masked number customers ring/text to reach the driver; the <strong>driver line</strong> is on the driver's job screen to reach the customer. Change a number here — no server access needed.</p>
            <div class="grid grid-2">
                <label>Customer line
                    <input type="text" name="twilio_customer_line" value="{{ $customerLine }}" placeholder="+447…" autocomplete="off" spellcheck="false">
                    @if($customerLine)
                        <span style="font-size:12px;color:#1f7a44;font-weight:600">✓ Active · {{ $customerLine }}</span>
                    @else
                        <span style="font-size:12px;color:#b32020;font-weight:600">✗ Not set — masking off for customers</span>
                    @endif
                </label>
                <label>Driver line
                    <input type="text" name="twilio_driver_line" value="{{ $driverLine }}" placeholder="+447…" autocomplete="off" spellcheck="false">
                    @if($driverLine)
                        <span style="font-size:12px;color:#1f7a44;font-weight:600">✓ Active · {{ $driverLine }}</span>
                    @else
                        <span style="font-size:12px;color:#b32020;font-weight:600">✗ Not set</span>
                    @endif
                </label>
            </div>
            <p class="muted" style="font-size:12px;margin:6px 0 0">Enter in full international format, e.g. <strong>+447575583899</strong>. Leave blank to fall back to the server's configured number.</p>

            <div style="margin-top:14px;border-top:1px solid var(--line);padding-top:12px">
                <p class="muted" style="margin:0 0 6px;font-weight:600">Paste these into each Twilio number (HTTP POST):</p>
                <label style="font-size:12px">Messaging webhook URL
                    <input type="text" value="{{ $smsWebhook }}" readonly onclick="this.select()" style="font-family:ui-monospace,monospace;font-size:12px">
                </label>
                <label style="font-size:12px">Voice webhook URL
                    <input type="text" value="{{ $voiceWebhook }}" readonly onclick="this.select()" style="font-family:ui-monospace,monospace;font-size:12px">
                </label>
            </div>
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
