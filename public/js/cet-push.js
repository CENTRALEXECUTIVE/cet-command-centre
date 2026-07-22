/*
 * Driver push-notification opt-in. Shows the "Enable notifications" banner when
 * the browser supports push and it isn't on yet; subscribes via the service
 * worker and registers the subscription with the server. Free Web Push — works
 * on installed Android PWAs and on iOS 16.4+ once added to the Home Screen.
 */
(function () {
    var cfg = document.getElementById('push-cfg');
    var banner = document.getElementById('push-banner');
    if (!cfg || !banner) return;

    var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    var btn = document.getElementById('push-enable');
    var state = document.getElementById('push-state');
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;

    function urlB64ToUint8Array(base64) {
        var padding = '='.repeat((4 - base64.length % 4) % 4);
        var b64 = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(b64);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    function show(msg) { if (state) state.textContent = msg; }

    if (!supported) { return; } // silently hide on unsupported browsers

    // Already granted + subscribed? Keep the banner hidden.
    navigator.serviceWorker.ready.then(function (reg) {
        return reg.pushManager.getSubscription();
    }).then(function (sub) {
        if (Notification.permission === 'granted' && sub) {
            return; // all set — no banner
        }
        if (Notification.permission !== 'denied') {
            banner.style.display = 'block';
        }
    }).catch(function () {});

    function subscribe() {
        show('Setting up…');
        fetch(cfg.dataset.keyUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.enabled || !data.key) { show('Notifications aren’t set up on the server yet.'); return; }
                return Notification.requestPermission().then(function (perm) {
                    if (perm !== 'granted') { show('You blocked notifications — enable them in your phone’s site settings.'); return; }
                    return navigator.serviceWorker.ready.then(function (reg) {
                        return reg.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: urlB64ToUint8Array(data.key),
                        });
                    }).then(function (sub) {
                        var json = sub.toJSON();
                        return fetch(cfg.dataset.subUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            body: JSON.stringify({
                                endpoint: sub.endpoint,
                                keys: json.keys,
                                contentEncoding: (PushManager.supportedContentEncodings || ['aes128gcm'])[0],
                            }),
                        });
                    }).then(function () {
                        show('✓ Job alerts are on.');
                        setTimeout(function () { banner.style.display = 'none'; }, 2500);
                    });
                });
            })
            .catch(function () { show('Couldn’t turn on notifications — try again.'); });
    }

    if (btn) btn.addEventListener('click', subscribe);
})();
