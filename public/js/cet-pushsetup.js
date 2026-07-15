/*
 * Robust push-notification setup for the Notifications page. Unlike the compact
 * banner on the dashboard, this ALWAYS shows the Enable button and reports
 * exactly what happens at each step, so a device that won't subscribe tells you
 * why (unsupported browser, blocked permission, keys not set, etc.).
 */
(function () {
    var card = document.getElementById('push-device');
    if (!card) return;

    var enableBtn = document.getElementById('push-enable');
    var testBtn = document.getElementById('push-test');
    var status = document.getElementById('push-status');
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function say(msg, ok) {
        status.textContent = msg;
        status.style.color = ok === true ? '#1f8b4c' : (ok === false ? '#b4531a' : '');
    }

    function b64ToUint8(base64) {
        var pad = '='.repeat((4 - base64.length % 4) % 4);
        var b64 = (base64 + pad).replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(b64);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

    // Reflect current state on load.
    if (!supported) {
        say('This browser can’t do notifications here. On iPhone: tap Share → Add to Home Screen, open the app from that icon, then come back and tap Enable.', false);
        enableBtn.disabled = true;
    } else if (Notification.permission === 'denied') {
        say('Notifications are blocked for this site in your phone/browser settings. Allow them there, then tap Enable.', false);
    } else {
        navigator.serviceWorker.ready
            .then(function (reg) { return reg.pushManager.getSubscription(); })
            .then(function (sub) {
                if (sub && Notification.permission === 'granted') {
                    say('✓ This device is already set up. Tap “Send a test” to check it buzzes.', true);
                } else {
                    say('Not enabled on this device yet — tap “Enable notifications”.');
                }
            })
            .catch(function () { say('Tap “Enable notifications” to turn them on for this device.'); });
    }

    function enable() {
        if (!supported) return;
        say('Step 1/4 — checking the server…');

        fetch(card.dataset.keyUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.enabled || !data.key) {
                    say('✗ Push isn’t switched on the server yet (VAPID keys missing). This needs the dev to run cet:make-vapid.', false);
                    return;
                }
                say('Step 2/4 — asking your permission…');
                return Notification.requestPermission().then(function (perm) {
                    if (perm !== 'granted') {
                        say('You didn’t allow notifications. Tap Enable again and choose “Allow”.', false);
                        return;
                    }
                    say('Step 3/4 — starting the app worker…');
                    // Register (idempotent) and wait for it to be active.
                    var swReg = null;
                    return navigator.serviceWorker.register('/sw.js').then(function (reg) {
                        swReg = reg;
                        return navigator.serviceWorker.ready;
                    }).then(function () {
                        say('Step 4/4 — subscribing this device…');
                        return swReg.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: b64ToUint8(data.key),
                        });
                    }).then(function (sub) {
                        var json = sub.toJSON();
                        return fetch(card.dataset.subUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                            body: JSON.stringify({
                                endpoint: sub.endpoint,
                                keys: json.keys,
                                contentEncoding: (window.PushManager && PushManager.supportedContentEncodings || ['aes128gcm'])[0],
                            }),
                        });
                    }).then(function (r) {
                        if (r && !r.ok) throw new Error('server rejected the subscription');
                        // DIAGNOSTIC: fire a LOCAL notification right now. If this one
                        // appears, the phone can display notifications (so any missing
                        // test is a delivery/settings issue). If it doesn't appear,
                        // Android is blocking notifications for this site.
                        try {
                            swReg.showNotification('✅ CET notifications are on', {
                                body: 'If you can see this, your phone can show CET alerts. Now tap “Send a test” to check delivery.',
                                icon: '/icons/icon-192.png', badge: '/icons/icon-192.png', vibrate: [80, 40, 80],
                            });
                        } catch (e) {}
                        say('✓ Notifications are ON. You should see a “notifications are on” banner now — if you do, tap “Send a test”. If you don’t, notifications are blocked in your phone settings.', true);
                    });
                });
            })
            .catch(function (e) {
                say('✗ Couldn’t turn on notifications: ' + (e && e.message ? e.message : 'unknown error') + '. On iPhone, open the app from the Home Screen icon first.', false);
            });
    }

    function test() {
        say('Sending a test to this device…');
        fetch(card.dataset.testUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (res) {
                var d = res.d || {};
                var rep = (d.reports && d.reports[0]) || null;
                var detail = rep ? (' [push service: ' + (rep.status || '?') + ' ' + (rep.reason || '') + ']') : '';
                if (res.ok && d.ok) {
                    say('✓ Accepted by the push service' + detail + '. If your phone doesn’t buzz in ~10s, pull down the notification shade — and try installing the app to your Home Screen (Chrome ⋮ → Add to Home Screen), which delivers more reliably.', true);
                } else if (d.reason === 'not_configured') {
                    say('✗ Push isn’t switched on the server yet (VAPID keys).', false);
                } else if (d.subscriptions === 0) {
                    say('✗ No device subscribed here yet — tap “Enable notifications” first.', false);
                } else {
                    say('✗ The push service rejected it' + detail + '. Tell me this message.', false);
                }
            })
            .catch(function () { say('✗ Request failed — check your connection.', false); });
    }

    enableBtn.addEventListener('click', enable);
    testBtn.addEventListener('click', test);
})();
