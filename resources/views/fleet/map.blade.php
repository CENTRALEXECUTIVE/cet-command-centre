@extends('layouts.app')
@section('title', 'Live Map')

@section('content')
    <div class="form-hero fleet-hero">
        <div class="form-hero-glow"></div>
        <div style="position:relative">
            <div class="fh-eyebrow">Fleet · live positions</div>
            <div class="fh-title">Live Map</div>
            <div class="fh-sub">Drivers currently on a job, from their latest GPS ping. Refreshes every 30s.</div>
        </div>
        <div class="fleet-live"><span class="pulse"></span><span id="fleet-count">0 on the road</span></div>
    </div>

    @unless($mapsKey)
        <div class="card" style="border-left:4px solid #FBBA2A;background:rgba(251,186,42,.08)">
            Add your Google Maps key in <a href="{{ route('settings.index') }}">Settings</a> to see the map. The live list below still works.
        </div>
    @endunless

    @if($mapsKey)
        <div id="fleet-map"></div>
    @endif

    <div class="card">
        <h2 style="margin:0 0 10px">On the road</h2>
        <div id="fleet-list">
            @if(empty($drivers))
                <p class="muted mb-0">No drivers on a job right now.</p>
            @endif
        </div>
    </div>

    <script>
        window.CET_FLEET_URL = "{{ route('fleet.positions') }}";
        window.CET_FLEET = @json($drivers);
        window.CET_MAPS_KEY = "{{ $mapsKey }}";
    </script>
    <script src="{{ asset('js/cet-ops.js') }}?v=1"></script>
    @verbatim
    <script>
    // Google calls this on an auth/API failure — turn its generic red error into
    // an actionable message.
    window.gm_authFailure = function () {
        var el = document.getElementById('fleet-map');
        if (el) el.innerHTML = '<div style="padding:28px;text-align:center;font-size:14px;color:#b32020">'
            + 'The map needs the <strong>Maps JavaScript API</strong> enabled on your Google key.<br>'
            + 'Google Cloud → APIs &amp; Services → Library → enable <strong>Maps JavaScript API</strong>, '
            + 'then add it to the key’s API restrictions. The list below still works meanwhile.</div>';
    };
    (function () {
        var map = null, markers = {};

        function renderList(drivers) {
            var list = document.getElementById('fleet-list');
            var count = document.getElementById('fleet-count');
            count.textContent = drivers.length + ' on the road';
            if (!drivers.length) { list.innerHTML = '<p class="muted mb-0">No drivers on a job right now.</p>'; return; }
            list.innerHTML = drivers.map(function (d) {
                // Stale GPS (no ping for a while) is highlighted so the office
                // knows the position shown may be out of date.
                var ping = d.stale
                    ? '<div class="ping stale">⚠ GPS stale · ' + esc(d.ago || '') + '</div>'
                    : '<div class="ping ok">📍 ' + esc(d.ago || 'just now') + '</div>';
                var initials = String(d.driver || '?').substring(0, 3).toUpperCase();
                return '<div class="fleet-row' + (d.stale ? ' stale' : '') + '">'
                    + '<div class="fleet-avatar">' + esc(initials) + '</div>'
                    + '<div class="fleet-main">'
                    +   '<div class="who">' + esc(d.driver) + '</div>'
                    +   '<div class="route">' + esc(d.customer || '—') + ' → ' + esc(d.destination || '') + '</div>'
                    +   ping
                    + '</div>'
                    + '<span class="badge badge-' + slug(d.status) + '">' + esc(d.status) + '</span>'
                    + '<a href="' + d.url + '" style="font-size:13px;margin-left:6px">Open →</a>'
                    + '</div>';
            }).join('');
        }
        function esc(s) { var e = document.createElement('div'); e.textContent = s == null ? '' : s; return e.innerHTML; }
        function slug(s) { return String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-'); }

        // Marker icon: an arrow pointing the way the car is heading when the
        // device reports one, else a dot. Gold while fresh, faded red once stale.
        function markerIcon(d) {
            var hasHeading = d.heading !== null && d.heading !== undefined && !isNaN(d.heading);
            return {
                path: hasHeading ? google.maps.SymbolPath.FORWARD_CLOSED_ARROW : google.maps.SymbolPath.CIRCLE,
                rotation: hasHeading ? Number(d.heading) : 0,
                scale: hasHeading ? 6 : 8,
                fillColor: d.stale ? '#d64545' : '#FBBA2A',
                fillOpacity: d.stale ? 0.55 : 0.95,
                strokeColor: '#0a101e',
                strokeWeight: 2,
            };
        }

        function updateMarkers(drivers) {
            if (!map || !window.google) return;
            var bounds = new google.maps.LatLngBounds(), any = false;
            var live = {};
            drivers.forEach(function (d) {
                var pos = { lat: d.lat, lng: d.lng };
                live[d.ref] = true; any = true;
                if (markers[d.ref]) {
                    markers[d.ref].setPosition(pos);
                    markers[d.ref].setOpacity(d.stale ? 0.45 : 1);
                    markers[d.ref].setIcon(markerIcon(d));
                }
                else {
                    markers[d.ref] = new google.maps.Marker({
                        position: pos, map: map, title: d.driver + (d.stale ? ' (GPS stale)' : ''),
                        opacity: d.stale ? 0.45 : 1,
                        icon: markerIcon(d),
                        label: { text: d.driver.substring(0, 3).toUpperCase(), color: '#e8ecf4', fontSize: '11px', fontWeight: '700', className: 'fleet-mlabel' }
                    });
                    var info = new google.maps.InfoWindow({
                        content: '<strong>' + esc(d.driver) + '</strong><br>' + esc(d.customer || '') + '<br>→ ' + esc(d.destination || '')
                            + '<br><span style="color:#888">' + esc(d.status) + ' · ' + esc(d.ago || '') + '</span>'
                    });
                    markers[d.ref].addListener('click', function () { info.open(map, markers[d.ref]); });
                }
                bounds.extend(pos);
            });
            // Drop markers that are no longer active.
            Object.keys(markers).forEach(function (ref) {
                if (!live[ref]) { markers[ref].setMap(null); delete markers[ref]; }
            });
            if (any && Object.keys(markers).length) { map.fitBounds(bounds); if (map.getZoom() > 14) map.setZoom(14); }
        }

        function refresh() {
            fetch(window.CET_FLEET_URL, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { renderList(d.drivers); updateMarkers(d.drivers); })
                .catch(function () {});
        }

        function initMap() {
            if (!window.CET_MAPS_KEY) { renderList(window.CET_FLEET || []); return; }
            var s = document.createElement('script');
            s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(window.CET_MAPS_KEY) + '&v=weekly';
            s.async = true;
            s.onload = function () {
                map = new google.maps.Map(document.getElementById('fleet-map'), {
                    center: { lat: 53.3811, lng: -1.4701 }, zoom: 10, mapTypeControl: false, streetViewControl: false,
                    styles: window.CET_DARK_MAP || undefined
                });
                updateMarkers(window.CET_FLEET || []);
            };
            s.onerror = function () {}; // list still works
            document.head.appendChild(s);
        }

        renderList(window.CET_FLEET || []);
        initMap();
        setInterval(refresh, 30000);
    })();
    </script>
    @endverbatim
@endsection
