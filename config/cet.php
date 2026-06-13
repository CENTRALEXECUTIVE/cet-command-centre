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
];
