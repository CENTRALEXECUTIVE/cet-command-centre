<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    {{-- Theme boots before first paint so there's no light/dark flash. Follows
         the phone's light/dark setting unless the user picked one with the toggle. --}}
    <script>try {
        var t = localStorage.getItem('cet-theme');
        if (!t && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) t = 'dark';
        if (t === 'dark') document.documentElement.dataset.theme = 'dark';
    } catch (e) {}</script>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'CET Command Centre')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('cet.asset_version') }}">
    @include('partials.pwa')
</head>
<body class="{{ request()->routeIs('driver.job') || request()->routeIs('driver.jobs') ? 'driver-app' : '' }}">
@php $u = auth()->user(); @endphp
<input type="checkbox" id="nav-toggle" class="nav-toggle-cb" hidden>
<div class="app-shell">
    <aside class="sidebar">
        <a href="{{ route('dashboard') }}" class="side-brand">
            <span class="dot"></span>
            <span>CENTRAL <span class="gold">EXECUTIVE</span></span>
        </a>

        <nav class="side-nav">
            {{-- Daily essentials — always visible, no clutter. Everything else
                 folds into groups below (auto-open on the section you're in). --}}
            <div class="nav-group">
                <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">🏠 Dashboard</a>
                @if($u->isAdmin() || $u->isCorporateClient())
                    <a href="{{ route('bookings.index') }}" class="{{ request()->routeIs('bookings.index') || request()->routeIs('bookings.show') ? 'active' : '' }}">📋 Bookings</a>
                @endif
                @if($u->isAdmin())
                    <a href="{{ route('despatch.board') }}" class="{{ request()->routeIs('despatch.*') ? 'active' : '' }}">🚦 Dispatch board</a>
                    <a href="{{ route('fleet.map') }}" class="{{ request()->routeIs('fleet.*') ? 'active' : '' }}">🗺 Live map</a>
                @endif
                @if($u->isAdmin() || $u->isCorporateClient())
                    <a href="{{ route('bookings.create') }}" class="{{ request()->routeIs('bookings.create') ? 'active' : '' }}">➕ New booking</a>
                @endif
                @if($u->isAdmin())
                    <a href="{{ route('intake.index') }}" class="{{ request()->routeIs('intake.*') ? 'active' : '' }}">📥 Paste a booking</a>
                @endif
                @if($u->isAdmin() || $u->isDriver())
                    <a href="{{ route('driver.jobs') }}" class="{{ request()->routeIs('driver.jobs') || request()->routeIs('driver.job') || request()->routeIs('driver.earnings') ? 'active' : '' }}">🚘 My jobs</a>
                @endif
                @if($u->isAdmin())
                    <a href="{{ route('payroll.index') }}" class="{{ request()->routeIs('payroll.*') ? 'active' : '' }}">💷 Payroll</a>
                @endif
                @if($u->isDriver() && ! $u->isAdmin())
                    <a href="{{ route('driver.documents') }}" class="{{ request()->routeIs('driver.documents*') ? 'active' : '' }}">📄 My documents</a>
                @endif
            </div>

            @if($u->isCorporateClient())
                <div class="nav-group">
                    <a href="{{ route('quotes.create') }}" class="{{ request()->routeIs('quotes.*') ? 'active' : '' }}">New quote</a>
                    <a href="{{ route('invoices.index') }}" class="{{ request()->routeIs('invoices.*') ? 'active' : '' }}">Invoices</a>
                    <a href="{{ route('corporate.statement') }}" class="{{ request()->routeIs('corporate.*') ? 'active' : '' }}">My account</a>
                </div>
            @endif

            @if($u->isAdmin())
                @php
                    $inSales = request()->routeIs('quotes.*', 'enquiries.*', 'customers.*', 'invoices.*', 'payments.*', 'pricing.*', 'waiting-list.*');
                    $inMarketing = request()->routeIs('review.*', 'marketing.*', 'reports.*');
                    $inFleet = request()->routeIs('compliance.*', 'driver-documents.*', 'cover-drivers.*', 'rotation.*', 'driver.documents*');
                    $inAdmin = request()->routeIs('imports.*', 'audit.*', 'users.*', 'settings.*', 'gdpr.*');
                @endphp

                <details class="nav-fold" @if($inSales) open @endif>
                    <summary>Sales &amp; customers</summary>
                    <a href="{{ route('quotes.create') }}" class="{{ request()->routeIs('quotes.*') ? 'active' : '' }}">New quote</a>
                    <a href="{{ route('enquiries.index') }}" class="{{ request()->routeIs('enquiries.*') ? 'active' : '' }}">Enquiries</a>
                    <a href="{{ route('customers.index') }}" class="{{ request()->routeIs('customers.*') ? 'active' : '' }}">Customers</a>
                    <a href="{{ route('invoices.index') }}" class="{{ request()->routeIs('invoices.*') ? 'active' : '' }}">Invoices</a>
                    <a href="{{ route('payments.index') }}" class="{{ request()->routeIs('payments.*') ? 'active' : '' }}">Payments</a>
                    <a href="{{ route('pricing.index') }}" class="{{ request()->routeIs('pricing.*') ? 'active' : '' }}">Pricing</a>
                    <a href="{{ route('waiting-list.index') }}" class="{{ request()->routeIs('waiting-list.*') ? 'active' : '' }}">Waiting list</a>
                </details>

                <details class="nav-fold" @if($inMarketing) open @endif>
                    <summary>Marketing &amp; reports</summary>
                    <a href="{{ route('marketing.studio') }}" class="{{ request()->routeIs('marketing.studio*') ? 'active' : '' }}">🎨 Marketing Studio</a>
                    <a href="{{ route('review.index') }}" class="{{ request()->routeIs('review.*') ? 'active' : '' }}">Review</a>
                    <a href="{{ route('marketing.ads') }}" class="{{ request()->routeIs('marketing.ads') ? 'active' : '' }}">Google Ads</a>
                    <a href="{{ route('marketing.keywords') }}" class="{{ request()->routeIs('marketing.keywords*') ? 'active' : '' }}">Keywords</a>
                    <a href="{{ route('marketing.seo') }}" class="{{ request()->routeIs('marketing.seo*') ? 'active' : '' }}">SEO</a>
                    <a href="{{ route('reports.profit') }}" class="{{ request()->routeIs('reports.profit') ? 'active' : '' }}">💰 Profit</a>
                    <a href="{{ route('reports.revenue') }}" class="{{ request()->routeIs('reports.revenue') ? 'active' : '' }}">Revenue reports</a>
                </details>

                <details class="nav-fold" @if($inFleet) open @endif>
                    <summary>Fleet &amp; drivers</summary>
                    <a href="{{ route('compliance.index') }}" class="{{ request()->routeIs('compliance.*') ? 'active' : '' }}">Compliance</a>
                    <a href="{{ route('driver-documents.index') }}" class="{{ request()->routeIs('driver-documents.*') ? 'active' : '' }}">Driver documents</a>
                    <a href="{{ route('cover-drivers.index') }}" class="{{ request()->routeIs('cover-drivers.*') ? 'active' : '' }}">Drivers directory</a>
                    <a href="{{ route('rotation.index') }}" class="{{ request()->routeIs('rotation.*') ? 'active' : '' }}">Driver rotation</a>
                    <a href="{{ route('driver.documents') }}" class="{{ request()->routeIs('driver.documents*') ? 'active' : '' }}">My documents</a>
                </details>

                <details class="nav-fold" @if($inAdmin) open @endif>
                    <summary>Admin &amp; data</summary>
                    <a href="{{ route('imports.index') }}" class="{{ request()->routeIs('imports.*') ? 'active' : '' }}">Imports</a>
                    <a href="{{ route('audit.index') }}" class="{{ request()->routeIs('audit.*') ? 'active' : '' }}">ETO audit</a>
                    <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}">Users</a>
                    <a href="{{ route('settings.index') }}" class="{{ request()->routeIs('settings.*') ? 'active' : '' }}">Settings</a>
                    @if($u->isSuperAdmin())
                        <a href="{{ route('notifications.index') }}" class="{{ request()->routeIs('notifications.*') ? 'active' : '' }}">Notifications</a>
                    @endif
                    <a href="{{ route('gdpr.erasure') }}" class="{{ request()->routeIs('gdpr.*') ? 'active' : '' }}">GDPR</a>
                </details>
            @endif
        </nav>
    </aside>

    <div class="content-area">
        <header class="topbar">
            <label for="nav-toggle" class="nav-burger" aria-label="Menu">&#9776;</label>
            <a href="{{ route('dashboard') }}" class="topbar-home" title="Home" aria-label="Home"
               style="text-decoration:none;font-size:19px;line-height:1;padding:4px 8px;border-radius:8px">🏠</a>
            <div class="topbar-title">@yield('title', 'CET Command Centre')</div>
            @if($u->isAdmin())
                @php $unackCritical = \App\Models\WatchdogEvent::criticalCount(); @endphp
                <a href="{{ route('dashboard') }}" class="alert-bell" title="Unacknowledged critical alerts">
                    🛰<span class="alert-badge" data-alert-badge
                        style="{{ $unackCritical > 0 ? '' : 'display:none' }}">{{ $unackCritical ?: '' }}</span>
                </a>
                <form method="GET" action="{{ route('bookings.index') }}" role="search"
                      style="flex:1;max-width:340px;margin:0 12px;min-width:110px">
                    <input type="search" id="global-search" name="q" value="{{ request('q') }}" placeholder="🔍 Search bookings…  ( / )"
                           aria-label="Search bookings"
                           style="width:100%;padding:7px 12px;border:1px solid rgba(128,128,128,.4);border-radius:8px;font-size:13px;background:#fff;color:#111">
                </form>
                <script>
                    // Press "/" anywhere (outside a field) to jump to search.
                    document.addEventListener('keydown', function (e) {
                        if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey) return;
                        var t = e.target, tag = (t.tagName || '').toLowerCase();
                        if (tag === 'input' || tag === 'textarea' || tag === 'select' || t.isContentEditable) return;
                        var box = document.getElementById('global-search');
                        if (box) { e.preventDefault(); box.focus(); box.select(); }
                    });
                </script>
            @endif
            <div class="topbar-user">
                <button type="button" class="theme-toggle" id="theme-toggle" title="Switch light / dark mode">🌙</button>
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
                <div class="foot-brand"><strong>Central Executive Transfers</strong> &mdash; &ldquo;Driven by Excellence&rdquo;</div>
                <div class="foot-copy">&copy; {{ date('Y') }} Central Executive Transfers. All rights reserved.</div>
            </footer>
        </main>
    </div>

    {{-- Bottom tab bar (phones only) — native-style nav, main sections always a
         thumb away. Hidden on the single-job screen, which has its own action bar. --}}
    @unless(request()->routeIs('driver.job'))
    <nav class="tabbar" aria-label="Primary">
        @if($u->isAdmin())
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}"><span class="ico">🏠</span>Home</a>
            <a href="{{ route('despatch.board') }}" class="{{ request()->routeIs('despatch.*') ? 'active' : '' }}"><span class="ico">🚦</span>Dispatch</a>
            <a href="{{ route('bookings.index') }}" class="{{ request()->routeIs('bookings.index','bookings.show','bookings.create') ? 'active' : '' }}"><span class="ico">📋</span>Bookings</a>
            <a href="{{ route('fleet.map') }}" class="{{ request()->routeIs('fleet.*') ? 'active' : '' }}"><span class="ico">🗺</span>Fleet</a>
        @elseif($u->isDriver())
            <a href="{{ route('driver.jobs') }}" class="{{ request()->routeIs('driver.jobs','driver.job') ? 'active' : '' }}"><span class="ico">🚘</span>Jobs</a>
            <a href="{{ route('driver.earnings') }}" class="{{ request()->routeIs('driver.earnings') ? 'active' : '' }}"><span class="ico">💷</span>Earnings</a>
            <a href="{{ route('driver.documents') }}" class="{{ request()->routeIs('driver.documents*') ? 'active' : '' }}"><span class="ico">📄</span>Docs</a>
        @elseif($u->isCorporateClient())
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}"><span class="ico">🏠</span>Home</a>
            <a href="{{ route('bookings.index') }}" class="{{ request()->routeIs('bookings.index','bookings.show') ? 'active' : '' }}"><span class="ico">📋</span>Bookings</a>
            <a href="{{ route('bookings.create') }}" class="{{ request()->routeIs('bookings.create') ? 'active' : '' }}"><span class="ico">➕</span>New</a>
        @endif
        <label for="nav-toggle" class="tabbar-more"><span class="ico">☰</span>More</label>
    </nav>
    @endunless

    <label for="nav-toggle" class="nav-scrim"></label>
</div>

@include('partials.cookie-consent')
<script>
    // Light/dark toggle — remembered per device.
    (function () {
        var btn = document.getElementById('theme-toggle');
        if (!btn) return;
        function paint() { btn.textContent = document.documentElement.dataset.theme === 'dark' ? '☀️' : '🌙'; }
        btn.addEventListener('click', function () {
            var dark = document.documentElement.dataset.theme === 'dark';
            if (dark) { delete document.documentElement.dataset.theme; } else { document.documentElement.dataset.theme = 'dark'; }
            try { localStorage.setItem('cet-theme', dark ? 'light' : 'dark'); } catch (e) {}
            paint();
        });
        paint();
    })();
</script>
</body>
</html>
