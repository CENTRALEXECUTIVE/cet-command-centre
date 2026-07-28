{{-- Shared driver job body — used by the logged-in driver page (driver.job,
     app layout) AND the public shareable link (driver.link, standalone layout).
     Route targets and the admin flag are passed in so both contexts work. --}}
@php
    $linkMode = $linkMode ?? false;
    $viewerIsAdmin = $linkMode ? false : (auth()->user()?->isAdmin() ?? false);
    $statusUrl = $statusUrl ?? route('driver.job.status', $booking);
    $locationStoreUrl = $locationStoreUrl ?? route('driver.locations.store');
@endphp

@unless($linkMode)
    <a href="{{ route('driver.jobs') }}" class="da-back">← All jobs</a>
@endunless

{{-- Modern job hero --}}
<div class="da-hero">
    <div class="da-hero-time">{{ $booking->pickup_at->format('D d M') }} · <span>{{ $booking->pickup_at->format('H:i') }}</span></div>
    <div class="da-hero-name">{{ $booking->displayName() }}@if($booking->hasChildSeat()) <span title="Child seat required">🚼</span>@endif</div>
    <div class="da-hero-route">
        <span>{{ \Illuminate\Support\Str::limit($booking->displayPickupAddress(), 34) }}</span>
        <span class="arr">→</span>
        <span>{{ \Illuminate\Support\Str::limit($booking->displayDropoffAddress(), 34) }}</span>
    </div>
    <div class="da-hero-chips">
        <span class="badge badge-{{ $booking->status->value }}">{{ $booking->status->label() }}</span>
        @if($booking->airport)<span class="da-chip">✈ {{ $booking->airport->code }}</span>@endif
        <span class="da-chip">👤 {{ $booking->passengerCount() }} {{ \Illuminate\Support\Str::plural('passenger', (int) $booking->passengerCount()) }}</span>
        <span class="da-chip">🧳 {{ $booking->luggageBreakdown() }}</span>
        @if($booking->displayChildSeats())<span class="da-chip">🚼 {{ $booking->displayChildSeats() }}</span>@endif
    </div>
</div>

@if($errors->any())
    <div class="alert alert-error">{{ $errors->first() }}</div>
@endif

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

{{-- One-tap navigation: opens Waze straight into navigation. Each address also
     has a Copy button so the driver can paste into any nav app, and a small
     Google Maps fallback for drivers who don't use Waze. --}}
<div style="margin-bottom:16px">
    <div style="display:flex;gap:8px;align-items:stretch;margin-bottom:8px">
        <a class="btn btn-dark" style="flex:1;text-align:center"
           href="https://waze.com/ul?q={{ urlencode($booking->pickup_address) }}&navigate=yes"
           target="_blank" rel="noopener">🧭 Waze to pickup</a>
        <button type="button" class="btn btn-ghost js-copy-addr" data-addr="{{ $booking->pickup_address }}"
                style="white-space:nowrap">📋 Copy</button>
    </div>
    <div style="display:flex;gap:8px;align-items:stretch;margin-bottom:6px">
        <a class="btn btn-ghost" style="flex:1;text-align:center"
           href="https://waze.com/ul?q={{ urlencode($booking->destination_address) }}&navigate=yes"
           target="_blank" rel="noopener">Waze to drop-off</a>
        <button type="button" class="btn btn-ghost js-copy-addr" data-addr="{{ $booking->destination_address }}"
                style="white-space:nowrap">📋 Copy</button>
    </div>
    <div class="hint" style="font-size:12px">
        Prefer another app?
        <a href="https://www.google.com/maps/dir/?api=1&destination={{ urlencode($booking->pickup_address) }}" target="_blank" rel="noopener">Google Maps · pickup</a> ·
        <a href="https://www.google.com/maps/dir/?api=1&destination={{ urlencode($booking->destination_address) }}" target="_blank" rel="noopener">drop-off</a>
    </div>
</div>

<script>
    document.querySelectorAll('.js-copy-addr').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var addr = btn.getAttribute('data-addr') || '';
            var done = function () {
                var old = btn.innerHTML;
                btn.innerHTML = '✓ Copied';
                setTimeout(function () { btn.innerHTML = old; }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(addr).then(done).catch(done);
            } else {
                var ta = document.createElement('textarea');
                ta.value = addr; document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(ta); done();
            }
        });
    });
</script>

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
        {{-- Child seats — KEY info for the driver (right seat fitted for the age). --}}
        @if($booking->displayChildSeats())
            <tr><th>Child seats</th><td><strong>🚼 {{ $booking->displayChildSeats() }}</strong></td></tr>
        @endif
        {{-- What to collect from the customer (cash balance). Never the fare
             or the driver's own pay — only what the passenger still owes. --}}
        @php $collect = $booking->driverCollectLine(); @endphp
        @if($collect)
            <tr><th>Collect</th><td><strong class="collect-amount">💷 {{ $collect }}</strong></td></tr>
        @endif
        {{-- The driver sees this simply as the customer's number. Whether it's the
             real number (owner-driver) or the masked switchboard line, we label it
             the same so the masking is invisible to them. --}}
        @php $contact = $booking->driverContactNumber(); @endphp
        @if($contact)
            <tr><th>Contact</th><td><a href="tel:{{ $contact }}">{{ $contact }}</a> <span class="muted" style="font-size:12px">· customer’s number</span></td></tr>
        @else
            <tr><th>Contact</th><td><span class="muted">Via the office — tap "Message the office" below</span></td></tr>
        @endif
        @if($booking->special_requests)<tr><th>Notes</th><td>{{ $booking->special_requests }}</td></tr>@endif
    </table>
</div>

@unless($linkMode)
    {{-- Office "request location": a one-off share while a request is outstanding. --}}
    @if($booking->locationRequestPending())
        <div id="locreq-banner" class="card" style="border-left:4px solid #FBBA2A;background:rgba(251,186,42,.08);text-align:center">
            📍 <strong>The office asked for your location.</strong>
            <span id="locreq-state" class="muted" style="display:block;margin-top:4px">Sharing your position…</span>
            <button type="button" id="locreq-share" class="btn btn-primary" style="padding:8px 16px;font-size:14px;margin-top:8px">Share my location</button>
        </div>
        <div id="loc-request" data-url="{{ route('driver.job.location', $booking) }}" hidden></div>
        <script src="{{ asset('js/cet-locreq.js') }}" defer></script>
    @endif
@endunless

{{-- GPS pinger config — tracking starts on the SET OFF tap and stops on
     Complete. Never runs before the driver is actually driving. --}}
<div id="gps-config"
     data-active="{{ $booking->status->isTracked() ? '1' : '0' }}"
     data-url="{{ $locationStoreUrl }}"
     data-interval="{{ (int) config('cet.gps_live_seconds', 20) }}" hidden></div>

{{-- Live tracking status so the driver can SEE it's working. --}}
<div id="track-box" class="card" style="display:none;text-align:center;border-left:4px solid #1f7a44">
    <div style="font-weight:700;font-size:15px"><span id="track-dot">🟡</span> <span id="track-label">Getting your location…</span></div>
    <div class="muted" style="font-size:13px;margin-top:2px" id="track-last">The office can see you on the Live map.</div>
    <div style="display:flex;gap:8px;justify-content:center;margin-top:10px;flex-wrap:wrap">
        <button type="button" id="track-now" class="btn btn-light" style="padding:8px 16px">📍 Send my location now</button>
        <button type="button" id="track-toggle" class="btn btn-ghost" style="padding:8px 16px">⏸ Stop sharing</button>
    </div>
    <p class="hint" style="margin:8px 0 0">Location sharing starts when you tap Set off and stops on its own when the job is completed or cancelled.</p>
</div>

{{-- Message the office on WhatsApp Business. --}}
<a href="https://wa.me/447405172435?text={{ rawurlencode('Re '.$booking->reference.' ('.$booking->displayName().'): ') }}" target="_blank" rel="noopener"
   class="btn" style="display:block;background:#25D366;color:#fff;padding:12px;font-weight:700;border-radius:10px;margin-bottom:12px;text-align:center">
    💬 Message the office
</a>

@php
    $next = $booking->status->nextStatuses();
    // Drivers can't cancel or mark no-show — only admins (the office).
    if (! $viewerIsAdmin) {
        $next = array_values(array_filter($next, fn ($s) => ! in_array($s->value, ['cancelled', 'no_show'], true)));
    }
@endphp
@if(!empty($next))
    <div class="da-actionbar">
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
            <form method="POST" action="{{ $statusUrl }}" class="status-form">
                @csrf
                <input type="hidden" name="status" value="{{ $status->value }}">
                <input type="hidden" name="lat" class="lat-input">
                <input type="hidden" name="lng" class="lng-input">
                <button type="submit" class="{{ $class }}" style="width:100%">{{ $btnLabel }}</button>
            </form>
        @endforeach
    </div>
    </div>
@else
    <div class="card"><p class="muted mb-0">This job is {{ $booking->status->label() }} — no further action.</p></div>
@endif

@verbatim
<script>
    // Capture GPS at the moment of a one-tap status change so the audit trail
    // records where the driver was. Submits with or without a fix.
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

        window.CETshowMe = function (lat, lng) {
            ensureMap(lat, lng);
            var icon = L.divIcon({ className: '', html: '<div style="background:#1d4ed8;width:16px;height:16px;border-radius:50%;border:3px solid #fff;box-shadow:0 0 0 2px #1d4ed8"></div>', iconSize: [16, 16] });
            if (!CETme) { CETme = L.marker([lat, lng], { icon: icon, zIndexOffset: 1000 }).addTo(CETmap).bindPopup('You'); }
            else { CETme.setLatLng([lat, lng]); }
            CETmap.setView([lat, lng], 14);
        };
    })();
</script>
@endverbatim
<script src="{{ asset('js/cet-flight.js') }}"></script>
<script src="{{ asset('js/cet-track.js') }}"></script>
