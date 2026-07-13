<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // WhatsApp via Twilio. When the SID/token are absent (local/test), the
    // messaging layer falls back to a "log" transport so the flow still works
    // and every message is recorded in the messages table.
    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
        // Twilio Proxy service (number masking) — the KS… sid from the Proxy
        // console. Masking is a silent no-op until this is set.
        'proxy_service_sid' => env('TWILIO_PROXY_SERVICE_SID'),
    ],

    // Tide payment links. Card details are never stored — we only generate and
    // store the hosted payment link. Falls back to a placeholder link when no
    // API key is configured.
    'tide' => [
        'key' => env('TIDE_API_KEY'),
        'base_url' => env('TIDE_BASE_URL', 'https://pay.tide.co'),
    ],

    // Anthropic Claude — powers the AI pricing engine and other AI features.
    // The model is pinned to claude-opus-4-8 via config/cet.php (ai_model).
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
    ],

    // Google Maps Distance Matrix — distance/duration for quoting.
    'google_maps' => [
        'key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    // Flight status API (e.g. AviationStack) — delay monitoring.
    'flight' => [
        'key' => env('FLIGHT_API_KEY'),
        'base_url' => env('FLIGHT_API_BASE_URL', 'https://api.aviationstack.com/v1'),
    ],

    // Google Ads — daily spend/conversion sync for the ads dashboard.
    'google_ads' => [
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        'customer_id' => env('GOOGLE_ADS_CUSTOMER_ID'),
    ],

    // Number masking — the "switchboard" model. Two permanent CET lines (from
    // your existing 2 Twilio numbers) bridge every job with no per-job cost:
    //   customer_line — give to customers; they ring it → reaches their driver.
    //   driver_line   — on the driver's job screen; they ring it → reaches the customer.
    // Works for unlimited concurrent jobs and can be handed out 24h+ ahead
    // because the numbers never change. proxy_number kept as a legacy fallback.
    'twilio_masking' => [
        'proxy_number' => env('TWILIO_PROXY_NUMBER'),
        'customer_line' => env('TWILIO_CUSTOMER_LINE'),
        'driver_line' => env('TWILIO_DRIVER_LINE'),
    ],

    // Google Calendar — booking events pushed to the company calendar.
    'google_calendar' => [
        'credentials' => env('GOOGLE_CALENDAR_CREDENTIALS'),
        // Hard env override. When false, NOTHING reads or writes the calendar,
        // regardless of the runtime pause setting. Leave unset for normal use.
        'sync_enabled' => env('CALENDAR_SYNC_ENABLED', true),
    ],

    // Microsoft Graph — parse Outlook booking emails into bookings.
    'microsoft_graph' => [
        'client_id' => env('MS_GRAPH_CLIENT_ID'),
        'client_secret' => env('MS_GRAPH_CLIENT_SECRET'),
        'tenant_id' => env('MS_GRAPH_TENANT_ID'),
        'mailbox' => env('MS_GRAPH_MAILBOX', 'bookings@centralexecutivetransfers.co.uk'),
    ],

];
