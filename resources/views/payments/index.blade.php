@extends('layouts.app')
@section('title', 'Payments')

@section('content')
    <h1 class="page-title">Payments</h1>
    <p class="page-sub">Money owed to CET and cash still to collect. A run cash job counts as collected on the day.</p>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    <div class="grid grid-2" style="margin-bottom:16px">
        <a class="stat" href="#owed" style="text-decoration:none;color:inherit;display:block">
            <div class="n" style="{{ $owedTotal > 0 ? 'color:#b32020' : '' }}">£{{ number_format($owedTotal, 0) }}</div>
            <div class="l">Owed to us · {{ $owed->count() }} job(s)</div>
        </a>
        <a class="stat" href="#collect" style="text-decoration:none;color:inherit;display:block">
            <div class="n" style="{{ $collectTotal > 0 ? 'color:#b8860b' : '' }}">£{{ number_format($collectTotal, 0) }}</div>
            <div class="l">💰 Cash to collect · {{ $toCollect->count() }} job(s)</div>
        </a>
    </div>

    {{-- Owed to us --}}
    <div class="card" id="owed">
        <h2>Owed to us <span class="muted" style="font-weight:400;font-size:13px">— ran already, card/account, not marked paid</span></h2>
        @if($owed->isEmpty())
            <p class="muted mb-0">Nothing outstanding. 🎉</p>
        @else
            <table>
                <thead><tr><th>Pickup</th><th>Customer</th><th>Ref</th><th>Method</th><th class="right">Amount</th><th></th></tr></thead>
                <tbody>
                    @foreach($owed as $b)
                        <tr>
                            <td>{{ $b->pickup_at->format('d M Y') }}</td>
                            <td>{{ $b->customer?->name ?? '—' }}</td>
                            <td><a href="{{ route('bookings.show', $b) }}" class="mono">{{ $b->reference }}</a></td>
                            <td>{{ ucfirst($b->payment_method->value) }} <span class="muted">({{ $b->payment_status }})</span></td>
                            <td class="right">£{{ number_format($b->final_price ?? $b->quoted_price ?? 0, 2) }}</td>
                            <td class="right">
                                <form method="POST" action="{{ route('payments.paid', $b) }}">@csrf
                                    <button class="btn btn-primary" style="padding:4px 12px;font-size:12px">Mark paid</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Cash to collect --}}
    <div class="card" id="collect">
        <h2>💰 Cash to collect <span class="muted" style="font-weight:400;font-size:13px">— upcoming cash jobs</span></h2>
        @if($toCollect->isEmpty())
            <p class="muted mb-0">No upcoming cash jobs.</p>
        @else
            <table>
                <thead><tr><th>Pickup</th><th>Customer</th><th>Driver</th><th>Ref</th><th class="right">Amount</th><th></th></tr></thead>
                <tbody>
                    @foreach($toCollect as $b)
                        <tr>
                            <td>{{ $b->pickup_at->format('D d M, H:i') }}</td>
                            <td>{{ $b->customer?->name ?? '—' }}</td>
                            <td class="muted">{{ $b->driver?->name ?? '—' }}</td>
                            <td><a href="{{ route('bookings.show', $b) }}" class="mono">{{ $b->reference }}</a></td>
                            <td class="right">£{{ number_format($b->final_price ?? $b->quoted_price ?? 0, 2) }}</td>
                            <td class="right">
                                <form method="POST" action="{{ route('payments.paid', $b) }}">@csrf
                                    <button class="btn btn-ghost" style="padding:4px 12px;font-size:12px">Mark collected</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
