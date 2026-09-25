@extends('layouts.app')
@section('title', 'Settings')

@section('content')
    <h1 class="page-title">Settings</h1>
    <p class="page-sub">Everything you can control — prices, vehicles, booking pages, integrations and your team — in one place.</p>

    @php
        $groups = [
            'Pricing' => [
                ['Fixed prices', 'Set airport &amp; route prices per zone and vehicle.', 'pricing.index', '💷'],
                ['Free-roam rates', 'Distance-based fares, VAT &amp; estate uplift.', 'free-roam.index', '🛣️'],
                ['Extra prices', 'Meet &amp; greet, child seats, ribbons and more.', 'extras.index', '➕'],
            ],
            'Fleet' => [
                ['Vehicles', 'Names, capacities, hand luggage, on/off &amp; order.', 'vehicles.index', '🚗'],
                ['Vehicle photos', 'The pictures customers see on the booking page.', 'fleet-photos.index', '📸'],
            ],
            'Booking channels' => [
                ['Web widgets', 'Embed the booking form &amp; price checker on your site.', 'web-widgets.index', '🧩'],
                ['Online booking page', 'Open the live customer booking page.', 'widget.book', '🌐', true],
            ],
            'Integrations' => [
                ['Keys &amp; phone lines', 'Google Maps key and number-masking phone lines.', 'settings.index', '🔑'],
            ],
            'People' => [
                ['Users', 'Drivers, corporate clients and admins.', 'users.index', '👥'],
            ],
            'Data' => [
                ['Imports', 'Import ETO bookings and Google Ads reports.', 'imports.index', '📥'],
                ['ETO audit', 'Reconcile bookings against the calendar.', 'audit.index', '✅'],
                ['GDPR', 'Handle customer data-erasure requests.', 'gdpr.erasure', '🔒'],
            ],
        ];

        if ($isSuperAdmin) {
            $groups['People'][] = ['Notifications', 'Alert preferences and the emergency call line.', 'notifications.index', '🔔'];
        }
    @endphp

    @foreach($groups as $heading => $cards)
        <h2 style="margin:22px 0 10px;font-size:15px;letter-spacing:.04em;text-transform:uppercase;color:var(--muted,#888)">{{ $heading }}</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px">
            @foreach($cards as $c)
                @php [$title, $desc, $route, $icon] = [$c[0], $c[1], $c[2], $c[3]]; $external = $c[4] ?? false; @endphp
                <a href="{{ route($route) }}" @if($external) target="_blank" rel="noopener" @endif
                   class="card" style="text-decoration:none;color:inherit;display:block;transition:transform .08s,box-shadow .08s"
                   onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 18px rgba(0,0,0,.10)'"
                   onmouseout="this.style.transform='';this.style.boxShadow=''">
                    <div style="font-size:24px;margin-bottom:6px">{{ $icon }}</div>
                    <div style="font-weight:700;margin-bottom:3px">{!! $title !!} @if($external)<span style="font-weight:400;color:var(--muted,#888)">↗</span>@endif</div>
                    <div class="hint">{!! $desc !!}</div>
                </a>
            @endforeach
        </div>
    @endforeach
@endsection
