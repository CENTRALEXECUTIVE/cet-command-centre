<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'CET Command Centre')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <header class="topbar">
        <a href="{{ route('dashboard') }}" class="brand">
            <span class="dot"></span>
            <span>CENTRAL <span class="gold">EXECUTIVE</span> TRANSFERS</span>
        </a>
        <nav class="topnav">
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
            @if(auth()->user()->isAdmin())
                <a href="{{ route('despatch.board') }}" class="{{ request()->routeIs('despatch.*') ? 'active' : '' }}">Despatch</a>
            @endif
            @if(auth()->user()->isAdmin() || auth()->user()->isDriver())
                <a href="{{ route('driver.jobs') }}" class="{{ request()->routeIs('driver.*') ? 'active' : '' }}">My Jobs</a>
            @endif
            <a href="{{ route('bookings.index') }}" class="{{ request()->routeIs('bookings.index') ? 'active' : '' }}">Bookings</a>
            @if(auth()->user()->isAdmin() || auth()->user()->isCorporateClient())
                <a href="{{ route('quotes.create') }}" class="{{ request()->routeIs('quotes.*') ? 'active' : '' }}">Quote</a>
                <a href="{{ route('bookings.create') }}" class="{{ request()->routeIs('bookings.create') ? 'active' : '' }}">New Booking</a>
                <a href="{{ route('invoices.index') }}" class="{{ request()->routeIs('invoices.*') ? 'active' : '' }}">Invoices</a>
            @endif
            @if(auth()->user()->isAdmin())
                <a href="{{ route('compliance.index') }}" class="{{ request()->routeIs('compliance.*') ? 'active' : '' }}">Compliance</a>
                <a href="{{ route('reports.revenue') }}" class="{{ request()->routeIs('reports.revenue') ? 'active' : '' }}">Reports</a>
                <a href="{{ route('reports.ads') }}" class="{{ request()->routeIs('reports.ads') ? 'active' : '' }}">Ads</a>
            @endif
            @if(auth()->user()->isCorporateClient())
                <a href="{{ route('corporate.statement') }}" class="{{ request()->routeIs('corporate.*') ? 'active' : '' }}">Account</a>
            @endif
            <form method="POST" action="{{ route('logout') }}" style="display:inline">
                @csrf
                <button type="submit" class="btn btn-ghost" style="padding:6px 14px;font-size:13px">Sign out</button>
            </form>
        </nav>
    </header>

    <main class="container">
        @if(session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @yield('content')
    </main>

    <footer class="site-footer">
        {{ config('cet.company.name') }} &middot; Company No. {{ config('cet.company.number') }} &middot;
        Operator Licence {{ config('cet.company.operator_licence') }} ({{ config('cet.company.licensing_authority') }})<br>
        @if(config('cet.ico_registration_number'))
            <span class="ico">ICO Registration: {{ config('cet.ico_registration_number') }}</span> &middot;
        @endif
        <a href="#">Privacy Notice</a> &middot; Your data is processed under UK GDPR.
    </footer>
</body>
</html>
