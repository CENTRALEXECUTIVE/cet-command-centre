@extends('layouts.app')
@section('title', 'Job ' . $booking->reference)

@section('content')
    <a href="{{ route('driver.jobs') }}" class="muted" style="font-size:13px">← All jobs</a>
    <h1 class="page-title" style="margin-top:8px">
        {{ $booking->pickup_at->format('H:i') }} · {{ $booking->displayName() }}
        <span class="badge badge-{{ $booking->status->value }}">{{ $booking->status->label() }}</span>
    </h1>
    <p class="page-sub">{{ $booking->reference }} · {{ $booking->vehicleType?->name }}</p>

    @if($errors->any())
        <div class="alert alert-error">{{ $errors->first() }}</div>
    @endif

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    {{-- One-tap navigation: opens the driver's own Google/Waze/Apple Maps app. --}}
    <div style="display:flex;gap:8px;margin-bottom:16px">
        <a class="btn btn-dark" style="flex:1;text-align:center"
           href="https://www.google.com/maps/dir/?api=1&destination={{ urlencode($booking->pickup_address) }}"
           target="_blank" rel="noopener">🧭 Navigate to pickup</a>
        <a class="btn btn-ghost" style="flex:1;text-align:center"
           href="https://www.google.com/maps/dir/?api=1&destination={{ urlencode($booking->destination_address) }}"
           target="_blank" rel="noopener">Navigate to drop-off</a>
    </div>

    <div id="job-map" style="height:220px;border-radius:10px;margin-bottom:16px;display:none"></div>
    <div id="map-cfg" data-pickup="{{ $booking->pickup_address }}" data-dropoff="{{ $booking->destination_address }}" hidden></div>

    <div class="card">
        <table>
            <tr><th>Pickup</th><td>{{ $booking->pickup_at->format('D d M, H:i') }}</td></tr>
            <tr><th>From</th><td>{{ $booking->displayPickupAddress() }}</td></tr>
            @foreach($booking->stops as $stop)
                <tr><th>Via {{ $stop->sequence }}</th><td>{{ $stop->address }}</td></tr>
            @endforeach
            <tr><th>To</th><td>{{ $booking->displayDropoffAddress() }}</td></tr>
            @if($booking->displayFlightNumber())
                <tr><th>Flight</th><td>
                    <span class="mono">{{ $booking->displayFlightNumber() }}</span>
                    <a href="{{ $booking->flightRadarUrl() }}" data-flightradar target="_blank" rel="noopener" class="btn" style="background:#fc3d02;color:#fff;padding:4px 12px;font-size:13px;margin-left:6px">✈ Track flight</a>
                </td></tr>
            @endif
            @if($booking->displayMeetAndGreet())<tr><th>Meet &amp; Greet</th><td>{{ $booking->displayMeetAndGreet() }}</td></tr>@endif
            <tr><th>Vehicle</th><td>{{ $booking->displayVehicleType() }}</td></tr>
            <tr><th>Passengers</th><td>{{ $booking->passengerCount() }}</td></tr>
            <tr><th>Luggage</th><td>{{ $booking->luggageBreakdown() }}</td></tr>
            @if($booking->customer?->phone)
                <tr><th>Contact</th><td><a href="tel:{{ $booking->customer->phone }}">{{ $booking->customer->phone }}</a></td></tr>
            @endif
            @if($booking->special_requests)<tr><th>Notes</th><td>{{ $booking->special_requests }}</td></tr>@endif
        </table>
    </div>

    {{-- GPS pinger config — tracking is active only while the job is active. --}}
    <div id="gps-config"
         data-active="{{ $booking->status->isActive() ? '1' : '0' }}"
         data-url="{{ route('driver.locations.store') }}"
         data-interval="{{ (int) config('cet.gps_ping_seconds', 300) }}" hidden></div>

    {{-- Live tracking status so the driver can SEE it's working. --}}
    <div id="track-box" class="card" style="display:none;text-align:center;border-left:4px solid #1f7a44">
        <div style="font-weight:700;font-size:15px"><span id="track-dot">🟡</span> <span id="track-label">Getting your location…</span></div>
        <div class="muted" style="font-size:13px;margin-top:2px" id="track-last">The office can see you on the Live map.</div>
        <button type="button" id="track-now" class="btn btn-light" style="margin-top:10px;padding:8px 16px">📍 Send my location now</button>
    </div>

    {{-- Message the office on WhatsApp Business. --}}
    <a href="https://wa.me/447405172435?text={{ rawurlencode('Re '.$booking->reference.' ('.$booking->displayName().'): ') }}" target="_blank" rel="noopener"
       class="btn" style="display:block;background:#25D366;color:#fff;padding:12px;font-weight:700;border-radius:10px;margin-bottom:12px;text-align:center">
        💬 Message the office
    </a>

    @php
        $next = $booking->status->nextStatuses();
        // Drivers can't cancel or mark no-show — only admins (Majid / the office).
        if (! auth()->user()->isAdmin()) {
            $next = array_values(array_filter($next, fn ($s) => ! in_array($s->value, ['cancelled', 'no_show'], true)));
        }
    @endphp
    @if(!empty($next))
        <div class="tap-actions">
            @foreach($next as $status)
                @php
                    $class = match($status->value) {
                        'accepted' => 'tap-accept', 'en_route' => 'tap-go',
                        'arrived' => 'tap-arrive', 'collected' => 'tap-collect',
                        'complete' => 'tap-complete',
                        default => 'tap-cancel',
                    };
                    $btnLabel = match($status->value) {
                        'en_route' => 'On My Way', 'collected' => 'Passenger On Board',
                        'complete' => 'Completed', default => $status->label(),
                    };
                @endphp
                <form method="POST" action="{{ route('driver.job.status', $booking) }}" class="status-form">
                    @csrf
                    <input type="hidden" name="status" value="{{ $status->value }}">
                    <input type="hidden" name="lat" class="lat-input">
                    <input type="hidden" name="lng" class="lng-input">
                    <button type="submit" class="{{ $class }}" style="width:100%">{{ $btnLabel }}</button>
                </form>
            @endforeach
        </div>
    @else
        <div class="card"><p class="muted mb-0">This job is {{ $booking->status->label() }} — no further action.</p></div>
    @endif

    @verbatim
    <script>
        // Capture GPS position at the moment of a one-tap status change so the
        // audit trail records where the driver was. Submits with or without a fix.
        document.querySelectorAll('.status-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (form.dataset.located || !navigator.geolocation) return;
                e.preventDefault();
                navigator.geolocation.getCurrentPosition(function (pos) {
                    form.querySelector('.lat-input').value = pos.coords.latitude;
                    form.querySelector('.lng-input').value = pos.coords.longitude;
                    form.dataset.located = '1';
                    form.submit();
                }, function () {
                    form.dataset.located = '1';
                    form.submit();
                }, { enableHighAccuracy: true, timeout: 5000 });
            });
        });

        // ---- Job map (OpenStreetMap): pins for pickup/drop-off + a live "you" dot.
        var CETmap = null, CETbounds = [], CETme = null;
        (function () {
            var cfg = document.getElementById('map-cfg');
            var el = document.getElementById('job-map');
            if (!cfg || !el || typeof L === 'undefined') return;

            function ensureMap(lat, lng) {
                if (!CETmap) {
                    el.style.display = 'block';
                    CETmap = L.map('job-map').setView([lat, lng], 12);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap', maxZoom: 18
                    }).addTo(CETmap);
                }
            }
            function pin(address, label) {
                if (!address) return;
                fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(address),
                      { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d || !d.length) return;
                        var lat = parseFloat(d[0].lat), lng = parseFloat(d[0].lon);
                        ensureMap(lat, lng);
                        L.marker([lat, lng]).addTo(CETmap).bindPopup(label);
                        CETbounds.push([lat, lng]);
                        if (CETbounds.length > 1) CETmap.fitBounds(CETbounds, { padding: [30, 30] });
                    }).catch(function () {});
            }
            pin(cfg.dataset.pickup, 'Pickup');
            pin(cfg.dataset.dropoff, 'Drop-off');

            // Drop / move the driver's live position marker.
            window.CETshowMe = function (lat, lng) {
                ensureMap(lat, lng);
                var icon = L.divIcon({ className: '', html: '<div style="background:#1d4ed8;width:16px;height:16px;border-radius:50%;border:3px solid #fff;box-shadow:0 0 0 2px #1d4ed8"></div>', iconSize: [16, 16] });
                if (!CETme) { CETme = L.marker([lat, lng], { icon: icon, zIndexOffset: 1000 }).addTo(CETmap).bindPopup('You'); }
                else { CETme.setLatLng([lat, lng]); }
                CETmap.setView([lat, lng], 14);
            };
        })();

        // ---- GPS ping loop with visible status. Sends the driver's position now
        // and every interval while the job is active; the office sees it on the
        // Live map. Stops when the server says the job is no longer active.
        (function () {
            var cfg = document.getElementById('gps-config');
            var box = document.getElementById('track-box');
            if (!cfg || cfg.dataset.active !== '1') return;
            if (box) box.style.display = 'block';

            var dot = document.getElementById('track-dot');
            var label = document.getElementById('track-label');
            var last = document.getElementById('track-last');
            var nowBtn = document.getElementById('track-now');

            if (!navigator.geolocation) {
                if (label) label.textContent = 'This phone can’t share location.';
                if (dot) dot.textContent = '🔴';
                return;
            }
            var token = document.querySelector('meta[name="csrf-token"]').content;
            var interval = (parseInt(cfg.dataset.interval, 10) || 300) * 1000;

            function stamp() {
                var d = new Date();
                return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + ':' + ('0' + d.getSeconds()).slice(-2);
            }
            function ping() {
                navigator.geolocation.getCurrentPosition(function (pos) {
                    fetch(cfg.dataset.url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                        body: JSON.stringify({
                            lat: pos.coords.latitude, lng: pos.coords.longitude,
                            heading: pos.coords.heading, speed: pos.coords.speed, accuracy: pos.coords.accuracy
                        })
                    }).then(function (r) { return r.json(); }).then(function (data) {
                        if (dot) dot.textContent = '🟢';
                        if (label) label.textContent = 'Tracking on';
                        if (last) last.textContent = 'Location sent at ' + stamp() + ' — office can see you.';
                        if (window.CETshowMe) window.CETshowMe(pos.coords.latitude, pos.coords.longitude);
                        if (data && data.tracking === false && window._cetTimer) { clearInterval(window._cetTimer); }
                    }).catch(function () {
                        if (last) last.textContent = 'Couldn’t reach the office — will retry.';
                    });
                }, function () {
                    if (dot) dot.textContent = '🔴';
                    if (label) label.textContent = 'Location permission needed';
                    if (last) last.textContent = 'Allow location for this site, then tap “Send my location now”.';
                }, { enableHighAccuracy: true, timeout: 10000 });
            }

            if (nowBtn) nowBtn.addEventListener('click', ping);
            ping();
            window._cetTimer = setInterval(ping, interval);
        })();
    </script>
    @endverbatim
    <script src="{{ asset('js/cet-flight.js') }}"></script>
@endsection
