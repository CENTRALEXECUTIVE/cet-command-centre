@extends('layouts.app')
@section('title', 'Settings')

@section('content')
    <h1 class="page-title">Settings</h1>
    <p class="page-sub">Everything you can control — prices, vehicles, booking pages, integrations and your team — in one place.</p>

    @php
        $groups = [
            'Pricing' => [
                ['Fixed prices', 'Set airport &amp; route prices per zone and vehicle.', 'pricing.index', '💷'],
                ['Pricing zones', 'Pickup areas &amp; the postcodes they cover (deep areas).', 'zones.index', '🗺️'],
                ['Free-roam rates', 'Distance-based fares, VAT &amp; estate uplift.', 'free-roam.index', '🛣️'],
                ['Extra prices', 'Meet &amp; greet, child seats, ribbons and more.', 'extras.index', '➕'],
                ['Time-based pricing', 'Night surcharge &amp; holiday (Christmas/NYE) uplifts.', 'time-surcharges.index', '🌙'],
                ['Discount codes', 'Promo codes customers enter at checkout.', 'vouchers.index', '🏷️'],
            ],
            'Bookings' => [
                ['Standing bookings', 'Regular runs that create a booking automatically.', 'recurring.index', '🔁'],
            ],
            'Fleet' => [
                ['Vehicles', 'Names, capacities, hand luggage, on/off &amp; order.', 'vehicles.index', '🚗'],
                ['Airports', 'Add or edit airports, set the Free Roam pool.', 'airports.index', '✈️'],
                ['Vehicle photos', 'The pictures customers see on the booking page.', 'fleet-photos.index', '📸'],
            ],
            'Booking channels' => [
                ['Booking settings', 'Lead time, VAT, driver pay, policies &amp; ops email.', 'booking-settings.index', '⚙️'],
                ['Web widgets', 'Embed the booking form &amp; price checker on your site.', 'web-widgets.index', '🧩'],
                ['Online booking page', 'Open the live customer booking page.', 'widget.book', '🌐', true],
                ['Reviews sent', 'Who&rsquo;s been asked for a Google review.', 'reviews-sent.index', '⭐'],
                ['Message templates', 'Edit confirmation, reminder &amp; quote wording.', 'message-templates.index', '✍️'],
            ],
            'Integrations' => [
                ['Keys &amp; phone lines', 'Google Maps key and number-masking phone lines.', 'settings.index', '🔑'],
                ['Email', 'Turn on booking-confirmation &amp; invoice emails (SMTP) and send a test.', 'settings.index', '📧', false, '#mail'],
                ['Card payments (Square)', 'Paste your Square keys to take card payments.', 'settings.index', '💳'],
            ],
            'People' => [
                ['Users', 'Drivers, corporate clients and admins.', 'users.index', '👥'],
            ],
            'Data' => [
                ['System health', 'Is everything running — scheduler, calendar, backups, connections.', 'health.index', '🩺'],
                ['Activity log', 'Who did what, when — across the system.', 'activity-log.index', '📜'],
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
                @php [$title, $desc, $route, $icon] = [$c[0], $c[1], $c[2], $c[3]]; $external = $c[4] ?? false; $anchor = $c[5] ?? ''; @endphp
                <a href="{{ route($route) }}{{ $anchor }}" @if($external) target="_blank" rel="noopener" @endif
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
