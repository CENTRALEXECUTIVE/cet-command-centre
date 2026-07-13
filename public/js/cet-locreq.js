/*
 * Driver one-off location share, in answer to an office "request location".
 * Works at ANY live stage (even before Set off): captures a single position and
 * posts it to this job's location endpoint. Auto-tries on open (the driver
 * arrived here from the office push) and offers a manual Share button too.
 */
(function () {
    var cfg = document.getElementById('loc-request');
    if (!cfg) return;
    var state = document.getElementById('locreq-state');
    var btn = document.getElementById('locreq-share');
    var banner = document.getElementById('locreq-banner');
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function say(msg) { if (state) state.textContent = msg; }

    function share() {
        if (!('geolocation' in navigator)) { say('This phone can’t share location.'); return; }
        say('Getting your position…');
        navigator.geolocation.getCurrentPosition(function (pos) {
            fetch(cfg.dataset.url, {
                method: 'POST', keepalive: true,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                body: JSON.stringify({
                    lat: pos.coords.latitude, lng: pos.coords.longitude,
                    heading: pos.coords.heading, speed: pos.coords.speed, accuracy: pos.coords.accuracy
                })
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (d && d.shared) {
                    say('✓ Location sent to the office.');
                    if (banner) setTimeout(function () { banner.style.display = 'none'; }, 2500);
                } else {
                    say('Couldn’t share for this job.');
                }
            }).catch(function () { say('Couldn’t reach the office — tap Share to retry.'); });
        }, function () {
            say('Location blocked — allow it in your phone’s settings, then tap Share.');
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 10000 });
    }

    if (btn) btn.addEventListener('click', share);
    share();
})();
