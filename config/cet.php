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
    ],

    // Displayed on all customer-facing pages (GDPR requirement).
    'ico_registration_number' => env('CET_ICO_NUMBER', ''),

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

    // AI features use this model exclusively.
    'ai_model' => env('CET_AI_MODEL', 'claude-opus-4-8'),

    // Bump on every deploy that changes CSS/JS — cache-busts the stylesheet
    // link so phones can never render new pages with old styles.
    'asset_version' => '19',

    // Google Ads budget alert thresholds.
    'ads_alert_thresholds' => [
        'conversions' => 40,
        'jobs' => 100,
        'revenue' => 14000,
    ],

    // UK VAT rate applied to corporate account invoices.
    'vat_rate' => 0.20,

    // GPS ping cadence while a driver is on an active job.
    'gps_ping_seconds' => 300, // every 5 minutes (fallback/other uses)
    // Live tracking on the job screen streams continuously (watchPosition) but
    // only WRITES to the server at most this often, to keep it smooth without
    // hammering the database.
    'gps_live_seconds' => (int) env('CET_GPS_LIVE_SECONDS', 20),

    // Compliance: how many days before expiry an item becomes "due soon" and a
    // WhatsApp renewal reminder is sent.
    'compliance_warn_days' => 30,

    // Operations number that receives compliance/ops alerts (falls back to the
    // directors' own numbers, then to the log transport).
    'ops_whatsapp' => env('CET_OPS_WHATSAPP', ''),

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
    ],

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

    // Flight delay (minutes) that triggers an automatic pickup adjustment.
    'flight_delay_threshold' => 15,
];
