/*
 * Driver live tracking — as consistent as a web app allows.
 *
 *  • watchPosition (a continuous stream, not one-shot polling) so the map dot
 *    and the office update smoothly as the car moves.
 *  • Screen Wake Lock so the phone doesn't sleep mid-job while the app is on
 *    the mount — this is what keeps tracking steady during a drive.
 *  • Sends to the server at most every N seconds (config interval), and ALWAYS
 *    the instant the driver flicks back to the app (visibility change), using
 *    fetch keepalive so an in-flight ping survives a brief background.
 *  • Driver start/stop control; stops itself when the server says the job is
 *    over. GPS is only ever collected while the job is active.
 *
 * NOTE: browsers cannot track with the screen off / app closed — that needs a
 * native wrapper. This gets the most a PWA can, for the phone-on-the-mount case.
 */
(function () {
    var cfg = document.getElementById('gps-config');
    var box = document.getElementById('track-box');
    if (!cfg || cfg.dataset.active !== '1') return;
    if (box) box.style.display = 'block';

    var dot = document.getElementById('track-dot');
    var label = document.getElementById('track-label');
    var last = document.getElementById('track-last');
    var nowBtn = document.getElementById('track-now');
    var toggleBtn = document.getElementById('track-toggle');
    var tokenEl = document.querySelector('meta[name="csrf-token"]');
    var token = tokenEl ? tokenEl.content : '';

    if (!('geolocation' in navigator)) {
        if (label) label.textContent = 'This phone can’t share location.';
        if (dot) dot.textContent = '🔴';
        if (toggleBtn) toggleBtn.style.display = 'none';
        return;
    }

    var minGap = (parseInt(cfg.dataset.interval, 10) || 60) * 1000; // min ms between server sends
    var sharing = true;
    var watchId = null;
    var wakeLock = null;
    var lastSentAt = 0;
    var lastPos = null;

    function stamp() {
        var d = new Date();
        return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + ':' + ('0' + d.getSeconds()).slice(-2);
    }
    function setState(emoji, text, sub) {
        if (dot) dot.textContent = emoji;
        if (label && text) label.textContent = text;
        if (last && sub) last.textContent = sub;
    }

    // Keep the screen awake while sharing so tracking doesn't die on sleep.
    function requestWakeLock() {
        if (!('wakeLock' in navigator) || !sharing) return;
        navigator.wakeLock.request('screen').then(function (lock) {
            wakeLock = lock;
            lock.addEventListener('release', function () { wakeLock = null; });
        }).catch(function () {});
    }
    function releaseWakeLock() {
        if (wakeLock) { try { wakeLock.release(); } catch (e) {} wakeLock = null; }
    }

    function send(pos, force) {
        var nowMs = Date.now();
        if (!force && nowMs - lastSentAt < minGap) return; // throttle server writes
        fetch(cfg.dataset.url, {
            method: 'POST', keepalive: true,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
            body: JSON.stringify({
                lat: pos.coords.latitude, lng: pos.coords.longitude,
                heading: pos.coords.heading, speed: pos.coords.speed, accuracy: pos.coords.accuracy
            })
        }).then(function (r) { return r.json(); }).then(function (data) {
            lastSentAt = Date.now();
            setState('🟢', 'Tracking on', 'Location sent at ' + stamp() + ' — the office can see you.');
            if (data && data.tracking === false) { finish(); }
        }).catch(function () {
            if (last) last.textContent = 'Couldn’t reach the office — will retry on the next fix.';
        });
    }

    function onFix(pos) {
        lastPos = pos;
        if (window.CETshowMe) window.CETshowMe(pos.coords.latitude, pos.coords.longitude);
        if (sharing) send(pos, false);
    }
    function onErr() {
        setState('🔴', 'Location permission needed', 'Allow location for this site, then tap “Send my location now”.');
    }

    function start() {
        sharing = true;
        if (toggleBtn) toggleBtn.textContent = '⏸ Stop sharing';
        setState('🟡', 'Getting your location…', 'The office can see you on the Live map.');
        requestWakeLock();
        if (watchId === null) {
            watchId = navigator.geolocation.watchPosition(onFix, onErr, {
                enableHighAccuracy: true, maximumAge: 5000, timeout: 20000
            });
        }
        if (lastPos) send(lastPos, true);
    }
    function pause() {
        sharing = false;
        if (watchId !== null) { navigator.geolocation.clearWatch(watchId); watchId = null; }
        releaseWakeLock();
        if (toggleBtn) toggleBtn.textContent = '▶ Start sharing';
        setState('⚪', 'Sharing paused', 'The office can’t see your location while paused.');
    }
    function finish() {
        if (watchId !== null) { navigator.geolocation.clearWatch(watchId); watchId = null; }
        releaseWakeLock();
        if (staleTimer) { clearInterval(staleTimer); staleTimer = null; }
        setState('⚪', 'Tracking finished', 'This job is no longer active, so location sharing has stopped.');
        if (toggleBtn) toggleBtn.style.display = 'none';
        if (nowBtn) nowBtn.style.display = 'none';
    }

    if (toggleBtn) toggleBtn.addEventListener('click', function () { sharing ? pause() : start(); });
    if (nowBtn) nowBtn.addEventListener('click', function () { if (!sharing) start(); else if (lastPos) send(lastPos, true); });

    // Re-acquire wake lock + fire a fresh ping the instant the driver returns.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && sharing) {
            requestWakeLock();
            if (lastPos) send(lastPos, true);
        }
    });

    // Warn if no fix has reached the office for a while.
    var staleTimer = setInterval(function () {
        if (sharing && lastSentAt && Date.now() - lastSentAt > minGap * 3) {
            setState('🟡', 'Weak signal', 'No location sent for a while — check signal/GPS.');
        }
    }, 30000);

    start();
})();
