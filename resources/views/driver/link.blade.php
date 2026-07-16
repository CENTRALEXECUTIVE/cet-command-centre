<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Your job · Central Executive Transfers</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('cet.asset_version') }}">
    @include('partials.pwa')
</head>
<body class="driver-app">
    <div class="link-topbar">
        <span class="brand"><span class="dot"></span> CENTRAL <span class="gold">EXECUTIVE</span></span>
        <span class="link-tag">Driver job</span>
    </div>

    <main class="container" style="max-width:640px;padding:18px 16px 48px">
        @if(session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @include('driver._job', [
            'linkMode' => true,
            'statusUrl' => route('driver.link.status', $token),
            'locationStoreUrl' => route('driver.link.location', $token),
        ])

        <p class="hint" style="text-align:center;margin-top:20px">Private link for this job · Central Executive Transfers</p>
    </main>
</body>
</html>
