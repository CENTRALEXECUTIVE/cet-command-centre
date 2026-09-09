<?php

/**
 * Central Executive Transfers — company & system configuration.
 *
 * Secrets (API keys/tokens) live ONLY in .env. This file exposes operational
 * configuration and the brand/company constants used across the system.
 */
return [
    'company' => [
        'name' => 'Central Executive Transfers Ltd',
        'number' => '15749931',
        'operator_licence' => 'OP037',
        'licensing_authority' => 'Sheffield City Council',
        'website' => 'centralexecutivetransfers.co.uk',
        // VAT registration number — set CET_VAT_NUMBER in .env once you have it
        // (e.g. "GB123456789"). Shown on customer receipts and VAT invoices.
        'vat_number' => env('CET_VAT_NUMBER', ''),
    ],

    // VAT switched ON the day the company registered. When true, fares are treated
    // as VAT-inclusive for the public (the price the customer sees already
    // contains VAT) and net + VAT is shown separately on corporate invoices.
    'vat_registered' => (bool) env('CET_VAT_REGISTERED', true),

    // Displayed on all customer-facing pages (GDPR requirement).
    'ico_registration_number' => env('CET_ICO_NUMBER', ''),

    // Where "new web booking" office emails go.
    'ops_email' => env('CET_OPS_EMAIL', 'admin@centralexecutivetransfers.co.uk'),

    'brand' => [
        'gold' => '#FBBA2A',
        'black' => '#0b0b0b',
        'font' => 'Inter',
    ],

    'calendar' => [
        'id' => env('CET_CALENDAR_ID', 'admin@centralexecutivetransfers.co.uk'),
        'timezone' => 'Europe/London',
    ],

    'privacy_policy_version' => env('CET_PRIVACY_POLICY_VERSION', '1.0'),

    // GPS data retention (GDPR): pruned automatically after this many days.
    'gps_retention_days' => 90,

    // ETO audit: bookings with a pickup BEFORE this date are historical (they
    // predate the live-calendar era), so the audit classes them "old" and never
    // raises calendar/sync errors for them — they're archive, not live work.
    'audit_cutoff' => env('CET_AUDIT_CUTOFF', '2026-03-01'),

    // AI features use this model exclusively.
    'ai_model' => env('CET_AI_MODEL', 'claude-opus-4-8'),

    // Bump on every deploy that changes CSS/JS — cache-busts the stylesheet
    // link so phones can never render new pages with old styles.
    'asset_version' => '31',

    // Google Ads budget alert thresholds.
    'ads_alert_thresholds' => [
        'conversions' => 40,
        'jobs' => 100,
        'revenue' => 14000,
    ],

    // UK standard VAT rate (20%). Applied system-wide once vat_registered is on:
    // broken out of VAT-inclusive public fares, and added to net corporate invoices.
    'vat_rate' => (float) env('CET_VAT_RATE', 0.20),

    // Flat uplift added to every free-roam (distance) fare to cover VAT — the
    // Price Guide rates are VAT-exclusive; fixed routes were raised in the matrix.
    // (estate_over_executive lives further down with the other pricing rules.)
    'freeroam_vat_uplift' => (float) env('CET_FREEROAM_VAT_UPLIFT', 10),

    // GPS ping cadence while a driver is on an active job.
    'gps_ping_seconds' => 300, // every 5 minutes (fallback/other uses)
    // Live tracking on the job screen streams continuously (watchPosition) but
    // only WRITES to the server at most this often, to keep it smooth without
    // hammering the database.
    'gps_live_seconds' => (int) env('CET_GPS_LIVE_SECONDS', 20),

    // Free waiting time the customer gets once the driver has arrived at the
    // pickup. The waiting timer on the driver's job screen only starts counting
    // billable time AFTER this grace period elapses.
    'waiting_grace_minutes' => (int) env('CET_WAITING_GRACE_MINUTES', 15),

    // Waiting-time CHARGE (GBP per hour, pro-rated per minute) applied only after
    // the free grace above AND any minutes the customer already paid for (the
    // booking's "waiting time included" tick-box). Keyed by vehicle-type slug; any
    // type not listed uses 'default'. £20/hr executive & estate, £30/hr for the
    // bigger vehicles (8-seater, XL, V-Class) and the Rolls.
    'waiting_charge_per_hour' => [
        'default' => (float) env('CET_WAITING_CHARGE_DEFAULT', 20),
        'minibus-8' => (float) env('CET_WAITING_CHARGE_MINIBUS', 30),
        'minibus-8-xl' => (float) env('CET_WAITING_CHARGE_MINIBUS_XL', 30),
        'v-class' => (float) env('CET_WAITING_CHARGE_VCLASS', 30),
        'rolls-royce-ghost' => (float) env('CET_WAITING_CHARGE_ROLLS', 30),
    ],

    // Compliance: how many days before expiry an item becomes "due soon" and a
    // WhatsApp renewal reminder is sent.
    'compliance_warn_days' => 30,

    // Operations number that receives compliance/ops alerts (falls back to the
    // directors' own numbers, then to the log transport).
    'ops_whatsapp' => env('CET_OPS_WHATSAPP', ''),

    // Emergency AUTO-CALL for a job at risk of being missed. When the assigned
    // driver still hasn't set off past the safe time, the watchdog rings this
    // number (repeating until someone answers and presses a key). Silent no-op
    // until a voice-capable Twilio "from" number and a "to" number are set.
    // How long a director's "hold my alerts" toggle lasts before auto-expiring.
    'alerts_hold_minutes' => (int) env('CET_ALERTS_HOLD_MINUTES', 180),

    // Driver "getting ready" checkpoint. Roughly PROMPT minutes before pickup the
    // driver is prompted (button on the job screen + a push) to tap "Getting ready".
    // If they haven't confirmed AND haven't set off by ESCALATE minutes before
    // pickup, the emergency escalation goes live (loud office alert + routed
    // auto-call), so a forgotten job is caught early enough to arrange cover.
    // There is deliberately no "can't make it" option — it's a confirmation, not
    // a decline. Setting off (En Route) auto-confirms it.
    'getting_ready' => [
        'prompt_minutes' => (int) env('CET_GET_READY_PROMPT', 30),
        'escalate_minutes' => (int) env('CET_GET_READY_ESCALATE', 20),
    ],

    // Who to ring — the business line (forwards to the other director on no-answer).
    'office_call_number' => env('CET_OFFICE_CALL_NUMBER', '+447405172435'),
    // Caller ID / "from" — a voice-capable Twilio number. Left blank, it uses the
    // driver switchboard line (see OfficeAlertCall::from()), so no env is needed.
    'alert_call_from' => env('CET_ALERT_CALL_FROM', ''),

    // Minutes after a job is marked complete before the review request is sent.
    'review_delay_minutes' => 30,

    // How far back to backfill review requests for already-completed jobs that
    // never got one (ETO imports, older completions). Kept sane so we don't ask
    // for a review on an ancient trip. Manual-send only — nothing auto-sends.
    'review_backfill_days' => (int) env('CET_REVIEW_BACKFILL_DAYS', 21),

    // Customer messages are only sent during waking hours (08:00–22:00). A
    // reminder whose ideal time falls outside this window is shifted to the
    // nearest edge (before start → start; after end → end) so nothing lands
    // overnight — e.g. a 05:00 night pickup is reminded at 08:00 the day before,
    // never between midnight and the morning.
    'send_window' => [
        'start' => env('CET_SEND_WINDOW_START', '08:00'),
        'end' => env('CET_SEND_WINDOW_END', '22:00'),
    ],

    // Pickup REMINDERS are never scheduled to go out after this time — an evening
    // or late-night pickup (e.g. an 11pm job) would otherwise be reminded at 11pm
    // the day before, which reads as unprofessional. Any reminder whose ideal time
    // is later than this is pulled back to it (e.g. sent by 19:00 instead).
    'reminder_cutoff' => env('CET_REMINDER_CUTOFF', '19:00'),

    // Quote extras (GBP) — mirrors the ETO Item Surcharge price list, so a CET
    // quote matches what ETO would have charged. Editable via env if rates move.
    'surcharges' => [
        'meet_greet' => (float) env('CET_SURCHARGE_MEET_GREET', 10),
        'child_seat' => (float) env('CET_SURCHARGE_CHILD_SEAT', 10),
        'booster_seat' => (float) env('CET_SURCHARGE_BOOSTER_SEAT', 10),
        'infant_seat' => (float) env('CET_SURCHARGE_INFANT_SEAT', 10),
        'wheelchair' => (float) env('CET_SURCHARGE_WHEELCHAIR', 0),
        'waiting_after_landing' => (float) env('CET_SURCHARGE_WAITING', 0),
        'stopover' => (float) env('CET_SURCHARGE_STOPOVER', 10),
        'ribbons_car' => (float) env('CET_SURCHARGE_RIBBONS_CAR', 30),
        'ribbons_minibus' => (float) env('CET_SURCHARGE_RIBBONS_MINIBUS', 50),
    ],

    // Holiday / rush-hour surcharge — mirrors ETO's date-range factor multipliers.
    // Each: label, factor (>1 raises, <1 lowers), and an inclusive date window.
    // The FIRST window that contains the pickup time applies to the base fare.
    'holiday_surcharges' => [
        ['label' => 'Christmas Eve',  'factor' => 1.3, 'from' => '2026-12-24 00:00', 'to' => '2026-12-24 23:59'],
        ['label' => 'Christmas Day',  'factor' => 1.3, 'from' => '2026-12-25 00:00', 'to' => '2026-12-25 23:59'],
        ['label' => 'Boxing Day',     'factor' => 1.3, 'from' => '2026-12-26 00:00', 'to' => '2026-12-26 23:59'],
        ['label' => "New Year's Eve", 'factor' => 1.5, 'from' => '2026-12-31 00:00', 'to' => '2026-12-31 23:59'],
        ['label' => "New Year's Day", 'factor' => 1.5, 'from' => '2027-01-01 00:00', 'to' => '2027-01-01 23:59'],
    ],

    // Estate is always priced at the Executive fare PLUS this uplift (default
    // £10). Derived at quote time so a stored Estate figure can never drift (the
    // ETO matrix had some rows at +£5 by mistake — this makes it always +£10).
    'estate_over_executive' => (float) env('CET_ESTATE_OVER_EXECUTIVE', 10),

    // "Paste a booking": the free deterministic parser always runs first and
    // costs nothing. Set true ONLY if you also want the paid AI to fill gaps
    // on messy unstructured messages (uses the Anthropic API = costs money).
    'intake_use_ai' => env('CET_INTAKE_USE_AI', false),

    // Public link customers are sent to leave a review (the Google review page).
    'review_url' => env('CET_REVIEW_URL', 'https://g.page/r/CYo2748zMiu5EBM/review'),

    // Website shown in the review request sign-off.
    'website' => env('CET_WEBSITE', 'www.centralexecutivetransfers.co.uk'),

    // Shared secret guarding inbound webhooks (e.g. Twilio missed-call).
    'webhook_secret' => env('CET_WEBHOOK_SECRET', ''),

    // Flight delay (minutes) that triggers an automatic pickup adjustment, and
    // how many hours BEFORE pickup to start watching a flight. A tighter window
    // means far fewer API calls — enough to stay inside a free flight-data tier.
    'flight_delay_threshold' => 15,
    'flight_monitor_window_hours' => env('CET_FLIGHT_WINDOW_HOURS', 6),

    // Driver "home base" for the set-off watchdog. When a driver hasn't shared
    // live GPS yet, the watchdog estimates the drive from here (Sheffield) to the
    // pickup and adds a 10-minute buffer, so the "time to set off" nudge fires at
    // the right time (a distant airport gets a long head start; a local pickup a
    // short one). Background only — never shown in the UI. Env-overridable.
    'base' => [
        'lat' => (float) env('CET_BASE_LAT', 53.3811),   // Sheffield city centre
        'lng' => (float) env('CET_BASE_LNG', -1.4701),
        'buffer_minutes' => (int) env('CET_BASE_BUFFER', 10), // "Sheffield + 10 mins"
    ],
];
