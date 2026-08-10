{{-- Shared driver job body — used by the logged-in driver page (driver.job,
     app layout) AND the public shareable link (driver.link, standalone layout).
     Route targets and the admin flag are passed in so both contexts work. --}}
@php
    $linkMode = $linkMode ?? false;
    $viewerIsAdmin = $linkMode ? false : (auth()->user()?->isAdmin() ?? false);
    $statusUrl = $statusUrl ?? route('driver.job.status', $booking);
    $locationStoreUrl = $locationStoreUrl ?? route('driver.locations.store');
    $stopUrl = $stopUrl ?? route('driver.job.reach-stop', $booking);
    $ackCashUrl = $ackCashUrl ?? route('driver.job.ack-cash', $booking);

    // Multi-stop journeys (a "via" between pickup and drop-off).
    $viaStops = $booking->viaStops();
    $stopsReached = $booking->stopsReached();
    $onBoard = $booking->status === \App\Enums\BookingStatus::Collected;
    $nextStop = $onBoard ? $booking->nextViaStop() : null;
    // Drop-off navigation only appears once the passenger is on board and every
    // stop has been reached — keeps the page clean until it's actually needed.
    $showDropoff = $onBoard && $booking->allViaStopsReached();
@endphp

@unless($linkMode)
    <a href="{{ route('driver.jobs') }}" class="da-back">← All jobs</a>
@endunless

{{-- Modern job hero --}}
<div class="da-hero">
    <div class="da-hero-time">{{ $booking->pickup_at->format('D d M') }} · <span>{{ $booking->pickup_at->format('H:i') }}</span></div>
    <div class="da-hero-name">{{ $booking->displayName() }}@if($booking->hasChildSeat()) <span title="Child seat required">🚼</span>@endif</div>
    <div class="da-hero-route">
        <span class="da-addr">📍 {{ $booking->displayPickupAddress() }}</span>
        @foreach($viaStops as $i => $stop)
            <span class="da-addr" style="{{ $i < $stopsReached ? 'opacity:.55' : '' }}">🔀 Stop {{ $i + 1 }}: {{ $stop }}{{ $i < $stopsReached ? ' ✓ done' : '' }}</span>
        @endforeach
        <span class="da-addr">🏁 {{ $booking->displayDropoffAddress() }}</span>
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

{{-- Location permission gate. A browser only shows the "Allow location" prompt
     over HTTPS and — on iPhone especially — in response to a TAP, not on page
     load. So we ask explicitly: this shows until permission is granted and the
     button triggers the native prompt on tap. Location is required for tracking,
     navigation and waiting time. Hidden for admins (they aren't the driver). --}}
@unless($viewerIsAdmin)
    <div id="loc-gate" class="card" style="display:none;border-left:4px solid #FBBA2A;background:rgba(251,186,42,.12)">
        <div style="font-weight:800;font-size:15px">📍 Turn on location for this job</div>
        <p class="hint" id="loc-gate-msg" style="margin:6px 0 10px">Central Executive needs your location for tracking, navigation and your waiting time. Tap below and choose <strong>Allow</strong>.</p>
        <button type="button" id="loc-gate-btn" class="btn btn-primary" style="padding:9px 18px;font-size:15px">Allow location</button>
    </div>
    <script>
    (function () {
        var gate = document.getElementById('loc-gate');
        if (!gate) { return; }
        var btn = document.getElementById('loc-gate-btn');
        var msg = document.getElementById('loc-gate-msg');
        if (!('geolocation' in navigator)) {
            gate.style.display = 'block';
            msg.textContent = 'This browser can’t share location. Open the link in Safari or Chrome.';
            if (btn) { btn.style.display = 'none'; }
            return;
        }
        function show() { gate.style.display = 'block'; }
        function granted() {
            gate.style.display = 'none';
            // If live tracking is active on this page, make sure it's running.
            if (window.CETstart) { window.CETstart(); }
        }
        function blocked() {
            show();
            msg.innerHTML = 'Location looks blocked for this site. Open your browser’s settings for this page, set <strong>Location</strong> to <strong>Allow</strong>, then reload.';
            if (btn) { btn.textContent = 'Try again'; }
        }
        function ask() {
            navigator.geolocation.getCurrentPosition(function (pos) {
                // Publish a fix straight away so the waiting timer sees location.
                window.CETgps = { pos: { lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy }, ok: true, sharing: true, at: Date.now() };
                granted();
            }, function (err) {
                if (err && err.code === 1) { blocked(); } // PERMISSION_DENIED
                else { show(); }                           // transient — allow a retry
            }, { enableHighAccuracy: true, timeout: 10000 });
        }
        if (btn) { btn.addEventListener('click', ask); }

        // Use the Permissions API where available to set the right initial state
        // without nagging a driver who has already allowed it.
        if (navigator.permissions && navigator.permissions.query) {
            navigator.permissions.query({ name: 'geolocation' }).then(function (st) {
                if (st.state === 'granted') { granted(); }
                else if (st.state === 'denied') { blocked(); }
                else { show(); }
                st.onchange = function () {
                    if (st.state === 'granted') { granted(); }
                    else if (st.state === 'denied') { blocked(); }
                };
            }).catch(show);
        } else {
            // Older iOS Safari has no Permissions API — show the invite; the tap prompts.
            show();
        }
    })();
    </script>
@endunless

{{-- Waiting time. Once the driver is AT the pickup with location sharing on,
     the customer gets a free grace period (15 min); the billable timer only
     starts counting after it. It will NOT run unless GPS is live and the driver
     is actually at the pickup — tapping "Arrived" from home doesn't start it.
     Location is required: if it's off, the card prompts for it. No messages are
     sent and no money is shown — it's purely a time display for the driver. --}}
@if($booking->status === \App\Enums\BookingStatus::Arrived && $booking->arrivedAt())
    @php $wpc = $booking->pickupCoords(); @endphp
    <div id="waiting-timer" class="card"
         data-grace="{{ $booking->waitingGraceMinutes() * 60 }}"
         data-now="{{ now()->timestamp }}"
         data-started="{{ $booking->waitingStartedAt()?->timestamp }}"
         data-plat="{{ $wpc[0] ?? '' }}"
         data-plng="{{ $wpc[1] ?? '' }}"
         data-radius="{{ \App\Models\Booking::WAITING_GEOFENCE_M }}"
         style="text-align:center;border-left:4px solid #FBBA2A;background:rgba(251,186,42,.10)">
        <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.06em">Waiting at pickup</div>
        <div id="waiting-main" style="font-size:30px;font-weight:800;font-variant-numeric:tabular-nums;margin:4px 0;line-height:1.1">—</div>
        <div id="waiting-sub" class="muted" style="font-size:13px">First {{ $booking->waitingGraceMinutes() }} minutes are free.</div>
        <button type="button" id="waiting-loc" class="btn btn-primary" style="display:none;padding:8px 16px;font-size:14px;margin-top:8px">📍 Turn on location</button>
    </div>
    <script>
    (function () {
        var el = document.getElementById('waiting-timer');
        if (!el) { return; }
        var grace = parseInt(el.dataset.grace, 10);
        var serverNow = parseInt(el.dataset.now, 10);
        var startedSrv = el.dataset.started ? parseInt(el.dataset.started, 10) : null;
        var plat = el.dataset.plat !== '' ? parseFloat(el.dataset.plat) : null;
        var plng = el.dataset.plng !== '' ? parseFloat(el.dataset.plng) : null;
        var radius = parseInt(el.dataset.radius, 10) || 200;
        // Trust the server clock, not the phone's: offset corrects a wrong device time.
        var offset = serverNow - Math.floor(Date.now() / 1000);
        var main = document.getElementById('waiting-main');
        var sub = document.getElementById('waiting-sub');
        var locBtn = document.getElementById('waiting-loc');
        var graceMin = Math.round(grace / 60);
        var clientStart = null; // client-observed at-pickup moment (until the server firms it up)

        if (locBtn) {
            locBtn.addEventListener('click', function () {
                if (window.CETstart) { window.CETstart(); }
                else if (window.CETsendNow) { window.CETsendNow(); }
            });
        }
        function fmt(s) {
            s = Math.max(0, Math.floor(s));
            var m = Math.floor(s / 60), r = s % 60;
            return m + ':' + (r < 10 ? '0' : '') + r;
        }
        function serverEpoch() { return Math.floor(Date.now() / 1000) + offset; }
        function meters(aLat, aLng, bLat, bLng) {
            var R = 6371000, p = Math.PI / 180;
            var dLat = (bLat - aLat) * p, dLng = (bLng - aLng) * p;
            var x = Math.sin(dLat / 2) * Math.sin(dLat / 2)
                + Math.cos(aLat * p) * Math.cos(bLat * p) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
            return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
        }
        function paint(border, bg, colour) { el.style.borderLeftColor = border; el.style.background = bg; main.style.color = colour; }
        function showBtn(show) { if (locBtn) { locBtn.style.display = show ? 'inline-block' : 'none'; } }

        function runTimer(startEpoch) {
            showBtn(false);
            var elapsed = serverEpoch() - startEpoch;
            if (elapsed < grace) {
                paint('#1f7a44', 'rgba(31,122,68,.08)', '#1f7a44');
                main.textContent = fmt(grace - elapsed) + ' left';
                sub.textContent = 'Free waiting — the timer starts after this.';
            } else {
                paint('#c0392b', 'rgba(192,57,43,.09)', '#c0392b');
                main.textContent = '⏱ ' + fmt(elapsed - grace);
                sub.textContent = 'Waiting time (after the free ' + graceMin + ' min).';
            }
        }

        function tick() {
            // Server already confirmed the driver at the pickup → authoritative,
            // no need to re-check GPS on the client.
            if (startedSrv) { runTimer(startedSrv); return; }

            var gps = window.CETgps || null;
            var fresh = gps && gps.at && (Date.now() - gps.at < 60000);
            var active = gps && gps.sharing && gps.ok && fresh && gps.pos;

            if (!active) {
                paint('#FBBA2A', 'rgba(251,186,42,.12)', '#8a6d00');
                main.textContent = '📍 Location needed';
                sub.textContent = 'Turn on location sharing — your waiting time needs it.';
                showBtn(true);
                clientStart = null;
                return;
            }

            // Location is live. Are we actually at the pickup?
            var atPickup = true;
            if (plat !== null && plng !== null) {
                atPickup = meters(gps.pos.lat, gps.pos.lng, plat, plng) <= radius;
            }
            if (!atPickup) {
                paint('#FBBA2A', 'rgba(251,186,42,.10)', '#8a6d00');
                main.textContent = 'Not at pickup yet';
                sub.textContent = 'Your waiting time starts once you reach the pickup.';
                showBtn(false);
                clientStart = null;
                return;
            }

            // At the pickup and sharing live — start the clock. Anchor to the
            // first moment we saw it; the server firms this up within a ping or
            // two and takes over (data-started) on the next load.
            if (clientStart === null) { clientStart = serverEpoch(); }
            runTimer(clientStart);
        }

        tick();
        setInterval(tick, 1000);
    })();
    </script>
@endif

{{-- Cash-collection reminder. On a cash job the driver is reminded to collect
     the cash and taps OK to acknowledge. The amount is whatever's actually due
     on this booking (not a fixed figure) and re-prompts if it changes. --}}
@if($booking->hasCashToCollect())
    @if($booking->cashCollectAcknowledged())
        <div class="card" style="border-left:4px solid #1f7a44;background:rgba(31,122,68,.08);margin-bottom:16px">
            <div style="font-weight:700;font-size:14px">✓ Cash to collect — confirmed</div>
            <div class="muted" style="font-size:13px;margin-top:2px">Collect <strong>{{ $booking->cashToCollectDisplay() }}</strong> in cash from the customer.</div>
        </div>
    @else
        <div class="card" style="border-left:4px solid #b8860b;background:rgba(251,186,42,.16);margin-bottom:16px">
            <div style="font-weight:800;font-size:16px">💷 Collect the cash</div>
            <p style="margin:6px 0 10px;font-size:15px">This is a <strong>cash job</strong> — please collect <strong>{{ $booking->cashToCollectDisplay() }}</strong> from the customer.</p>
            <form method="POST" action="{{ $ackCashUrl }}">
                @csrf
                <button type="submit" class="btn btn-primary" style="width:100%;padding:11px;font-size:15px">OK — I’ll collect it</button>
            </form>
        </div>
    @endif
@endif

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
    @foreach($viaStops as $i => $stop)
        <div style="display:flex;gap:8px;align-items:stretch;margin-bottom:6px">
            <a class="btn btn-ghost" style="flex:1;text-align:center;{{ $i < $stopsReached ? 'opacity:.55' : '' }}"
               href="https://waze.com/ul?q={{ urlencode($stop) }}&navigate=yes"
               target="_blank" rel="noopener">🔀 Waze to stop {{ $i + 1 }}{{ $i < $stopsReached ? ' ✓' : '' }}</a>
            <button type="button" class="btn btn-ghost js-copy-addr" data-addr="{{ $stop }}"
                    style="white-space:nowrap">📋 Copy</button>
        </div>
    @endforeach
    @if($showDropoff)
        <div style="display:flex;gap:8px;align-items:stretch;margin-bottom:6px">
            <a class="btn btn-ghost" style="flex:1;text-align:center"
               href="https://waze.com/ul?q={{ urlencode($booking->destination_address) }}&navigate=yes"
               target="_blank" rel="noopener">🏁 Waze to drop-off</a>
            <button type="button" class="btn btn-ghost js-copy-addr" data-addr="{{ $booking->destination_address }}"
                    style="white-space:nowrap">📋 Copy</button>
        </div>
    @endif
    <div class="hint" style="font-size:12px">
        Prefer another app?
        <a href="https://www.google.com/maps/dir/?api=1&destination={{ urlencode($booking->pickup_address) }}" target="_blank" rel="noopener">Google Maps · pickup</a>@if($showDropoff) ·
        <a href="https://www.google.com/maps/dir/?api=1&destination={{ urlencode($booking->destination_address) }}" target="_blank" rel="noopener">drop-off</a>@endif
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

<div class="card">
    <table>
        <tr><th>Pickup</th><td>{{ $booking->pickup_at->format('D d M, H:i') }}</td></tr>
        <tr><th>From</th><td>{{ $booking->displayPickupAddress() }}</td></tr>
        @foreach($viaStops as $i => $stop)
            <tr><th>Stop {{ $i + 1 }}</th><td>{{ $stop }}{!! $i < $stopsReached ? ' <span style="color:#1f7a44;font-weight:700">✓ reached</span>' : '' !!}</td></tr>
        @endforeach
        <tr><th>To</th><td>{{ $booking->displayDropoffAddress() }}</td></tr>
        @if($booking->displayFlightNumber())
            <tr><th>Flight</th><td>
                <span class="mono">{{ $booking->displayFlightNumber() }}</span>
                <a href="{{ $booking->flightRadarUrl() }}" data-flightradar target="_blank" rel="noopener" class="btn" style="background:#fc3d02;color:#fff;padding:4px 12px;font-size:13px;margin-left:6px">✈ Track flight</a>
                {{-- Google flight status — always resolves, even the regional /
                     codeshare numbers Flightradar24 sometimes can't find. --}}
                <a href="{{ $booking->flightSearchUrl() }}" target="_blank" rel="noopener" class="btn btn-ghost" style="padding:4px 12px;font-size:13px;margin-left:6px">Live status</a>
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
    // The passenger is on board but there's still a via stop to reach — the job
    // isn't finished, so guide the driver to the next stop instead of offering
    // Complete straight away.
    $awaitingStop = $onBoard && $nextStop !== null;
@endphp

@if($awaitingStop)
    {{-- Multi-stop: travel to the next stop, then tap through it. --}}
    <div class="card" style="border-left:4px solid #7a45e0;background:rgba(122,69,224,.08);margin-bottom:12px">
        <div style="font-weight:800;font-size:15px">🔀 Travel to the next stop — {{ $stopsReached + 1 }} of {{ count($viaStops) }}</div>
        <div style="margin:6px 0 10px;font-size:15px">{{ $nextStop }}</div>
        <a class="btn btn-dark" style="display:block;text-align:center"
           href="https://waze.com/ul?q={{ urlencode($nextStop) }}&navigate=yes" target="_blank" rel="noopener">🧭 Waze to this stop</a>
    </div>
    <div class="da-actionbar">
    <div class="tap-actions">
        <form method="POST" action="{{ $stopUrl }}">
            @csrf
            <button type="submit" class="tap-collect" style="width:100%">Reached stop {{ $stopsReached + 1 }} — Passenger On Board</button>
        </form>
    </div>
    </div>
@elseif(!empty($next))
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
                    'complete' => count($viaStops) ? 'Completed (final drop-off)' : 'Completed',
                    default => $status->label(),
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
</script>
@endverbatim
<script src="{{ asset('js/cet-flight.js') }}"></script>
<script src="{{ asset('js/cet-track.js') }}"></script>
