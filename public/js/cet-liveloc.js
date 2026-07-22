/*
 * Office live-location card on the booking page. Polls the driver's latest GPS
 * ping (every 12s) and wires the "Request location" button, which pushes the
 * driver's phone to share where they are right now.
 */
(function () {
    var card = document.getElementById('live-loc');
    if (!card) return;
    var statusEl = document.getElementById('loc-status');
    var detail = document.getElementById('loc-detail');
    var mapLink = document.getElementById('loc-map');
    var btn = document.getElementById('loc-req-btn');
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var pollUrl = card.dataset.poll;
    var requestUrl = card.dataset.request;
    var awaiting = false;

    function fmtAge(s) {
        if (s == null) return '';
        if (s < 60) return s + 's ago';
        if (s < 3600) return Math.round(s / 60) + ' min ago';
        return Math.round(s / 3600) + 'h ago';
    }

    function render(d) {
        if (d.ping) {
            detail.style.display = '';
            mapLink.href = 'https://www.google.com/maps?q=' + d.ping.lat + ',' + d.ping.lng;
            var stale = d.ping.age > 180;
            var when = fmtAge(d.ping.age);
            if (awaiting && !d.pending) {
                statusEl.innerHTML = '🟢 <strong>Location updated</strong> ' + when + '.';
                awaiting = false;
            } else if (d.pending) {
                statusEl.innerHTML = '🟡 Requested ' + fmtAge(d.requested_age) + ' — waiting for the driver… (last seen ' + when + ')';
            } else {
                statusEl.innerHTML = (stale ? '🟠' : '🟢') + ' Last location ' + when + (stale ? ' (going stale)' : '') + '.';
            }
        } else {
            detail.style.display = 'none';
            if (d.pending) {
                statusEl.innerHTML = '🟡 Requested ' + fmtAge(d.requested_age) + ' — waiting for the driver to share…';
            } else {
                statusEl.textContent = 'No location shared yet — tap Request location.';
            }
        }
    }

    function poll() {
        fetch(pollUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(render)
            .catch(function () {});
    }

    if (btn) btn.addEventListener('click', function () {
        btn.disabled = true;
        var old = btn.textContent; btn.textContent = 'Requesting…';
        fetch(requestUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (res) {
                awaiting = true;
                if (res.d && res.d.pushed === 0) {
                    statusEl.innerHTML = '🟡 Requested — but the driver has no push device yet (or keys aren’t set). They’ll share when they open the job.';
                }
                poll();
            })
            .catch(function () {})
            .finally(function () { btn.disabled = false; btn.textContent = old; });
    });

    poll();
    setInterval(poll, 12000);
})();
