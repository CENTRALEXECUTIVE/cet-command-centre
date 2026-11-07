{{-- ───── Booking form v2 (Nov 2026): clean, compact dispatcher layout ─────
     Presentation only. Every field id/name, validation and script hook in
     create.blade.php is unchanged; the small script below only re-arranges
     existing elements (segmented journey control, passenger steppers in one
     row) and adds the compact mobile total bar toggle. --}}
<style>
    .sf-booking{ --bk-line:#e3e3de; --bk-ink:#15151a; --bk-muted:#6c6c72; --bk-field:#fff; --bk-soft:#f6f6f3; --bk-gold:#FBBA2A; }
    html[data-theme="dark"] .sf-booking{ --bk-line:rgba(255,255,255,.12); --bk-ink:#f2f4f8; --bk-muted:#9aa1ad; --bk-field:#0f1420; --bk-soft:#151b2c; }

    /* Anything marked hidden stays hidden, even inside .grid (fixes the VAT
       company fields showing when VAT isn't ticked). */
    .sf-booking [hidden]{ display:none !important; }
    /* …except the vehicle cards, which must always be visible to choose from. */
    .sf-booking #veh-cards[hidden]{ display:grid !important; }

    /* Header: slim on phones (the top bar already says "New Booking"). */
    @media (max-width:900px){ .sf-booking .smart-hero.sf-compact{ display:none; } }
    .sf-booking .smart-hero.sf-compact{ padding:12px 18px 14px; }

    /* Sections: flat cards, tight spacing. */
    .sf-booking .eto-section{ border:1px solid var(--bk-line); border-radius:14px; box-shadow:none; margin-bottom:12px; overflow:visible; }
    .sf-booking .eto-section > .head{ padding:11px 14px; font-size:12.5px; letter-spacing:.6px; border-bottom:1px solid var(--bk-line); }
    .sf-booking .eto-section.collapsible.closed > .head{ border-bottom:0; }
    .sf-booking .eto-section > .head::before{ width:20px; height:20px; font-size:11px; }
    .sf-booking .eto-section > .body{ padding:12px 14px 14px; }
    .sf-booking .field{ margin-bottom:12px; }
    .sf-booking .grid.grid-2{ gap:0 12px; }

    /* Labels and hints: short and quiet. */
    .sf-booking .field > label, .sf-booking .stepper-field .lbl{ font-size:12.5px; font-weight:650; color:var(--bk-ink); margin-bottom:5px; }
    .sf-booking .field > label .muted{ display:none; }
    .sf-booking .hint{ display:none; }
    .sf-booking .field:has(#vat_invoice) .hint{ display:block; font-size:12px; color:var(--bk-muted); margin-top:4px; }
    .sf-booking .error{ font-size:12.5px; }

    /* Inputs: white, 44px, thin border, gold focus. */
    .sf-booking input:not([type=checkbox]):not([type=radio]):not([type=hidden]),
    .sf-booking select, .sf-booking textarea{
        background:var(--bk-field); border:1px solid var(--bk-line); border-radius:10px; font-size:15px;
        min-height:44px; padding:9px 12px; color:var(--bk-ink); box-shadow:none; }
    .sf-booking textarea{ min-height:44px; height:44px; resize:vertical; line-height:1.35; }
    .sf-booking textarea:focus{ height:auto; min-height:64px; }
    .sf-booking input:focus, .sf-booking select:focus, .sf-booking textarea:focus{
        outline:none; border-color:var(--bk-gold); box-shadow:0 0 0 3px rgba(251,186,42,.22); }
    .sf-booking #pickup_postcode, .sf-booking #destination_postcode,
    .sf-booking #return_pickup_postcode, .sf-booking #return_destination_postcode{ max-width:190px; }
    .sf-booking .loc-row{ gap:8px; align-items:center; }
    .sf-booking .loc-row .pin{ width:26px; height:26px; font-size:12px; flex:0 0 26px; }
    .sf-booking #add-stop{ padding:5px 12px !important; font-size:12.5px !important; }

    /* Journey type as a segmented control (the select stays for the form/JS). */
    .sf-booking .bk-seg{ display:grid; grid-template-columns:repeat(3,1fr); gap:4px; padding:4px; border-radius:12px;
        background:var(--bk-soft); border:1px solid var(--bk-line); }
    .sf-booking .bk-seg button{ border:0; background:transparent; border-radius:9px; padding:9px 6px; font:inherit;
        font-size:13.5px; font-weight:650; color:var(--bk-muted); cursor:pointer; }
    .sf-booking .bk-seg button[aria-pressed="true"]{ background:#0b0b0c; color:var(--bk-gold); }
    html[data-theme="dark"] .sf-booking .bk-seg button[aria-pressed="true"]{ background:var(--bk-gold); color:#0b0b0c; }
    .sf-booking .bk-seg-select{ position:absolute !important; width:1px; height:1px; opacity:0; pointer-events:none; }

    /* Passengers / suitcases / cabin bags side by side. */
    .sf-booking .bk-pax{ display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; margin:12px 0 4px; }
    .sf-booking .bk-pax .stepper-field{ display:flex; flex-direction:column; align-items:stretch; gap:5px; border:0 !important; padding:0; }
    .sf-booking .bk-pax .stepper-field .lbl .sub{ display:none; }
    .sf-booking .bk-pax .stepper{ display:grid; grid-template-columns:34px 1fr 34px; height:42px; border-radius:10px;
        border:1px solid var(--bk-line); overflow:hidden; background:var(--bk-field); max-width:none; }
    .sf-booking .bk-pax .stepper button{ border:0; background:var(--bk-soft); font-size:17px; padding:0; min-width:0; }
    .sf-booking .bk-pax .stepper input{ border:0 !important; border-radius:0 !important; min-height:40px !important; text-align:center; padding:0 !important; box-shadow:none !important; }

    /* Vehicle cards: compact rows, two columns when there's room. */
    .sf-booking .veh-cards{ gap:8px; grid-template-columns:1fr; }
    @media (min-width:620px){ .sf-booking .veh-cards{ grid-template-columns:1fr 1fr; } }
    .sf-booking .veh-card{ grid-template-columns:52px 1fr auto; gap:10px; padding:8px 10px; border-radius:12px; border-width:1px; }
    .sf-booking .veh-photo{ width:52px; height:36px; border-radius:8px; }
    .sf-booking .veh-photo svg{ width:44px; height:22px; }
    .sf-booking .veh-name{ font-size:13.5px; }
    .sf-booking .veh-tag{ display:none; }
    .sf-booking .veh-price{ font-size:15px; }
    .sf-booking .veh-tick{ top:4px; left:4px; width:16px; height:16px; font-size:10px; }

    /* Checkboxes as clean rows. */
    .sf-booking .checkbox-row{ gap:10px; align-items:flex-start; font-size:14px; }
    .sf-booking .checkbox-row input[type=checkbox]{ width:20px; height:20px; flex:0 0 20px; accent-color:#0b0b0c; margin-top:1px; }
    .sf-booking .eto-section:has(#privacy_consent) .checkbox-row label{ font-weight:500; font-size:12.5px; color:var(--bk-muted); }

    /* Phones and tablets: a slim total bar fixed above the tab bar.
       Tap the total to see the breakdown, summary, preview and cancel. */
    @media (max-width:1099px){
        .sf-booking .eto-form{ padding-bottom:84px; }
        .sf-booking .total-bar{ position:fixed; left:10px; right:10px; z-index:45; margin:0;
            bottom:calc(10px + env(safe-area-inset-bottom, 0px)); padding:10px 12px; border-radius:14px;
            display:grid !important; grid-template-columns:minmax(0,1fr) auto; align-items:center; gap:6px 10px;
            max-height:45vh; overflow-y:auto; overflow-x:hidden; box-sizing:border-box;
            transition:transform .18s ease, opacity .18s ease; }
        /* While a field has focus (phone keyboard up) the bar gets out of the way. */
        .sf-booking .total-bar.bk-hide{ transform:translateY(160%); opacity:0; pointer-events:none; }
        .sf-booking .total-bar:not(.bk-open) .total-amount .basis{ display:none; }
        .sf-booking .total-bar:not(.bk-open){ padding:8px 10px 8px 14px; }
        .sf-booking .total-bar .total-main{ cursor:pointer; min-width:0; }
        .sf-booking .total-bar .total-label{ font-size:10px; }
        .sf-booking .total-bar .total-label::after{ content:" ▴ price breakdown"; letter-spacing:0; text-transform:none; color:#8d8c86; }
        .sf-booking .total-bar.bk-open .total-label::after{ content:" ▾ hide"; }
        .sf-booking .total-bar .total-amount{ font-size:22px !important; }
        .sf-booking .total-bar .total-amount .basis{ font-size:11px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sf-booking .total-bar:not(.bk-open) .total-breakdown,
        /* Phones: the bar is for the price only, never a repeat of the booking details. */
        .sf-booking .total-bar .sf-summary{ display:none !important; }
        .sf-booking .total-bar.bk-open .total-breakdown:not([hidden]){ display:block; grid-column:1 / -1; }
        .sf-booking .total-bar .actions{ display:flex !important; gap:6px; flex-wrap:nowrap; }
        .sf-booking .total-bar .actions .btn-ghost, .sf-booking .total-bar .actions .btn-light{ display:none; }
        .sf-booking .total-bar .actions .btn-primary{ padding:12px 16px !important; font-size:14.5px; white-space:nowrap; }
        .sf-booking .total-bar.bk-open .actions{ grid-column:1 / -1; display:grid !important; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:8px; width:auto; min-width:0; }
        .sf-booking .total-bar.bk-open .actions > *{ display:flex !important; width:100%; min-width:0; box-sizing:border-box; justify-content:center; align-items:center; text-align:center; margin:0; }
        .sf-booking .total-bar.bk-open .actions .btn-primary{ grid-column:1 / -1; order:-1; }
    }
    @media (max-width:900px){
        .sf-booking .total-bar{ bottom:calc(66px + env(safe-area-inset-bottom, 0px)); }
    }

    /* Return journey on its own card (and its own step on phones). */
    .sf-booking .bk-return-sec[hidden]{ display:none !important; }
    .sf-booking .bk-return-sec #return_locations{ border:0 !important; padding:0 !important; margin:0 !important; }
    .sf-booking .bk-return-sec #return_locations > div:first-child{ display:none; }

    /* Step-by-step booking on phones/tablets: one step at a time. */
    .sf-booking .bk-steps-head, .sf-booking .bk-step-nav{ display:none; }
    @media (max-width:1099px){
        .sf-booking.bk-wizard .bk-off{ display:none !important; }
        .sf-booking.bk-wizard .eto-section > .head::before{ display:none; }
        .sf-booking.bk-wizard .bk-steps-head{ display:block; margin:0 0 10px; }
        .sf-booking .bk-steps-head .bk-dots{ display:flex; gap:6px; margin-bottom:6px; }
        .sf-booking .bk-steps-head .bk-dots span{ flex:1; height:4px; border-radius:4px; background:var(--bk-line); }
        .sf-booking .bk-steps-head .bk-dots span.on{ background:var(--bk-gold); }
        .sf-booking .bk-steps-head .bk-title{ font-size:13px; font-weight:700; color:var(--bk-ink); }
        .sf-booking .bk-steps-head .bk-title small{ color:var(--bk-muted); font-weight:600; }
        .sf-booking.bk-wizard .bk-step-nav{ display:grid; grid-template-columns:1fr 2fr; gap:8px; margin:4px 0 14px; }
        .sf-booking .bk-step-nav button{ border-radius:12px; padding:13px 14px; font:inherit; font-weight:750; font-size:15px; cursor:pointer; }
        .sf-booking .bk-step-nav .bk-back{ background:var(--bk-soft); border:1px solid var(--bk-line); color:var(--bk-ink); }
        .sf-booking .bk-step-nav .bk-next{ background:#0b0b0c; color:var(--bk-gold); border:0; }
        .sf-booking .bk-step-nav .bk-back[disabled]{ visibility:hidden; }
        /* The Confirm button lives in the price bar; on the last step "Next" is hidden. */
        .sf-booking.bk-wizard.bk-last .bk-step-nav .bk-next{ display:none; }
        .sf-booking.bk-wizard.bk-last .bk-step-nav{ grid-template-columns:1fr; }
        .sf-booking.bk-wizard:not(.bk-last) .total-bar .actions .btn-primary{ display:none !important; }
        .sf-booking.bk-wizard:not(.bk-last) .total-bar{ grid-template-columns:1fr; }
    }

    /* Return step: its own vehicle cards and party/luggage. */
    .sf-booking .bk-ret-pax{ display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; margin-top:6px; }
    .sf-booking .bk-ret-pax .stepper-field{ display:flex; flex-direction:column; gap:5px; border:0 !important; padding:0; }
    .sf-booking .bk-ret-pax .stepper{ display:grid; grid-template-columns:34px 1fr 34px; height:42px; border-radius:10px;
        border:1px solid var(--bk-line); overflow:hidden; background:var(--bk-field); max-width:none; }
    .sf-booking .bk-ret-pax .stepper button{ border:0; background:var(--bk-soft); font-size:17px; padding:0; min-width:0; }
    .sf-booking .bk-ret-pax .stepper input{ border:0 !important; border-radius:0 !important; min-height:40px !important; text-align:center; padding:0 !important; box-shadow:none !important; }
    .sf-booking .bk-ret-pax input.bk-own{ background:rgba(251,186,42,.14) !important; font-weight:700; }
    .sf-booking .bk-ret-cards .veh-price{ display:none; }
    .sf-booking .bk-ret-cards{ margin:4px 0 2px; }
    .sf-booking .bk-ret-cards .veh-card.bk-same .veh-photo{ font-size:20px; }
    .sf-booking .bk-ret-vsel{ position:absolute !important; width:1px; height:1px; opacity:0; pointer-events:none; }
</style>
<script>
(function () {
    var form = document.querySelector('.sf-booking .eto-form');
    if (!form) return;

    // Segmented control for journey type, driving the real select.
    var sel = document.getElementById('journey_type');
    if (sel && !sel.dataset.bkSeg) {
        sel.dataset.bkSeg = '1';
        var seg = document.createElement('div');
        seg.className = 'bk-seg';
        seg.setAttribute('role', 'group');
        seg.setAttribute('aria-label', 'Journey type');
        var short = { one_way: 'One way', 'return': 'Return', hourly: 'Hourly' };
        Array.prototype.forEach.call(sel.options, function (o) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = short[o.value] || o.text;
            b.dataset.value = o.value;
            b.addEventListener('click', function () {
                if (sel.value === o.value) return;
                sel.value = o.value;
                sel.dispatchEvent(new Event('change', { bubbles: true }));
                sel.dispatchEvent(new Event('input', { bubbles: true }));
            });
            seg.appendChild(b);
        });
        function syncSeg() {
            Array.prototype.forEach.call(seg.children, function (b) { b.setAttribute('aria-pressed', b.dataset.value === sel.value ? 'true' : 'false'); });
        }
        sel.classList.add('bk-seg-select');
        sel.setAttribute('tabindex', '-1');
        sel.parentNode.insertBefore(seg, sel);
        sel.addEventListener('change', syncSeg);
        syncSeg();
    }

    // Passengers, suitcases and cabin bags in one row.
    var pax = ['passengers', 'suitcases', 'hand_luggage']
        .map(function (id) { var i = document.getElementById(id); return i && i.closest('.stepper-field'); })
        .filter(Boolean);
    if (pax.length === 3 && !pax[0].parentNode.classList.contains('bk-pax')) {
        var row = document.createElement('div');
        row.className = 'bk-pax';
        pax[0].parentNode.insertBefore(row, pax[0]);
        pax.forEach(function (f) { row.appendChild(f); });
    }

    // Return journey: its own card, shown only for a return. The existing script
    // still controls the fields inside (reverse pre-fill, return price, etc.).
    var retDate = document.getElementById('return_field');
    var retLocs = document.getElementById('return_locations');
    var locSec = document.getElementById('pickup_address') && document.getElementById('pickup_address').closest('.eto-section');
    var retSec = null;
    if (retDate && retLocs && locSec && !document.querySelector('.bk-return-sec')) {
        retSec = document.createElement('div');
        retSec.className = 'eto-section bk-return-sec';
        retSec.innerHTML = '<div class="head"><span class="ico">↩</span> Return journey <span class="muted" style="font-weight:500;text-transform:none;letter-spacing:0">(defaults to the reverse)</span></div><div class="body"></div>';
        var rb = retSec.querySelector('.body');
        rb.appendChild(retDate);
        rb.appendChild(retLocs);
        locSec.parentNode.insertBefore(retSec, locSec.nextSibling);
        var syncRet = function () { retSec.hidden = !(sel && sel.value === 'return'); };
        if (sel) sel.addEventListener('change', syncRet);
        syncRet();
    }

    // Return step: the party/luggage follow the outbound until changed.
    var follows = Array.prototype.slice.call(document.querySelectorAll('[data-follow]'));
    var resetBtn = document.getElementById('return-pax-reset');
    function outVal(f) { var o = document.getElementById(f.dataset.follow); return o ? o.value : ''; }
    function markOwn() {
        var any = false;
        follows.forEach(function (f) { var own = f.dataset.own === '1' && f.value !== outVal(f); f.classList.toggle('bk-own', own); any = any || own; });
        if (resetBtn) resetBtn.hidden = !any;
    }
    function follow() {
        follows.forEach(function (f) { if (f.dataset.own !== '1') f.value = outVal(f); });
        markOwn();
        if (typeof fitRet === 'function') fitRet();
    }
    follows.forEach(function (f) {
        if (f.value !== '' && f.value !== outVal(f)) f.dataset.own = '1';  // kept from a failed submit
        var mark = function () { f.dataset.own = f.value !== outVal(f) ? '1' : '0'; markOwn(); if (typeof fitRet === 'function') fitRet(); };
        f.addEventListener('input', mark);
        f.addEventListener('change', mark);
        var o = document.getElementById(f.dataset.follow);
        if (o) { o.addEventListener('input', follow); o.addEventListener('change', follow); }
    });
    if (resetBtn) resetBtn.addEventListener('click', function () { follows.forEach(function (f) { f.dataset.own = '0'; }); follow(); });

    // Return step: vehicle cards (start on "Same as outbound").
    var retVeh = document.getElementById('return_vehicle_type_id');
    var outCards = document.getElementById('veh-cards');
    var retGrid = null;
    function fitRet() {
        if (!retGrid) return;
        var g = function (id) { var e = document.getElementById(id); return parseInt(e && e.value, 10) || 0; };
        var pax = g('return_passengers') || g('passengers'), lug = g('return_suitcases'), hand = g('return_hand_luggage');
        retGrid.querySelectorAll('.veh-card[data-id]').forEach(function (c) {
            if (!c.dataset.id) return;
            var fits = pax <= +c.dataset.capPax && lug <= +c.dataset.capLug && hand <= +c.dataset.capHand;
            c.classList.toggle('unfit', !fits);
        });
    }
    if (retVeh && outCards && !document.querySelector('.bk-ret-cards')) {
        retGrid = document.createElement('div');
        retGrid.className = 'veh-cards bk-ret-cards';
        var same = document.createElement('button');
        same.type = 'button';
        same.className = 'veh-card bk-same';
        same.dataset.id = '';
        same.innerHTML = '<span class="veh-photo">↺</span><span class="veh-info"><span class="veh-name">Same as outbound</span><span class="veh-cap">Uses the vehicle picked for the outbound</span></span><span class="veh-tick">✓</span>';
        retGrid.appendChild(same);
        outCards.querySelectorAll('.veh-card').forEach(function (c) {
            var k = c.cloneNode(true);
            k.classList.remove('sel', 'unfit');
            k.removeAttribute('id');
            k.querySelectorAll('[id]').forEach(function (n) { n.removeAttribute('id'); });
            retGrid.appendChild(k);
        });
        var vField = retVeh.closest('.field');
        vField.insertBefore(retGrid, retVeh);
        retVeh.classList.add('bk-ret-vsel');
        retVeh.setAttribute('tabindex', '-1');
        if (vField.parentNode && vField.parentNode.classList.contains('grid')) vField.style.gridColumn = '1 / -1';
        var syncRet = function () {
            retGrid.querySelectorAll('.veh-card').forEach(function (c) { c.classList.toggle('sel', String(c.dataset.id) === String(retVeh.value)); });
        };
        retGrid.addEventListener('click', function (e) {
            var c = e.target.closest('.veh-card');
            if (!c) return;
            retVeh.value = c.dataset.id;
            retVeh.dispatchEvent(new Event('change', { bubbles: true }));
            syncRet();
        });
        retVeh.addEventListener('change', syncRet);
        syncRet();
    }
    follow();
    fitRet();

    // Step-by-step on phones/tablets: Customer → Journey → Return (only for a
    // return) → Vehicle & extras → Payment & confirm. Desktop keeps one page.
    var root = form.closest('.sf-booking');
    var secs = Array.prototype.slice.call(form.querySelectorAll(':scope > .eto-section'));
    var by = function (id) { var e = document.getElementById(id); return e ? e.closest('.eto-section') : null; };
    var vehSec = by('vehicle_type_id'), extrasSec = by('meet_greet'), paySec = by('payment_method'), custSec = by('customer_name');
    var steps = [
        { title: 'Customer', els: [custSec] },
        { title: 'Journey', els: [locSec] },
        { title: 'Return journey', els: [retSec], onlyReturn: true },
        { title: 'Vehicle & extras', els: [vehSec, extrasSec] },
        { title: 'Payment & confirm', els: [] },
    ];
    var used = [];
    steps.forEach(function (st) { st.els = st.els.filter(Boolean); used = used.concat(st.els); });
    secs.forEach(function (sec) { if (used.indexOf(sec) === -1) steps[4].els.push(sec); });
    var small = window.matchMedia('(max-width: 1099px)');
    var cur = 0;
    var head = document.createElement('div');
    head.className = 'bk-steps-head';
    form.insertBefore(head, form.firstChild);
    var nav = document.createElement('div');
    nav.className = 'bk-step-nav';
    nav.innerHTML = '<button type="button" class="bk-back">← Back</button><button type="button" class="bk-next">Next →</button>';
    var bar = form.querySelector('.total-bar');
    form.insertBefore(nav, bar);

    function active() { return steps.filter(function (st) { return !st.onlyReturn || (sel && sel.value === 'return'); }); }
    function stepValid(st) {
        var bad = null;
        st.els.forEach(function (sec) {
            if (bad) return;
            sec.querySelectorAll('input, select, textarea').forEach(function (el) {
                if (!bad && !el.disabled && el.willValidate && !el.checkValidity()) bad = el;
            });
        });
        if (bad) { bad.reportValidity && bad.reportValidity(); try { bad.focus(); } catch (e) {} }
        return !bad;
    }
    function show(i, scroll) {
        var list = active();
        cur = Math.max(0, Math.min(i, list.length - 1));
        if (!small.matches) {
            root.classList.remove('bk-wizard', 'bk-last');
            secs.concat(retSec ? [retSec] : []).forEach(function (s) { s.classList.remove('bk-off'); });
            return;
        }
        root.classList.add('bk-wizard');
        root.classList.toggle('bk-last', cur === list.length - 1);
        steps.forEach(function (st) { st.els.forEach(function (s) { s.classList.add('bk-off'); }); });
        list[cur].els.forEach(function (s) { s.classList.remove('bk-off'); });
        head.innerHTML = '<div class="bk-dots">' + list.map(function (_, k) { return '<span class="' + (k <= cur ? 'on' : '') + '"></span>'; }).join('')
            + '</div><div class="bk-title">Step ' + (cur + 1) + ' of ' + list.length + ' · ' + list[cur].title
            + (cur < list.length - 1 ? ' <small>· next: ' + list[cur + 1].title + '</small>' : '') + '</div>';
        nav.querySelector('.bk-back').disabled = cur === 0;
        if (scroll) window.scrollTo({ top: Math.max(0, form.getBoundingClientRect().top + window.scrollY - 70), behavior: 'smooth' });
    }
    nav.querySelector('.bk-next').addEventListener('click', function () {
        var list = active();
        if (stepValid(list[cur])) show(cur + 1, true);
    });
    nav.querySelector('.bk-back').addEventListener('click', function () { show(cur - 1, true); });
    if (sel) sel.addEventListener('change', function () { show(cur, false); });
    // If the browser blocks submit on a field in another step, jump to that step.
    form.addEventListener('invalid', function (e) {
        if (!small.matches) return;
        var list = active();
        for (var k = 0; k < list.length; k++) {
            if (list[k].els.some(function (s) { return s.contains(e.target); })) {
                if (k !== cur) { show(k, true); setTimeout(function () { e.target.reportValidity && e.target.reportValidity(); }, 350); }
                break;
            }
        }
    }, true);
    // Server-side errors: open the step with the first error.
    var firstErr = form.querySelector('.error');
    var startAt = 0;
    if (firstErr) {
        var l = active();
        for (var k2 = 0; k2 < l.length; k2++) { if (l[k2].els.some(function (s) { return s.contains(firstErr); })) { startAt = k2; break; } }
    }
    (small.addEventListener ? small.addEventListener('change', function () { show(cur, false); }) : small.addListener(function () { show(cur, false); }));
    show(startAt, false);

    // Mobile total bar: tap the total for details.
    var bar = form.querySelector('.total-bar');
    var main = bar && bar.querySelector('.total-main');
    if (main) {
        main.setAttribute('role', 'button');
        main.setAttribute('tabindex', '0');
        main.setAttribute('aria-label', 'Show or hide the price breakdown');
        var toggle = function () { bar.classList.toggle('bk-open'); };
        main.addEventListener('click', toggle);
        main.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });

        // Close the breakdown when tapping elsewhere or scrolling the form.
        document.addEventListener('click', function (e) { if (!bar.contains(e.target)) bar.classList.remove('bk-open'); });
        var lastY = window.scrollY;
        window.addEventListener('scroll', function () {
            if (Math.abs(window.scrollY - lastY) > 40) { bar.classList.remove('bk-open'); lastY = window.scrollY; }
        }, { passive: true });

        // Phones: hide the bar while typing in a field so it never covers it.
        var small = window.matchMedia('(max-width: 1099px)');
        var hideTimer;
        form.addEventListener('focusin', function (e) {
            if (!small.matches || bar.contains(e.target)) return;
            if (!/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || /checkbox|radio|button|submit/.test(e.target.type || '')) return;
            clearTimeout(hideTimer);
            bar.classList.remove('bk-open');
            bar.classList.add('bk-hide');
        });
        form.addEventListener('focusout', function () {
            clearTimeout(hideTimer);
            hideTimer = setTimeout(function () {
                var a = document.activeElement;
                if (!a || !form.contains(a) || !/^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) bar.classList.remove('bk-hide');
            }, 150);
        });
    }
})();
</script>
