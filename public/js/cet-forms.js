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

    // Precise-address place types — house/street/postcode, no businesses.
    var ADDRESS_TYPES = ['street_address', 'premise', 'subpremise', 'route', 'postal_code'];

    // Returns a Promise of an array of address strings for the query. When
    // types === 'address' the results are biased to precise addresses so typing a
    // house number lists the exact addresses to choose from.
    function suggest(query, types) {
        var wantAddresses = types === 'address';
        if (googleReady) {
            return google.maps.importLibrary('places').then(function (places) {
                if (!window._cetToken) window._cetToken = new places.AutocompleteSessionToken();
                var req = { input: query, includedRegionCodes: ['gb'], sessionToken: window._cetToken };
                if (wantAddresses) req.includedPrimaryTypes = ADDRESS_TYPES;
                return places.AutocompleteSuggestion.fetchAutocompleteSuggestions(req).then(function (res) {
                    var out = (res.suggestions || []).map(function (s) {
                        return s.placePrediction && s.placePrediction.text ? s.placePrediction.text.text : null;
                    }).filter(Boolean);
                    // Fall back to an unrestricted search rather than showing nothing.
                    if (!out.length && wantAddresses) return suggestGoogle(places, query, false);
                    return out;
                });
            }).catch(function () { return proxySuggest(query, types); });
        }
        return proxySuggest(query, types);
    }

    function suggestGoogle(places, query, wantAddresses) {
        var req = { input: query, includedRegionCodes: ['gb'], sessionToken: window._cetToken };
        if (wantAddresses) req.includedPrimaryTypes = ADDRESS_TYPES;
        return places.AutocompleteSuggestion.fetchAutocompleteSuggestions(req).then(function (res) {
            return (res.suggestions || []).map(function (s) {
                return s.placePrediction && s.placePrediction.text ? s.placePrediction.text.text : null;
            }).filter(Boolean);
        });
    }

    function proxySuggest(query, types) {
        if (!window.CET_PLACES_URL) return Promise.resolve([]);
        var url = window.CET_PLACES_URL + '?q=' + encodeURIComponent(query)
            + (types ? '&types=' + encodeURIComponent(types) : '');
        return fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { return d.suggestions || []; })
            .catch(function () { return []; });
    }

    // Pull a UK postcode out of a Google suggestion like
    // "Harney Close, Darnall, Sheffield S9 5BW, UK".
    function extractPostcode(text) {
        var m = String(text || '').toUpperCase().match(/([A-Z]{1,2}[0-9][A-Z0-9]? ?[0-9][A-Z]{2})/);
        return m ? m[1].replace(/\s+/g, ' ').trim() : '';
    }

    // If the WHOLE value is a complete UK postcode, return it normalised
    // ("S95BW" → "S9 5BW"); otherwise ''. Used to switch to the full PAF list.
    function fullPostcode(text) {
        var pc = String(text || '').toUpperCase().replace(/\s+/g, '');
        if (!/^[A-Z]{1,2}[0-9][A-Z0-9]?[0-9][A-Z]{2}$/.test(pc)) return '';
        return pc.slice(0, -3) + ' ' + pc.slice(-3);
    }

    // Fetch every address at a postcode (Royal Mail PAF via getAddress.io). Falls
    // back to Google's address type-ahead when PAF isn't set up or finds nothing.
    function postcodeAddresses(query) {
        var pc = fullPostcode(query);
        if (pc && window.CET_ADDRESSES_URL) {
            return fetch(window.CET_ADDRESSES_URL + '?postcode=' + encodeURIComponent(pc), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.addresses && d.addresses.length) return d.addresses;
                    return suggest(query, 'address');
                })
                .catch(function () { return suggest(query, 'address'); });
        }
        return suggest(query, 'address');
    }

    /**
     * attachPlaces(el, opts)
     *   opts.minLen  minimum characters before suggesting (default 3)
     *   opts.onPick(text)  what to do when a suggestion is chosen
     *                      (default: set el.value = text and fire change)
     */
    function attachPlaces(el, opts) {
        if (!el || el.dataset.placesReady) return;
        el.dataset.placesReady = '1';
        opts = opts || {};
        var minLen = opts.minLen || 3;
        var types = opts.types || el.dataset.placesTypes || '';
        var fetcher = opts.fetcher || function (q) { return suggest(q, types); };
        var onPick = opts.onPick || function (text) { el.value = text; el.dispatchEvent(new Event('change')); };

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
                opt.addEventListener('mousedown', function (e) { e.preventDefault(); onPick(text); close(); });
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
            if (q.length < minLen) { close(); return; }
            var mine = ++seq;
            timer = setTimeout(function () {
                fetcher(q).then(function (items) { if (mine === seq) render(items); });
            }, 200);
        });
        el.addEventListener('blur', function () { setTimeout(close, 200); });
        el.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        window.addEventListener('scroll', function () { if (menu.style.display === 'block') place(); }, true);
        window.addEventListener('resize', function () { if (menu.style.display === 'block') place(); });
    }

    /**
     * Live postcode → address picker. As the customer types their postcode (or
     * the start of their address) Google suggests matches; choosing one drops the
     * full address into the linked field and back-fills the postcode itself.
     * el.dataset.postcodeFill is a selector for the address field to populate.
     */
    function attachPostcodePicker(el) {
        var addr = document.querySelector(el.dataset.postcodeFill || '');
        attachPlaces(el, {
            minLen: 2,
            fetcher: postcodeAddresses,
            onPick: function (text) {
                var pc = extractPostcode(text);
                if (pc) { el.value = pc; el.dispatchEvent(new Event('change')); }
                if (addr) {
                    addr.value = text;
                    addr.dispatchEvent(new Event('change'));
                    // Drop the cursor at the start so the customer can add their
                    // house number and pick the exact address from the list.
                    try { addr.focus(); addr.setSelectionRange(0, 0); } catch (e) {}
                }
            }
        });
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

    // Auto-fill a postcode field from a chosen address (address-first flow):
    // when the address changes, pull the postcode out of it into the target field.
    function attachPostcodeExtractor(el) {
        var target = document.querySelector(el.dataset.postcodeTarget || '');
        if (!target) return;
        el.addEventListener('change', function () {
            var pc = extractPostcode(el.value);
            if (pc) { target.value = pc; target.dispatchEvent(new Event('change')); }
        });
    }

    function init() {
        document.querySelectorAll('[data-places]').forEach(function (el) { attachPlaces(el); });
        document.querySelectorAll('[data-postcode-fill]').forEach(attachPostcodePicker);
        document.querySelectorAll('[data-postcode-target]').forEach(attachPostcodeExtractor);
        initAutoQuote();
        window.CETattachPlaces = attachPlaces;
        // Upgrade to Google's own client-side autocomplete when a key is present.
        if (window.CET_MAPS_KEY) { loadGoogle(window.CET_MAPS_KEY).catch(function () { googleReady = false; }); }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
