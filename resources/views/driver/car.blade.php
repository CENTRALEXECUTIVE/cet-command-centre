<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Your car · Central Executive Transfers</title>
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

        @if($booking === null || $car === null)
            <div class="card" style="text-align:center;padding:36px 20px">
                <div style="font-size:40px">🔒</div>
                <h2 style="margin:10px 0 4px">This link isn’t active</h2>
                <p class="hint" style="margin:0">It may have expired or the job is no longer live. Contact Central Executive Transfers on WhatsApp for a fresh link.</p>
            </div>
        @else
            @php
                $carStatus = \App\Enums\BookingStatus::from($car['status'] ?? \App\Enums\BookingStatus::Allocated->value);
                $allowed = ['accepted', 'en_route', 'arrived', 'collected', 'complete'];
                $next = array_values(array_filter($carStatus->nextStatuses(), fn ($s) => in_array($s->value, $allowed, true)));
            @endphp

            <div class="da-hero">
                <div class="da-hero-time">{{ $booking->pickup_at->format('D d M') }} · <span>{{ $booking->pickup_at->format('H:i') }}</span></div>
                <div class="da-hero-name">{{ $booking->displayName() }}</div>
                <div class="da-hero-route">
                    <span class="da-addr">📍 {{ $booking->displayPickupAddress() }}</span>
                    <span class="da-addr">🏁 {{ $booking->displayDropoffAddress() }}</span>
                </div>
                <div class="da-hero-chips">
                    <span class="badge" style="background:#5b2bc7;color:#fff">🚗 Car {{ $carNumber }} of {{ $carTotal }}</span>
                    <span class="badge badge-{{ $carStatus->value }}">{{ $carStatus->label() }}</span>
                    @if($booking->airport)<span class="da-chip">✈ {{ $booking->airport->code }}</span>@endif
                    @php $carPax = $car['passengers'] ?? null; @endphp
                    <span class="da-chip">👤 {{ $carPax !== null ? $carPax : $booking->passengerCount() }} pax @if($carPax !== null)<span style="opacity:.7">in your car</span>@endif</span>
                    <span class="da-chip">🧳 {{ $booking->luggageBreakdown() }}</span>
                </div>
                @if(!empty($car['reg']) || !empty($car['car']))
                    <div class="da-hero-route" style="margin-top:6px"><span class="da-addr">🚘 {{ trim(($car['car'] ?? '').' '.($car['reg'] ?? '')) }}</span></div>
                @endif
            </div>

            @if($errors->any())
                <div class="alert alert-error">{{ $errors->first() }}</div>
            @endif

            {{-- Notes from the office for the driver — extra info the customer gave.
                 Prominent so it isn't missed. Same note every car on the job sees. --}}
            @if($booking->driverNotes())
                <div class="card" style="border-left:4px solid #FBBA2A;background:rgba(251,186,42,.10);margin-bottom:16px">
                    <div style="font-weight:800;font-size:15px">📝 Notes</div>
                    <p style="margin:6px 0 0;font-size:15px;white-space:pre-wrap">{{ $booking->driverNotes() }}</p>
                </div>
            @endif

            {{-- Navigation (Waze), with a Copy button for any nav app. --}}
            <div style="margin-bottom:16px">
                <div style="display:flex;gap:8px;align-items:stretch;margin-bottom:8px">
                    <a class="btn btn-dark" style="flex:1;text-align:center"
                       href="https://waze.com/ul?q={{ urlencode($booking->pickup_address) }}&navigate=yes" target="_blank" rel="noopener">🧭 Waze to pickup</a>
                    <button type="button" class="btn btn-ghost js-copy-addr" data-addr="{{ $booking->pickup_address }}" style="white-space:nowrap">📋 Copy</button>
                </div>
                @if(in_array($carStatus->value, ['collected'], true))
                    <div style="display:flex;gap:8px;align-items:stretch;margin-bottom:6px">
                        <a class="btn btn-ghost" style="flex:1;text-align:center"
                           href="https://waze.com/ul?q={{ urlencode($booking->destination_address) }}&navigate=yes" target="_blank" rel="noopener">🏁 Waze to drop-off</a>
                        <button type="button" class="btn btn-ghost js-copy-addr" data-addr="{{ $booking->destination_address }}" style="white-space:nowrap">📋 Copy</button>
                    </div>
                @endif
            </div>

            <div class="card">
                <table>
                    <tr><th>Pickup</th><td>{{ $booking->pickup_at->format('D d M, H:i') }}</td></tr>
                    <tr><th>From</th><td>{{ $booking->displayPickupAddress() }}</td></tr>
                    <tr><th>To</th><td>{{ $booking->displayDropoffAddress() }}</td></tr>
                    <tr><th>Vehicle</th><td>{{ $booking->displayVehicleType() }}</td></tr>
                    <tr><th>Passengers</th><td>{{ ($car['passengers'] ?? null) !== null ? $car['passengers'].' in your car (of '.$booking->passengerCount().' total)' : $booking->passengerCount() }}</td></tr>
                    <tr><th>Luggage</th><td>{{ $booking->luggageBreakdown() }}</td></tr>
                    <tr><th>Contact</th><td><span class="muted">Via the office — tap “Message the office” below</span></td></tr>
                    @if($booking->special_requests)<tr><th>Special requests</th><td>{{ $booking->special_requests }}</td></tr>@endif
                    @if($booking->driverNotes())<tr><th>Notes</th><td style="white-space:pre-wrap">{{ $booking->driverNotes() }}</td></tr>@endif
                </table>
            </div>

            {{-- Message the office on WhatsApp Business. --}}
            <a href="https://wa.me/447405172435?text={{ rawurlencode('Re '.$booking->reference.' (Car '.$carNumber.' - '.($car['name'] ?? 'driver').'): ') }}" target="_blank" rel="noopener"
               class="btn" style="display:block;background:#25D366;color:#fff;padding:12px;font-weight:700;border-radius:10px;margin-bottom:12px;text-align:center">
                💬 Message the office
            </a>

            @if(!empty($next))
                <div class="da-actionbar">
                <div class="tap-actions">
                    @foreach($next as $status)
                        @php
                            $class = match($status->value) {
                                'accepted' => 'tap-accept', 'en_route' => 'tap-go',
                                'arrived' => 'tap-arrive', 'collected' => 'tap-collect',
                                'complete' => 'tap-complete', default => 'tap-cancel',
                            };
                            $btnLabel = match($status->value) {
                                'accepted' => 'Accept job', 'en_route' => 'On My Way',
                                'collected' => 'Passenger On Board', 'complete' => 'Completed',
                                default => $status->label(),
                            };
                        @endphp
                        <form method="POST" action="{{ route('driver.car.status', $token) }}">
                            @csrf
                            <input type="hidden" name="status" value="{{ $status->value }}">
                            <button type="submit" class="{{ $class }}" style="width:100%">{{ $btnLabel }}</button>
                        </form>
                    @endforeach
                </div>
                </div>
            @else
                <div class="card"><p class="muted mb-0">This car is {{ $carStatus->label() }} — no further action.</p></div>
            @endif

            <p class="hint" style="text-align:center;margin-top:20px">Car {{ $carNumber }} of {{ $carTotal }} · Private link · Central Executive Transfers</p>
        @endif
    </main>

    @verbatim
    <script>
        document.querySelectorAll('.js-copy-addr').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var addr = btn.getAttribute('data-addr') || '';
                var done = function () { var old = btn.innerHTML; btn.innerHTML = '✓ Copied'; setTimeout(function () { btn.innerHTML = old; }, 1500); };
                if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(addr).then(done).catch(done); }
                else { var ta = document.createElement('textarea'); ta.value = addr; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (e) {} document.body.removeChild(ta); done(); }
            });
        });
    </script>
    @endverbatim
</body>
</html>
