<?php

// Web Push (driver notifications) — VAPID keys identify this server to the
// browser push services. Generate a pair once with `php artisan cet:make-vapid`
// and paste the two lines it prints into your .env. The private key is a secret
// (env only — never committed).
return [
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@centralexecutivetransfers.co.uk'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],
];
