/*
 * Ops-room live widgets (no build step, vanilla JS):
 *  - [data-countdown]  : ticking "in 42m" countdowns on the pickup timeline
 *  - [data-ping-age]   : GPS ping ages on dispatch cards, green→amber→red
 *  - [data-countup]    : KPI numbers animate to their value on load
 *  - radar map         : muted dark Google map behind the dashboard deck,
 *                        plotting live drivers from the fleet feed
 * Respects prefers-reduced-motion (no count-up, no sweep) and works without
 * a Maps key (the radar keeps its gradient backdrop).
 */
(function () {
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* Dark "ops" styling for any Google map on the console. */
    window.CET_DARK_MAP = [
        { elementType: 'geometry', stylers: [{ color: '#0e1524' }] },
        { elementType: 'labels.text.fill', stylers: [{ color: '#67718a' }] },
        { elementType: 'labels.text.stroke', stylers: [{ color: '#0a101e' }] },
        { featureType: 'poi', stylers: [{ visibility: 'off' }] },
        { featureType: 'transit', stylers: [{ visibility: 'off' }] },
        { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#1a2334' }] },
        { featureType: 'road', elementType: 'labels', stylers: [{ visibility: 'simplified' }] },
        { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: '#232f47' }] },
        { featureType: 'water', stylers: [{ color: '#0a1220' }] },
        { featureType: 'landscape', stylers: [{ color: '#0e1524' }] },
    ];

    /* ── Countdowns ─────────────────────────────────────────────────────── */
    function tickCountdowns() {
        var now = Date.now() / 1000;
        document.querySelectorAll('[data-countdown]').forEach(function (el) {
            var ts = parseInt(el.dataset.countdown, 10);
            if (!ts) return;
            var diff = Math.round(ts - now);
            var txt, cls = '';
            if (diff <= 0) { txt = Math.abs(diff) < 90 ? 'now' : (Math.round(-diff / 60) + 'm ago'); cls = 'now'; }
            else if (diff < 3600) { txt = 'in ' + Math.max(1, Math.round(diff / 60)) + 'm'; if (diff < 900) cls = 'soon'; }
            else { txt = 'in ' + Math.floor(diff / 3600) + 'h ' + Math.round((diff % 3600) / 60) + 'm'; }
            el.textContent = txt;
            el.classList.remove('soon', 'now');
            if (cls) el.classList.add(cls);
        });
    }

    /* ── Ping ages (dispatch cards) ─────────────────────────────────────── */
    function tickPings() {
        document.querySelectorAll('[data-ping-age]').forEach(function (el) {
            var base = parseInt(el.dataset.pingAge, 10);
            if (isNaN(base)) return;
            // Elements are replaced on board refresh, so each keeps its own t0.
            if (!el.dataset.pingT0) el.dataset.pingT0 = Date.now();
            var s = base + Math.round((Date.now() - parseInt(el.dataset.pingT0, 10)) / 1000);
            el.textContent = s < 60 ? s + 's ago' : Math.floor(s / 60) + 'm ' + (s % 60) + 's ago';
            el.classList.remove('amber', 'red');
            if (s > 180) el.classList.add('red');       // matches watchdog stale rule
            else if (s > 60) el.classList.add('amber');
        });
    }

    /* ── KPI count-up ───────────────────────────────────────────────────── */
    function countUp() {
        document.querySelectorAll('[data-countup]').forEach(function (el) {
            var raw = el.dataset.countup;
            var target = parseFloat(raw);
            if (isNaN(target) || reduced) return; // reduced motion: leave the real value
            var prefix = el.dataset.prefix || '';
            var decimals = raw.indexOf('.') > -1 ? 2 : 0;
            var start = null, dur = 700;
            function step(t) {
                if (!start) start = t;
                var p = Math.min(1, (t - start) / dur);
                var eased = 1 - Math.pow(1 - p, 3);
                el.textContent = prefix + (target * eased).toLocaleString('en-GB', {
                    minimumFractionDigits: decimals, maximumFractionDigits: decimals
                });
                if (p < 1) requestAnimationFrame(step);
            }
            requestAnimationFrame(step);
        });
    }

    /* ── Radar map (dashboard backdrop) ─────────────────────────────────── */
    function radar() {
        var el = document.getElementById('radar-map');
        if (!el || !window.CET_MAPS_KEY) return;
        var s = document.createElement('script');
        s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(window.CET_MAPS_KEY) + '&v=weekly';
        s.async = true;
        s.onload = function () {
            var map = new google.maps.Map(el, {
                center: { lat: 53.3811, lng: -1.4701 }, zoom: 10,
                styles: window.CET_DARK_MAP, disableDefaultUI: true,
                gestureHandling: 'none', keyboardShortcuts: false,
            });
            function plot() {
                if (!window.CET_FLEET_URL) return;
                fetch(window.CET_FLEET_URL, { headers: { Accept: 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        (d.drivers || []).forEach(function (drv) {
                            new google.maps.Marker({
                                position: { lat: drv.lat, lng: drv.lng }, map: map,
                                icon: {
                                    path: google.maps.SymbolPath.CIRCLE, scale: 6,
                                    fillColor: drv.stale ? '#d64545' : '#FBBA2A', fillOpacity: .95,
                                    strokeColor: '#0a101e', strokeWeight: 2,
                                },
                                title: drv.driver,
                            });
                        });
                    }).catch(function () {});
            }
            plot();
        };
        s.onerror = function () {}; // gradient backdrop stays
        document.head.appendChild(s);
    }

    document.addEventListener('DOMContentLoaded', function () {
        tickCountdowns(); tickPings(); countUp(); radar();
        setInterval(tickCountdowns, 15000);
        setInterval(tickPings, 1000);
    });
})();
