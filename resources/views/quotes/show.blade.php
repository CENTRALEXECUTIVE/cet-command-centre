@extends('layouts.app')
@section('title', 'Quote ' . $quote->reference)

@section('content')
    <div class="quote-hero">
        <div class="quote-hero-glow"></div>
        <div style="position:relative">
            <div class="q-ref mono">Quote {{ $quote->reference }} · {{ $quote->ai_generated ? 'AI priced' : 'Rule-based' }} · valid until {{ $quote->expires_at?->format('D d M, H:i') }}</div>
            <div class="q-price">£{{ number_format($quote->price, 2) }}</div>
            <div class="q-sub">{{ $quote->vehicleType?->name }} · {{ $quote->distance_miles }} mi · {{ $quote->duration_minutes }} min</div>
            <div style="margin-top:18px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
                <a href="{{ route('bookings.create', ['quote' => $quote->id]) }}" class="btn btn-primary">Convert to booking →</a>
                <a href="{{ route('quotes.create') }}" class="dash-btn">New quote</a>
            </div>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <h2>Journey</h2>
            <table>
                <tr><th>From</th><td>{{ $quote->pickup_address }}</td></tr>
                <tr><th>To</th><td>{{ $quote->destination_address }}</td></tr>
                <tr><th>Pickup</th><td>{{ $quote->pickup_at?->format('D d M Y, H:i') }}</td></tr>
                <tr><th>Distance</th><td>{{ $quote->distance_miles }} miles ({{ $quote->breakdown['distance_source'] ?? 'n/a' }})</td></tr>
            </table>
        </div>
        <div class="card">
            <h2>Price Breakdown</h2>
            @php $rb = $quote->breakdown['rule_breakdown'] ?? []; @endphp
            <table>
                <tr><th>Base fare</th><td class="right">£{{ number_format($rb['base_fare'] ?? 0, 2) }}</td></tr>
                <tr><th>Distance</th><td class="right">£{{ number_format($rb['distance']['cost'] ?? 0, 2) }}</td></tr>
                <tr><th>Time</th><td class="right">£{{ number_format($rb['time']['cost'] ?? 0, 2) }}</td></tr>
                @if(($rb['airport_surcharge'] ?? 0) > 0)<tr><th>Airport</th><td class="right">£{{ number_format($rb['airport_surcharge'], 2) }}</td></tr>@endif
                @if(($rb['night_uplift'] ?? 0) > 0)<tr><th>Night uplift</th><td class="right">£{{ number_format($rb['night_uplift'], 2) }}</td></tr>@endif
                @if(($rb['bank_holiday']['uplift'] ?? 0) > 0)<tr><th>{{ $rb['bank_holiday']['name'] }}</th><td class="right">£{{ number_format($rb['bank_holiday']['uplift'], 2) }}</td></tr>@endif
                <tr><th>Rule price</th><td class="right">£{{ number_format($quote->breakdown['rule_price'] ?? $quote->breakdown['base_price'] ?? $quote->price, 2) }}</td></tr>
                @foreach(($quote->breakdown['extras'] ?? []) as $extra)
                    <tr><th>{{ $extra['label'] }}</th><td class="right">£{{ number_format($extra['amount'], 2) }}</td></tr>
                @endforeach
                @if(($quote->breakdown['extras_total'] ?? 0) > 0)
                    <tr><th><strong>Total incl. extras</strong></th><td class="right"><strong>£{{ number_format($quote->price, 2) }}</strong></td></tr>
                @endif
            </table>
            @if($quote->breakdown['stopover_addresses'] ?? null)
                <p class="hint" style="margin-top:8px"><strong>Stopovers:</strong> {{ $quote->breakdown['stopover_addresses'] }}</p>
            @endif
            @if($quote->breakdown['ai_rationale'] ?? null)
                <p class="hint" style="margin-top:12px"><strong>AI:</strong> {{ $quote->breakdown['ai_rationale'] }}</p>
            @endif
        </div>
    </div>
@endsection
