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

    // Google Ads budget alert thresholds.
    'ads_alert_thresholds' => [
        'conversions' => 40,
        'jobs' => 100,
        'revenue' => 14000,
    ],

    // UK VAT rate applied to corporate account invoices.
    'vat_rate' => 0.20,

    // GPS ping cadence while a driver is on an active job.
    'gps_ping_seconds' => 300, // every 5 minutes

    // Compliance: how many days before expiry an item becomes "due soon" and a
    // WhatsApp renewal reminder is sent.
    'compliance_warn_days' => 30,

    // Operations number that receives compliance/ops alerts (falls back to the
    // directors' own numbers, then to the log transport).
    'ops_whatsapp' => env('CET_OPS_WHATSAPP', ''),

    // Minutes after a job is marked complete before the review request is sent.
    'review_delay_minutes' => 30,

    // Customer messages are only sent during waking hours (08:00–22:00). A
    // reminder whose ideal time falls outside this window is shifted to the
    // nearest edge (before start → start; after end → end) so nothing lands
    // overnight — e.g. a 05:00 night pickup is reminded at 08:00 the day before,
    // never between midnight and the morning.
    'send_window' => [
        'start' => env('CET_SEND_WINDOW_START', '08:00'),
        'end' => env('CET_SEND_WINDOW_END', '22:00'),
    ],

    // Public link customers are sent to leave a review.
    'review_url' => env('CET_REVIEW_URL', 'https://g.page/r/central-executive-transfers/review'),

    // Shared secret guarding inbound webhooks (e.g. Twilio missed-call).
    'webhook_secret' => env('CET_WEBHOOK_SECRET', ''),

    // Flight delay (minutes) that triggers an automatic pickup adjustment.
    'flight_delay_threshold' => 15,
];
