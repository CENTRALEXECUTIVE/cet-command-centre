<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Track your car · Central Executive Transfers</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <header class="topbar">
        <span class="brand"><span class="dot"></span>CENTRAL <span class="gold">EXECUTIVE</span> TRANSFERS</span>
    </header>

    <main class="container" style="max-width:560px">
        <div class="card" style="text-align:center">
            <h1 class="page-title">Your driver is on the way</h1>
            <p class="page-sub">Booking {{ $booking->reference }}</p>
            <p style="font-size:48px;margin:8px 0">🚗</p>
            <span class="badge badge-{{ $booking->status->value }}">{{ $booking->status->label() }}</span>

            <table style="margin-top:24px;text-align:left">
                <tr><th>Pickup</th><td>{{ $booking->pickup_at->format('D d M, H:i') }}</td></tr>
                <tr><th>From</th><td>{{ $booking->pickup_address }}</td></tr>
                <tr><th>Vehicle</th><td>{{ $booking->vehicleType?->name }}</td></tr>
                <tr><th>Driver</th><td>{{ $booking->driver?->name ?? 'Allocated' }}</td></tr>
            </table>

            <p class="hint" style="margin-top:20px" id="live-status">A live map will appear here as your driver approaches.</p>
        </div>
        <p class="site-footer" style="border:none">Central Executive Transfers Ltd · Operator Licence {{ config('cet.company.operator_licence') }}</p>
    </main>

    <div id="track-config" data-url="{{ route('track.location', $booking->trackingLink?->token ?? '') }}" hidden></div>
    @verbatim
    <script>
        // Poll the driver's live position every 20 seconds.
        (function () {
            var cfg = document.getElementById('track-config');
            var status = document.getElementById('live-status');
            if (!cfg || !cfg.dataset.url) return;

            function poll() {
                fetch(cfg.dataset.url, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data && data.available) {
                            var t = new Date(data.updated_at).toLocaleTimeString();
                            status.textContent = 'Driver location updated at ' + t +
                                ' (' + Number(data.latitude).toFixed(4) + ', ' + Number(data.longitude).toFixed(4) + ').';
                        }
                    }).catch(function () {});
            }
            poll();
            setInterval(poll, 20000);
        })();
    </script>
    @endverbatim
</body>
</html>
