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
@php $u = auth()->user(); @endphp
<input type="checkbox" id="nav-toggle" class="nav-toggle-cb" hidden>
<div class="app-shell">
    <aside class="sidebar">
        <a href="{{ route('dashboard') }}" class="side-brand">
            <span class="dot"></span>
            <span>CENTRAL <span class="gold">EXECUTIVE</span></span>
        </a>

        <nav class="side-nav">
            <div class="nav-group">
                <div class="nav-group-title">Operations</div>
                <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
                @if($u->isAdmin())
                    <a href="{{ route('review.index') }}" class="{{ request()->routeIs('review.*') ? 'active' : '' }}">Review</a>
                    <a href="{{ route('despatch.board') }}" class="{{ request()->routeIs('despatch.*') ? 'active' : '' }}">Dispatch board</a>
                    <a href="{{ route('fleet.map') }}" class="{{ request()->routeIs('fleet.*') ? 'active' : '' }}">Live map</a>
                @endif
                @if($u->isAdmin() || $u->isDriver())
                    <a href="{{ route('driver.jobs') }}" class="{{ request()->routeIs('driver.jobs') || request()->routeIs('driver.job') || request()->routeIs('driver.earnings') ? 'active' : '' }}">My jobs</a>
                    <a href="{{ route('driver.documents') }}" class="{{ request()->routeIs('driver.documents*') ? 'active' : '' }}">My documents</a>
                @endif
                <a href="{{ route('bookings.index') }}" class="{{ request()->routeIs('bookings.index') || request()->routeIs('bookings.show') ? 'active' : '' }}">Bookings</a>
                @if($u->isAdmin())
                    <a href="{{ route('enquiries.index') }}" class="{{ request()->routeIs('enquiries.*') ? 'active' : '' }}">Enquiries</a>
                    <a href="{{ route('customers.index') }}" class="{{ request()->routeIs('customers.*') ? 'active' : '' }}">Customers</a>
                @endif
            </div>

            @if($u->isAdmin() || $u->isCorporateClient())
                <div class="nav-group">
                    <div class="nav-group-title">Sales</div>
                    <a href="{{ route('quotes.create') }}" class="{{ request()->routeIs('quotes.*') ? 'active' : '' }}">New quote</a>
                    <a href="{{ route('bookings.create') }}" class="{{ request()->routeIs('bookings.create') ? 'active' : '' }}">New booking</a>
                    <a href="{{ route('invoices.index') }}" class="{{ request()->routeIs('invoices.*') ? 'active' : '' }}">Invoices</a>
                    @if($u->isAdmin())
                        <a href="{{ route('payments.index') }}" class="{{ request()->routeIs('payments.*') ? 'active' : '' }}">Payments</a>
                    @endif
                    @if($u->isAdmin())
                        <a href="{{ route('pricing.index') }}" class="{{ request()->routeIs('pricing.*') ? 'active' : '' }}">Pricing</a>
                    @endif
                    @if($u->isCorporateClient())
                        <a href="{{ route('corporate.statement') }}" class="{{ request()->routeIs('corporate.*') ? 'active' : '' }}">My account</a>
                    @endif
                </div>
            @endif

            @if($u->isAdmin())
                <div class="nav-group">
                    <div class="nav-group-title">Marketing</div>
                    <a href="{{ route('marketing.ads') }}" class="{{ request()->routeIs('marketing.ads') ? 'active' : '' }}">Google Ads</a>
                    <a href="{{ route('marketing.keywords') }}" class="{{ request()->routeIs('marketing.keywords*') ? 'active' : '' }}">Keywords</a>
                    <a href="{{ route('marketing.seo') }}" class="{{ request()->routeIs('marketing.seo*') ? 'active' : '' }}">SEO</a>
                    <a href="{{ route('reports.revenue') }}" class="{{ request()->routeIs('reports.revenue') ? 'active' : '' }}">Revenue reports</a>
                </div>

                <div class="nav-group">
                    <div class="nav-group-title">Fleet &amp; admin</div>
                    <a href="{{ route('compliance.index') }}" class="{{ request()->routeIs('compliance.*') ? 'active' : '' }}">Compliance</a>
                    <a href="{{ route('driver-documents.index') }}" class="{{ request()->routeIs('driver-documents.*') ? 'active' : '' }}">Driver documents</a>
                    <a href="{{ route('cover-drivers.index') }}" class="{{ request()->routeIs('cover-drivers.*') ? 'active' : '' }}">Drivers directory</a>
                    <a href="{{ route('imports.index') }}" class="{{ request()->routeIs('imports.*') ? 'active' : '' }}">Imports</a>
                    <a href="{{ route('settings.index') }}" class="{{ request()->routeIs('settings.*') ? 'active' : '' }}">Settings</a>
                    <a href="{{ route('waiting-list.index') }}" class="{{ request()->routeIs('waiting-list.*') ? 'active' : '' }}">Waiting list</a>
                    <a href="{{ route('gdpr.erasure') }}" class="{{ request()->routeIs('gdpr.*') ? 'active' : '' }}">GDPR</a>
                </div>
            @endif
        </nav>
    </aside>

    <div class="content-area">
        <header class="topbar">
            <label for="nav-toggle" class="nav-burger" aria-label="Menu">&#9776;</label>
            <div class="topbar-title">@yield('title', 'CET Command Centre')</div>
            <div class="topbar-user">
                <span class="who">{{ $u->name }} <span class="role">{{ $u->role->label() }}</span></span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-ghost" style="padding:6px 14px;font-size:13px">Sign out</button>
                </form>
            </div>
        </header>

        <main class="container">
            @if(session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            @yield('content')

            <footer class="site-footer">
                {{ config('cet.company.name') }} &middot; Company No. {{ config('cet.company.number') }} &middot;
                Operator Licence {{ config('cet.company.operator_licence') }}
                @if(config('cet.ico_registration_number')) &middot; ICO {{ config('cet.ico_registration_number') }} @endif
                &middot; UK GDPR
            </footer>
        </main>
    </div>

    <label for="nav-toggle" class="nav-scrim"></label>
</div>

@include('partials.cookie-consent')
</body>
</html>
