<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <title>Track your car · Central Executive Transfers</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('cet.asset_version') }}">
    {{-- Leaflet + OpenStreetMap tiles — no API key required --}}
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</head>
<body>
    <header class="track-topbar">
        <span class="brand"><span class="dot"></span>CENTRAL <span class="gold">EXECUTIVE</span> TRANSFERS</span>
    </header>

    <main class="container" style="max-width:560px">
        <div class="card" style="text-align:center">
            <h1 class="page-title" id="track-message">{{ $booking->trackingMessage() }}</h1>
            <p class="page-sub">Booking {{ $booking->reference }}</p>
            <p style="font-size:48px;margin:8px 0">{{ $booking->status->isTerminal() ? '🏁' : '🚗' }}</p>
            <span class="badge badge-{{ $booking->status->value }}" id="track-badge">{{ $booking->status->label() }}</span>

            {{-- Only what the customer needs: their own pickup, the vehicle, and
                 the driver's first name. Nothing internal. --}}
            <table style="margin-top:24px;text-align:left">
                <tr><th>Pickup</th><td>{{ $booking->pickup_at->format('D d M, H:i') }}</td></tr>
                <tr><th>From</th><td>{{ $booking->displayPickupAddress() }}</td></tr>
                <tr><th>Vehicle</th><td>{{ $booking->displayVehicleType() }}</td></tr>
                <tr><th>Driver</th><td>{{ $booking->driverPublicName() ?? 'Being allocated' }}</td></tr>
            </table>

            <div id="map" style="height:300px;border-radius:10px;margin-top:20px;display:none"></div>
            <p class="hint" style="margin-top:14px" id="live-status">
                @if($booking->status->isTerminal())
                    {{ $booking->status->value === 'complete' ? 'We hope you had a great journey.' : 'No live tracking for this booking.' }}
                @else
                    A live map will appear here once your driver is on the way.
                @endif
            </p>
        </div>
        <p class="site-footer" style="border:none">Central Executive Transfers Ltd · Operator Licence {{ config('cet.company.operator_licence') }}</p>
    </main>

    <div id="track-config" data-url="{{ route('track.location', $booking->trackingLink?->token ?? '') }}" hidden></div>
    @verbatim
    <script>
        // Poll the driver's live position every 20s and plot it on the map.
        (function () {
            var cfg = document.getElementById('track-config');
            var status = document.getElementById('live-status');
            var mapEl = document.getElementById('map');
            if (!cfg || !cfg.dataset.url) return;
            var map = null, marker = null;

            function show(lat, lng, t) {
                if (typeof L === 'undefined') return;
                if (!map) {
                    mapEl.style.display = 'block';
                    map = L.map('map').setView([lat, lng], 13);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap', maxZoom: 18
                    }).addTo(map);
                    marker = L.marker([lat, lng]).addTo(map);
                } else {
                    marker.setLatLng([lat, lng]);
                    map.panTo([lat, lng]);
                }
                status.textContent = 'Driver location updated at ' + t + '.';
            }

            var timer = null;
            var msg = document.getElementById('track-message');
            var badge = document.getElementById('track-badge');

            function poll() {
                fetch(cfg.dataset.url, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data) return;
                        // Keep the journey message and status badge live.
                        if (data.message && msg) msg.textContent = data.message;
                        if (data.status_label && badge) {
                            badge.textContent = data.status_label;
                            badge.className = 'badge badge-' + data.status;
                        }
                        if (data.available) {
                            show(Number(data.latitude), Number(data.longitude),
                                 new Date(data.updated_at).toLocaleTimeString());
                        }
                        // Journey over → hide the map and stop polling.
                        if (data.finished) {
                            if (mapEl) mapEl.style.display = 'none';
                            if (status) status.textContent = data.status === 'complete'
                                ? 'We hope you had a great journey.' : 'No live tracking for this booking.';
                            if (timer) clearInterval(timer);
                        }
                    }).catch(function () {});
            }
            poll();
            timer = setInterval(poll, 20000);
        })();
    </script>
    @endverbatim
    @include('partials.cookie-consent')
</body>
</html>
