@extends('layouts.app')
@section('title', 'Account Statement')

@section('content')
    <h1 class="page-title">Account Statement</h1>
    <p class="page-sub">{{ $accounts->pluck('name')->join(', ') }}</p>

    <div class="grid grid-3" style="margin-bottom:24px">
        <div class="stat"><div class="n">£{{ number_format($totalSpend, 2) }}</div><div class="l">Total Spend (completed)</div></div>
        <div class="stat"><div class="n">{{ $completedCount }}</div><div class="l">Completed Journeys</div></div>
        <div class="stat"><div class="n">{{ $bookings->total() }}</div><div class="l">All Bookings</div></div>
    </div>

    <div class="toolbar">
        <a href="{{ route('bookings.create') }}" class="btn btn-primary">+ New Booking</a>
        <a href="{{ route('invoices.index') }}" class="btn btn-ghost">View Invoices</a>
    </div>

    <div class="card">
        <h2>Booking History</h2>
        @if($bookings->isEmpty())
            <p class="muted mb-0">No bookings yet.</p>
        @else
            <table>
                <thead><tr><th>Ref</th><th>Pickup</th><th>Route</th><th>Cost code</th><th>Vehicle</th><th>Status</th><th class="right">Price</th></tr></thead>
                <tbody>
                    @foreach($bookings as $b)
                        <tr>
                            <td><a href="{{ route('bookings.show', $b) }}" class="mono">{{ $b->reference }}</a></td>
                            <td>{{ $b->pickup_at->format('d M H:i') }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($b->pickup_address, 16) }} → {{ \Illuminate\Support\Str::limit($b->destination_address, 16) }}</td>
                            <td>{{ $b->cost_code ?? '—' }}</td>
                            <td>{{ $b->vehicleType?->name }}</td>
                            <td><span class="badge badge-{{ $b->status->value }}">{{ $b->status->label() }}</span></td>
                            <td class="right">{{ $b->final_price ?? $b->quoted_price ? '£'.number_format($b->final_price ?? $b->quoted_price, 2) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="margin-top:16px">{{ $bookings->links() }}</div>
        @endif
    </div>
@endsection
