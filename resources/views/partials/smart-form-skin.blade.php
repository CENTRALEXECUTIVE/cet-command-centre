{{-- Premium "smart form" skin for the internal New Booking + Instant Quote pages,
     mirroring the customer booking widget (dark branded hero, cream inputs, gold
     gradient CTA). Scoped under .smart-form so pages that share .eto-section are
     untouched. Keeps every existing field id/name + JS hook. --}}
<style>
    .smart-form{
        --sf-gold:#FBBA2A; --sf-gold-deep:#E9A413; --sf-ink:#0b0b0c;
        --sf-cream:#fbfaf6; --sf-line:#e9e7e0; --sf-muted:#6a6a70;
    }

    /* ── Branded hero ──────────────────────────────────────────────── */
    .smart-hero{
        position:relative; overflow:hidden; margin:0 0 18px; border-radius:18px;
        padding:20px 24px 22px; color:#fff;
        background:
            radial-gradient(600px 200px at 12% -40%, rgba(251,186,42,.30), transparent 60%),
            linear-gradient(135deg,#17171a 0%, #0b0b0c 100%);
        box-shadow:0 24px 60px -30px rgba(0,0,0,.55);
    }
    .smart-hero::after{ content:""; position:absolute; left:0; right:0; bottom:0; height:3px;
        background:linear-gradient(90deg, var(--sf-gold), var(--sf-gold-deep)); }
    .smart-hero .brand{ display:flex; align-items:center; gap:10px; margin-bottom:12px; }
    .smart-hero .mark{ width:34px; height:34px; border-radius:9px; background:var(--sf-gold);
        color:#0b0b0c; font-family:Georgia,serif; font-weight:800; font-size:22px; display:grid; place-items:center; flex:0 0 auto; }
    .smart-hero .brand .name{ font-weight:800; letter-spacing:1.5px; font-size:14px; }
    .smart-hero .brand .name span{ color:var(--sf-gold); }
    .smart-hero .eyebrow{ color:var(--sf-gold); font-weight:800; letter-spacing:2px; font-size:11px; text-transform:uppercase; }
    .smart-hero h1{ margin:6px 0 4px; font-size:26px; font-weight:800; letter-spacing:-.5px; color:#fff; }
    .smart-hero p{ margin:0; color:#c7c6c0; font-size:14px; max-width:62ch; }
    .smart-hero .pill{ display:inline-flex; align-items:center; gap:6px; margin-top:12px;
        background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.2); border-radius:999px;
        padding:5px 12px; font-size:12px; font-weight:600; color:#f4f2ec; }

    /* ── Sections as clean white cards (no heavy black bars) ───────── */
    .smart-form .eto-section{
        background:#fff; border:1px solid var(--sf-line); border-radius:16px;
        box-shadow:0 1px 2px rgba(10,12,18,.04), 0 12px 30px -22px rgba(10,12,18,.20);
        margin-bottom:16px; overflow:hidden;
    }
    .smart-form .eto-section > .head{
        background:#fff; color:var(--sf-ink); font-weight:800; font-size:12.5px;
        letter-spacing:.8px; text-transform:uppercase; padding:15px 18px 11px;
        display:flex; align-items:center; gap:9px; border-bottom:1px solid var(--sf-line);
    }
    .smart-form .eto-section > .head .ico{ font-size:15px; filter:saturate(1.1); }
    .smart-form .eto-section > .head .chev{ margin-left:auto; color:var(--sf-muted); }
    .smart-form .eto-section > .body{ padding:16px 18px; }

    /* ── Fields ────────────────────────────────────────────────────── */
    .smart-form label{ font-size:12.5px; font-weight:600; color:#3a3a40; }
    .smart-form .field{ margin-bottom:14px; }
    .smart-form .hint{ color:var(--sf-muted); font-size:12px; margin-top:5px; }
    .smart-form input, .smart-form select, .smart-form textarea{
        width:100%; padding:12px 13px; border:1px solid var(--sf-line); border-radius:11px;
        font-size:15px; background:var(--sf-cream); font-family:inherit; color:var(--sf-ink);
        transition:border-color .15s, box-shadow .15s, background .15s;
    }
    .smart-form input::placeholder, .smart-form textarea::placeholder{ color:#b3b2ad; }
    .smart-form input:focus, .smart-form select:focus, .smart-form textarea:focus{
        outline:none; border-color:var(--sf-gold); background:#fff; box-shadow:0 0 0 4px rgba(251,186,42,.18); }
    .smart-form input[type=checkbox], .smart-form input[type=radio]{ width:auto; }
    .smart-form .checkbox-row{ display:flex; align-items:center; gap:8px; }
    .smart-form .checkbox-row label{ margin:0; }
    .smart-form .req{ color:#c02626; }

    /* A/B location pins */
    .smart-form .loc-row{ display:flex; gap:10px; align-items:flex-start; }
    .smart-form .loc-row .grow{ flex:1; min-width:0; }
    .smart-form .pin{ flex:0 0 auto; width:34px; height:34px; border-radius:50%; display:grid; place-items:center;
        color:#fff; font-weight:800; font-size:14px; margin-top:3px; }
    .smart-form .pin.pickup{ background:#1f8b4c; }
    .smart-form .pin.drop{ background:#c0392b; }

    /* ── Live all-vehicle price chips ──────────────────────────────── */
    .smart-form .veh-prices{ display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
    .smart-form .veh-prices .vp{ display:flex; align-items:center; gap:8px; border:1px solid var(--sf-line);
        border-radius:11px; padding:9px 13px; background:var(--sf-cream); cursor:pointer; font-size:13px;
        transition:border-color .12s, box-shadow .12s, background .12s; }
    .smart-form .veh-prices .vp:hover{ border-color:var(--sf-gold); background:#fff; }
    .smart-form .veh-prices .vp.sel{ border-color:var(--sf-gold); background:#fff; box-shadow:0 0 0 3px rgba(251,186,42,.18); }
    .smart-form .veh-prices .vp .n{ font-weight:600; } .smart-form .veh-prices .vp .p{ font-weight:800; }
    .smart-form .veh-prices .vp .p.poa{ font-weight:600; color:var(--sf-muted); }

    /* ── Sticky premium price/submit bar ───────────────────────────── */
    .smart-form .total-bar{
        position:sticky; bottom:12px; z-index:6; display:flex; align-items:center; justify-content:space-between;
        gap:16px; margin-top:6px; padding:14px 18px; border-radius:16px; color:#fff; border:1px solid #23232a;
        background:linear-gradient(135deg,#17171a 0%, #0b0b0c 100%);
        box-shadow:0 20px 44px -20px rgba(0,0,0,.6);
    }
    .smart-form .total-bar .total-label{ font-size:11px; letter-spacing:1px; text-transform:uppercase; color:#b7b6b0; }
    .smart-form .total-bar .total-amount{ font-size:28px; font-weight:800; color:var(--sf-gold); line-height:1.05; }
    .smart-form .total-bar .total-amount .basis{ display:block; font-size:12px; font-weight:500; color:#b7b6b0; letter-spacing:0; }
    .smart-form .total-bar .actions{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .smart-form .total-bar .btn-primary{
        background:linear-gradient(135deg,var(--sf-gold),var(--sf-gold-deep)); color:#0b0b0c; font-weight:800;
        border:0; padding:14px 22px; border-radius:12px; font-size:15px; cursor:pointer;
        box-shadow:0 12px 26px -10px rgba(233,164,19,.75); transition:transform .08s, box-shadow .15s; }
    .smart-form .total-bar .btn-primary:hover{ box-shadow:0 16px 32px -10px rgba(233,164,19,.9); }
    .smart-form .total-bar .btn-primary:active{ transform:translateY(1px); }
    .smart-form .total-bar .btn-light, .smart-form .total-bar .btn-ghost{
        background:rgba(255,255,255,.12); color:#fff; border:1px solid rgba(255,255,255,.22);
        padding:12px 16px; border-radius:11px; font-weight:700; font-size:14px; cursor:pointer; }

    /* Plain cards (intake paste/preview) get the same premium surface. */
    .smart-form .card{ background:#fff; border:1px solid var(--sf-line); border-radius:16px;
        box-shadow:0 1px 2px rgba(10,12,18,.04), 0 12px 30px -22px rgba(10,12,18,.20); padding:18px; }

    /* Primary buttons anywhere in a smart form use the gold gradient. */
    .smart-form .btn-primary{
        background:linear-gradient(135deg,var(--sf-gold),var(--sf-gold-deep)); color:#0b0b0c; font-weight:800;
        border:0; padding:12px 20px; border-radius:11px; font-size:15px; cursor:pointer;
        box-shadow:0 10px 22px -12px rgba(233,164,19,.7); transition:transform .08s, box-shadow .15s; }
    .smart-form .btn-primary:hover{ box-shadow:0 14px 28px -12px rgba(233,164,19,.9); }
    .smart-form .btn-primary:active{ transform:translateY(1px); }

    /* Collapsible advanced sections */
    .smart-form .eto-section.collapsible.closed > .body{ display:none; }
    .smart-form .eto-section.collapsible > .head{ cursor:pointer; }

    @media (max-width:560px){
        .smart-hero h1{ font-size:22px; }
        .smart-form .total-bar{ flex-direction:column; align-items:stretch; }
        .smart-form .total-bar .actions{ justify-content:stretch; }
        .smart-form .total-bar .btn-primary{ flex:1; text-align:center; }
    }
</style>
