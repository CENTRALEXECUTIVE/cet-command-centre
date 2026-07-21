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
    var stamp = document.getElementById('alerts-stamp');
    var tokenEl = document.querySelector('meta[name="csrf-token"]');
    var token = tokenEl ? tokenEl.content : '';
    var chimeOn = panel.dataset.chime === '1';
    var alarmOn = panel.dataset.alarm === '1';
    var silenceBtn = document.getElementById('alerts-silence');
    var seen = null; // ids seen last poll (null until first render)
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

    function beep() {
        var ctx = audioCtx();
        if (!ctx) return;
        [880, 660].forEach(function (freq, i) {
            var o = ctx.createOscillator(), g = ctx.createGain();
            var t = ctx.currentTime + i * 0.35;
            o.type = 'square'; o.frequency.value = freq;
            g.gain.setValueAtTime(0.0001, t);
            g.gain.exponentialRampToValueAtTime(0.5, t + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, t + 0.3);
            o.connect(g); g.connect(ctx.destination);
            o.start(t); o.stop(t + 0.32);
        });
        if (navigator.vibrate) navigator.vibrate([300, 120, 300]);
    }

    function startAlarm() {
        if (alarmTimer || silenced) return;
        if (silenceBtn) silenceBtn.style.display = '';
        beep();
        alarmTimer = setInterval(beep, 1600);
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

    function ack(id, row) {
        fetch(panel.dataset.feed.replace(/\/feed$/, '/' + id + '/ack'), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); }).then(function (d) {
            // Dealt with → drop it off the feed entirely.
            if (seen) delete seen[id];
            row.remove();
            badge(d.critical);
            if (!list.querySelector('.alert-row')) {
                list.innerHTML = '<p class="muted mb-0" style="font-size:13px">All clear — nothing needs attention.</p>';
            }
        }).catch(function () {});
    }

    function render(data) {
        var isFirst = seen === null;
        var known = seen || {};
        seen = {};
        var hadNewCritical = false;

        if (!data.events.length) {
            list.innerHTML = '<p class="muted mb-0" style="font-size:13px">All clear — nothing needs attention.</p>';
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
            row.innerHTML =
                '<span class="a-time mono">' + esc(e.time) + '</span>'
                + '<span class="a-ico">' + (ICONS[e.severity] || '·') + '</span>'
                + '<span class="a-title">' + (e.url ? '<a href="' + e.url + '">' + esc(e.title) + '</a>' : esc(e.title)) + '</span>'
                + '<button type="button" class="ack-btn" title="Dismiss — mark dealt with">Done</button>';
            var btn = row.querySelector('.ack-btn');
            if (btn) btn.addEventListener('click', function () { ack(e.id, row); });
            list.appendChild(row);
        });

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
