/*
 * Control-tower alerts feed (admin dashboard).
 *  - Polls the feed every 30 s (matching the dispatch board cadence).
 *  - New rows animate in at the top; critical rows glow until acknowledged.
 *  - Updates the nav badge (unacknowledged criticals) on every poll.
 *  - Optional soft chime on NEW critical events — per-admin preference,
 *    default off. Respects prefers-reduced-motion for the animations.
 */
(function () {
    var panel = document.getElementById('alerts-panel');
    if (!panel) return;

    var list = document.getElementById('alerts-list');
    var toasts = document.getElementById('alerts-toasts'); // big top-right popups (optional)
    var stamp = document.getElementById('alerts-stamp');
    var tokenEl = document.querySelector('meta[name="csrf-token"]');
    var token = tokenEl ? tokenEl.content : '';
    var chimeOn = panel.dataset.chime === '1';
    var alarmOn = panel.dataset.alarm === '1';
    var silenceBtn = document.getElementById('alerts-silence');
    var clearBtn = document.getElementById('alerts-clear');
    var seen = null; // ids seen last poll (null until first render)

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (!confirm('Clear all live alerts? This marks them all as dealt with.')) return;
            fetch(clearBtn.dataset.clear, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }
            }).then(function (r) { return r.json(); }).then(function (d) {
                seen = {};
                list.innerHTML = '<p class="muted mb-0" style="font-size:13px">All clear — nothing needs attention.</p>';
                if (toasts) toasts.innerHTML = '';
                clearBtn.style.display = 'none';
                badge(d.critical);
                stopAlarm(true);
            }).catch(function () {});
        });
    }
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var ICONS = { info: '✓', warning: '⚠', critical: '🔴' };

    function esc(s) { var e = document.createElement('div'); e.textContent = s == null ? '' : s; return e.innerHTML; }

    function chime() {
        try { new Audio('/sounds/chime.wav').play().catch(function () {}); } catch (e) {}
    }

    // ── Alarm: a repeating two-tone siren (Web Audio, no asset) + vibration that
    //    keeps going while an unacknowledged CRITICAL alert is on screen. Stops
    //    when it's acknowledged or the user hits Silence. ────────────────────────
    var actx = null, alarmTimer = null, silenced = false;

    function audioCtx() {
        if (!actx) {
            var C = window.AudioContext || window.webkitAudioContext;
            if (C) actx = new C();
        }
        if (actx && actx.state === 'suspended') actx.resume();
        return actx;
    }
    // Browsers need a user gesture before audio can play — arm it on first tap.
    document.addEventListener('click', function armAudio() {
        audioCtx();
        document.removeEventListener('click', armAudio);
    }, { once: true });

    // A LOUD, wailing emergency siren: a sawtooth sweeping 700↔1300 Hz at near-
    // full volume, repeated back-to-back so it's continuous, plus a hard vibration
    // pattern. Meant to be impossible to sleep through — it runs until the critical
    // alert is acknowledged or Silence is pressed.
    function beep() {
        var ctx = audioCtx();
        if (!ctx) return;
        var t = ctx.currentTime, dur = 0.75;
        var o = ctx.createOscillator(), g = ctx.createGain();
        o.type = 'sawtooth';
        o.frequency.setValueAtTime(700, t);
        o.frequency.linearRampToValueAtTime(1300, t + dur / 2);
        o.frequency.linearRampToValueAtTime(700, t + dur);
        g.gain.setValueAtTime(1.0, t);            // absolute max the browser allows (rides the media volume)
        o.connect(g); g.connect(ctx.destination);
        o.start(t); o.stop(t + dur);
        if (navigator.vibrate) navigator.vibrate([600, 100, 600, 100, 600]);
    }

    function startAlarm() {
        if (alarmTimer || silenced) return;
        if (silenceBtn) silenceBtn.style.display = '';
        beep();
        alarmTimer = setInterval(beep, 760); // back-to-back = continuous wail
    }
    function stopAlarm(hideBtn) {
        if (alarmTimer) { clearInterval(alarmTimer); alarmTimer = null; }
        if (hideBtn && silenceBtn) silenceBtn.style.display = 'none';
    }
    if (silenceBtn) {
        silenceBtn.addEventListener('click', function () {
            silenced = true;      // muted until a NEW critical arrives
            stopAlarm(true);
        });
    }

    function badge(count) {
        document.querySelectorAll('[data-alert-badge]').forEach(function (b) {
            b.textContent = count > 0 ? count : '';
            b.style.display = count > 0 ? '' : 'none';
        });
    }

    // Drop an alert everywhere it shows — the log row AND its top-right popup.
    function removeAlert(id) {
        document.querySelectorAll('[data-alert-id="' + id + '"]').forEach(function (el) { el.remove(); });
    }

    function ack(id) {
        fetch(panel.dataset.feed.replace(/\/feed$/, '/' + id + '/ack'), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); }).then(function (d) {
            // Dealt with → drop it off the feed entirely. Pressing Done means the
            // operator has ACTED, so silence the siren straight away (like the
            // Silence button); a genuinely NEW critical later re-arms it.
            if (seen) delete seen[id];
            removeAlert(id);
            silenced = true;
            stopAlarm(true);
            badge(d.critical);
            if (!list.querySelector('.alert-row')) {
                list.innerHTML = '<p class="muted mb-0" style="font-size:13px">All clear — nothing needs attention.</p>';
            }
        }).catch(function () {});
    }

    // The big top-right popups: only what needs dealing with NOW — unacknowledged
    // critical + warning events. Info pings (set off, arrived…) stay in the log.
    function renderToasts(data) {
        if (!toasts) return;
        toasts.innerHTML = '';
        data.events.forEach(function (e) {
            if (e.acknowledged) return;
            if (e.severity !== 'critical' && e.severity !== 'warning') return;
            var t = document.createElement('div');
            t.className = 'alert-toast sev-' + e.severity;
            t.dataset.alertId = e.id;
            t.innerHTML =
                '<span class="at-ico">' + (ICONS[e.severity] || '·') + '</span>'
                + '<div class="at-body">'
                + '<div class="at-title">' + (e.url ? '<a href="' + e.url + '">' + esc(e.title) + '</a>' : esc(e.title)) + '</div>'
                + '<div class="at-time mono">' + esc(e.time) + '</div>'
                + '</div>'
                + '<button type="button" class="at-done">Done</button>';
            var b = t.querySelector('.at-done');
            if (b) b.addEventListener('click', function () { ack(e.id); });
            toasts.appendChild(t);
        });
    }

    function render(data) {
        var isFirst = seen === null;
        var known = seen || {};
        seen = {};
        var hadNewCritical = false;

        if (clearBtn) clearBtn.style.display = data.events.length ? '' : 'none';

        if (!data.events.length) {
            list.innerHTML = '<p class="muted mb-0" style="font-size:13px">All clear — nothing needs attention.</p>';
            if (toasts) toasts.innerHTML = '';
            badge(data.critical);
            stopAlarm(true);
            return;
        }

        list.innerHTML = '';
        var hasLiveCritical = false;
        data.events.forEach(function (e) {
            seen[e.id] = true;
            var isNew = !isFirst && !known[e.id];
            if (e.severity === 'critical' && !e.acknowledged) hasLiveCritical = true;
            if (isNew && e.severity === 'critical' && !e.acknowledged) hadNewCritical = true;

            var row = document.createElement('div');
            row.className = 'alert-row sev-' + e.severity
                + (e.severity === 'critical' && !e.acknowledged ? ' critical-live' : '')
                + (isNew && !reduced ? ' slide-in' : '');
            row.dataset.alertId = e.id;
            row.innerHTML =
                '<span class="a-time mono">' + esc(e.time) + '</span>'
                + '<span class="a-ico">' + (ICONS[e.severity] || '·') + '</span>'
                + '<span class="a-title">' + (e.url ? '<a href="' + e.url + '">' + esc(e.title) + '</a>' : esc(e.title)) + '</span>'
                + '<button type="button" class="ack-btn" title="Dismiss — mark dealt with">Done</button>';
            var btn = row.querySelector('.ack-btn');
            if (btn) btn.addEventListener('click', function () { ack(e.id); });
            list.appendChild(row);
        });

        renderToasts(data);
        badge(data.critical);
        if (hadNewCritical) { silenced = false; }        // a new critical re-arms the alarm
        if (hadNewCritical && chimeOn) chime();
        if (alarmOn && hasLiveCritical) { startAlarm(); } else { stopAlarm(true); }
        if (stamp) {
            var d = new Date();
            stamp.textContent = 'Updated ' + ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + ':' + ('0' + d.getSeconds()).slice(-2);
        }
    }

    function poll() {
        if (document.hidden) return;
        fetch(panel.dataset.feed, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(render)
            .catch(function () {
                // Never sit on "Loading…" forever — say what's happening.
                if (seen === null) {
                    list.innerHTML = '<p class="muted mb-0" style="font-size:13px">Can\'t reach the alerts feed right now — retrying every 30s.</p>';
                }
            });
    }

    poll();
    setInterval(poll, 30000);
})();
