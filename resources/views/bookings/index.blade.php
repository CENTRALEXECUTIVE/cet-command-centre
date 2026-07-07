@extends('layouts.app')
@section('title', 'Bookings')

@section('content')
    <h1 class="page-title">Bookings</h1>
    @if(!empty($q))
        <p class="page-sub">Search results for “<strong>{{ $q }}</strong>” · {{ $bookings->total() }} found — <a href="{{ route('bookings.index') }}">clear</a></p>
    @else
        <p class="page-sub">All journeys in the system.</p>
    @endif

    @if(auth()->user()->isAdmin() || auth()->user()->isCorporateClient())
        <a href="{{ route('bookings.create') }}" class="btn btn-primary" style="margin-bottom:20px">+ New Booking</a>
    @endif

    <div class="card">
        @if($bookings->isEmpty())
            <p class="muted mb-0">No bookings found.</p>
        @else
            <table>
                <thead>
                    <tr><th>Ref</th><th>Pickup</th><th>Customer</th><th>Route</th><th>Vehicle</th><th>Luggage</th><th>Driver</th><th>Pay</th><th>Status</th>@if(auth()->user()->isAdmin())<th></th>@endif</tr>
                </thead>
                <tbody>
                    @foreach($bookings as $b)
                        <tr>
                            <td>
                                <a href="{{ route('bookings.show', $b) }}" class="mono">{{ $b->reference }}</a>
                                @if(!empty($b->meta['audit_issues']))
                                    <span title="Flagged by ETO audit — {{ implode('; ', $b->meta['audit_issues']) }}">⚠</span>
                                @endif
                                @if($b->external_reference)
                                    <br><span class="muted" style="font-size:11px">ETO {{ $b->external_reference }}</span>
                                @endif
                            </td>
                            <td>{{ $b->pickup_at->format('d M H:i') }}</td>
                            <td>{{ $b->customer?->name }}</td>
                            <td>{{ Str::limit($b->pickup_address, 18) }} → {{ Str::limit($b->destination_address, 18) }}</td>
                            <td>{{ $b->vehicleType?->name }}</td>
                            <td title="{{ $b->luggageBreakdown() }}">{{ $b->luggageShort() }}</td>
                            <td>{{ $b->driver?->name ?? '—' }}</td>
                            <td>{{ $b->payment_method->emoji() ?? $b->payment_method->label() }}</td>
                            <td><span class="badge badge-{{ $b->status->value }}">{{ $b->status->label() }}</span></td>
                            @if(auth()->user()->isAdmin())
                                <td>@unless($b->status->isTerminal())<a href="{{ route('bookings.edit', $b) }}" title="Edit">✏️</a>@endunless</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="margin-top:16px">{{ $bookings->links() }}</div>
        @endif
    </div>
@endsection
