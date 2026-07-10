@extends('layouts.app')
@section('title', 'Bookings')

@section('content')
    <div class="list-head">
        <div>
            <h1 class="page-title" style="margin:0">Bookings</h1>
            @if(!empty($q))
                <p class="page-sub" style="margin:2px 0 0">Search results for “<strong>{{ $q }}</strong>” · {{ $bookings->total() }} found — <a href="{{ route('bookings.index') }}">clear</a></p>
            @else
                <p class="page-sub" style="margin:2px 0 0">{{ $bookings->total() }} journeys in the system</p>
            @endif
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <form method="GET" action="{{ route('bookings.index') }}" role="search">
                <input type="search" name="q" value="{{ $q ?? '' }}" placeholder="🔍 Search ref, ETO, name, phone…"
                       style="width:240px;max-width:52vw;padding:9px 12px;border-radius:10px">
            </form>
            @if(auth()->user()->isAdmin() || auth()->user()->isCorporateClient())
                <a href="{{ route('bookings.create') }}" class="btn btn-primary" style="padding:9px 16px;white-space:nowrap">+ New</a>
            @endif
        </div>
    </div>

    <div class="card" style="padding:8px">
        @if($bookings->isEmpty())
            <p class="muted mb-0" style="padding:16px">No bookings found.</p>
        @else
            <div style="overflow-x:auto">
            <table class="table-modern">
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
                            <td>{{ $b->displayCustomerName() }}</td>
                            <td>{{ Str::limit($b->displayPickupAddress(), 18) }} → {{ Str::limit($b->displayDropoffAddress(), 18) }}</td>
                            <td>{{ $b->displayVehicleType() }}</td>
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
            </div>
            <div style="margin-top:12px;padding:0 8px 8px">{{ $bookings->links() }}</div>
        @endif
    </div>
@endsection
