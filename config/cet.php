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

    // Public link customers are sent to leave a review.
    'review_url' => env('CET_REVIEW_URL', 'https://g.page/r/central-executive-transfers/review'),

    // Shared secret guarding inbound webhooks (e.g. Twilio missed-call).
    'webhook_secret' => env('CET_WEBHOOK_SECRET', ''),
];
