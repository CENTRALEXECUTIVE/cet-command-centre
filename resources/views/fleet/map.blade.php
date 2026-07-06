@extends('layouts.app')
@section('title', 'Live Map')

@section('content')
    <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
        <div>
            <h1 class="page-title" style="margin-bottom:2px">Live Map</h1>
            <p class="page-sub">Drivers currently on a job, from their latest GPS ping. Refreshes every 30s.</p>
        </div>
        <span id="fleet-count" class="muted" style="font-size:13px"></span>
    </div>

    @unless($mapsKey)
        <div class="card" style="border-left:4px solid #FBBA2A;background:rgba(251,186,42,.08)">
            Add your Google Maps key in <a href="{{ route('settings.index') }}">Settings</a> to see the map. The live list below still works.
        </div>
    @endunless

    @if($mapsKey)
        <div id="fleet-map" style="height:460px;border-radius:12px;overflow:hidden;border:1px solid var(--line);margin-bottom:16px;background:#eee"></div>
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
    @verbatim
    <script>
    (function () {
        var map = null, markers = {};

        function renderList(drivers) {
            var list = document.getElementById('fleet-list');
            var count = document.getElementById('fleet-count');
            count.textContent = drivers.length + ' driver' + (drivers.length === 1 ? '' : 's') + ' on the road';
            if (!drivers.length) { list.innerHTML = '<p class="muted mb-0">No drivers on a job right now.</p>'; return; }
            list.innerHTML = drivers.map(function (d) {
                return '<div style="display:flex;gap:12px;align-items:baseline;padding:8px 0;border-bottom:1px solid rgba(128,128,128,.12)">'
                    + '<span style="font-weight:700;min-width:70px">' + esc(d.driver) + '</span>'
                    + '<span style="flex:1">' + esc(d.customer || '—') + ' <span class="muted">→ ' + esc(d.destination || '') + '</span></span>'
                    + '<span class="badge badge-' + slug(d.status) + '">' + esc(d.status) + '</span>'
                    + '<a href="' + d.url + '" style="font-size:13px;margin-left:8px">Open →</a>'
                    + '</div>';
            }).join('');
        }
        function esc(s) { var e = document.createElement('div'); e.textContent = s == null ? '' : s; return e.innerHTML; }
        function slug(s) { return String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-'); }

        function updateMarkers(drivers) {
            if (!map || !window.google) return;
            var bounds = new google.maps.LatLngBounds(), any = false;
            var live = {};
            drivers.forEach(function (d) {
                var pos = { lat: d.lat, lng: d.lng };
                live[d.ref] = true; any = true;
                if (markers[d.ref]) { markers[d.ref].setPosition(pos); }
                else {
                    markers[d.ref] = new google.maps.Marker({
                        position: pos, map: map, title: d.driver,
                        label: { text: d.driver.substring(0, 3).toUpperCase(), color: '#0b0b0b', fontSize: '11px', fontWeight: '700' }
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
                    center: { lat: 53.3811, lng: -1.4701 }, zoom: 10, mapTypeControl: false, streetViewControl: false
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
