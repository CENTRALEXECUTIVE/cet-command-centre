/**
 * CET booking/quote form helpers.
 *
 * Address autocomplete: uses Google's own client-side Places (New) API when a
 * Maps key is present (window.CET_MAPS_KEY) — the exact "powered by Google"
 * experience — and falls back to the server-side proxy (window.CET_PLACES_URL)
 * otherwise. The suggestion menu is anchored to <body> with fixed positioning
 * so it can never be clipped by a parent's overflow.
 *
 * Also: live fare estimates (fixed airport price / free roam) via
 * window.CET_ESTIMATE_URL.
 */
(function () {
    var googleReady = false;

    function loadGoogle(key) {
        return new Promise(function (resolve, reject) {
            if (window.google && google.maps && google.maps.importLibrary) { resolve(); return; }
            var s = document.createElement('script');
            s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) + '&v=weekly&loading=async&libraries=places';
            s.async = true; s.onload = resolve; s.onerror = reject;
            document.head.appendChild(s);
        }).then(function () { return google.maps.importLibrary('places'); }).then(function () { googleReady = true; });
    }

    // Returns a Promise of an array of address strings for the query.
    function suggest(query) {
        if (googleReady) {
            return google.maps.importLibrary('places').then(function (places) {
                if (!window._cetToken) window._cetToken = new places.AutocompleteSessionToken();
                return places.AutocompleteSuggestion.fetchAutocompleteSuggestions({
                    input: query, includedRegionCodes: ['gb'], sessionToken: window._cetToken
                }).then(function (res) {
                    return (res.suggestions || []).map(function (s) {
                        return s.placePrediction && s.placePrediction.text ? s.placePrediction.text.text : null;
                    }).filter(Boolean);
                });
            }).catch(function () { return proxySuggest(query); });
        }
        return proxySuggest(query);
    }

    function proxySuggest(query) {
        if (!window.CET_PLACES_URL) return Promise.resolve([]);
        return fetch(window.CET_PLACES_URL + '?q=' + encodeURIComponent(query), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { return d.suggestions || []; })
            .catch(function () { return []; });
    }

    function attachPlaces(el) {
        if (!el || el.dataset.placesReady) return;
        el.dataset.placesReady = '1';

        var menu = document.createElement('div');
        menu.style.cssText = 'position:fixed;z-index:9999;background:#fff;color:#111;border:1px solid rgba(0,0,0,.2);border-radius:8px;max-height:280px;overflow:auto;display:none;box-shadow:0 8px 24px rgba(0,0,0,.18)';
        document.body.appendChild(menu);
        var timer = null, seq = 0;

        function place() {
            var r = el.getBoundingClientRect();
            menu.style.left = r.left + 'px';
            menu.style.top = (r.bottom + 2) + 'px';
            menu.style.width = r.width + 'px';
        }
        function close() { menu.style.display = 'none'; menu.innerHTML = ''; }
        function render(items) {
            menu.innerHTML = '';
            if (!items.length) { close(); return; }
            items.forEach(function (text) {
                var opt = document.createElement('div');
                opt.textContent = text;
                opt.style.cssText = 'padding:10px 12px;cursor:pointer;font-size:14px;border-bottom:1px solid rgba(0,0,0,.08)';
                opt.addEventListener('mousedown', function (e) { e.preventDefault(); el.value = text; close(); el.dispatchEvent(new Event('change')); });
                opt.addEventListener('mouseenter', function () { opt.style.background = 'rgba(251,186,42,.22)'; });
                opt.addEventListener('mouseleave', function () { opt.style.background = ''; });
                menu.appendChild(opt);
            });
            var foot = document.createElement('div');
            foot.textContent = 'powered by Google';
            foot.style.cssText = 'padding:6px 12px;font-size:11px;color:#888;text-align:right';
            menu.appendChild(foot);
            place();
            menu.style.display = 'block';
        }
        el.addEventListener('input', function () {
            var q = el.value.trim();
            clearTimeout(timer);
            if (q.length < 3) { close(); return; }
            var mine = ++seq;
            timer = setTimeout(function () {
                suggest(q).then(function (items) { if (mine === seq) render(items); });
            }, 200);
        });
        el.addEventListener('blur', function () { setTimeout(close, 200); });
        el.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        window.addEventListener('scroll', function () { if (menu.style.display === 'block') place(); }, true);
        window.addEventListener('resize', function () { if (menu.style.display === 'block') place(); });
    }

    function initAutoQuote() {
        var pickup = document.getElementById('pickup_address');
        var dest = document.getElementById('destination_address');
        var veh = document.getElementById('vehicle_type_id');
        var price = document.getElementById('quoted_price');
        var note = document.getElementById('quote-note');
        if (!pickup || !dest || !veh || !window.CET_ESTIMATE_URL) return;
        var timer = null, edited = false;
        if (price) price.addEventListener('input', function () { edited = true; });
        function refresh() {
            var p = pickup.value.trim(), d = dest.value.trim(), v = veh.value;
            if (!p || !d || !v) return;
            clearTimeout(timer);
            timer = setTimeout(function () {
                if (note) note.textContent = '· calculating…';
                var url = window.CET_ESTIMATE_URL + '?pickup=' + encodeURIComponent(p) + '&destination=' + encodeURIComponent(d) + '&vehicle_type_id=' + encodeURIComponent(v);
                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (q) {
                        if (q.price == null) { if (note) note.textContent = '· ' + (q.basis || 'price on request'); return; }
                        if (note) note.textContent = '· ' + q.basis + ' — £' + Number(q.price).toFixed(2);
                        if (price && (!edited || price.value === '')) { price.value = Number(q.price).toFixed(2); edited = false; }
                    })
                    .catch(function () { if (note) note.textContent = ''; });
            }, 400);
        }
        [pickup, dest, veh].forEach(function (el) { el.addEventListener('change', refresh); el.addEventListener('blur', refresh); });
    }

    function init() {
        document.querySelectorAll('[data-places]').forEach(attachPlaces);
        initAutoQuote();
        window.CETattachPlaces = attachPlaces;
        // Upgrade to Google's own client-side autocomplete when a key is present.
        if (window.CET_MAPS_KEY) { loadGoogle(window.CET_MAPS_KEY).catch(function () { googleReady = false; }); }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
