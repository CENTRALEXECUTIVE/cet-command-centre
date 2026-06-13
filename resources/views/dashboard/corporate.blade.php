@extends('layouts.app')
@section('title', 'Corporate Dashboard')

@section('content')
    <h1 class="page-title">Your Bookings</h1>
    <p class="page-sub">{{ auth()->user()->name }} &middot;
        {{ auth()->user()->corporateAccounts->pluck('name')->join(', ') }}</p>

    <a href="{{ route('bookings.create') }}" class="btn btn-primary" style="margin-bottom:20px">+ New Booking</a>

    <div class="card">
        @if($bookings->isEmpty())
            <p class="muted mb-0">No bookings yet. <a href="{{ route('bookings.create') }}">Make your first booking</a>.</p>
        @else
            <table>
                <thead>
                    <tr><th>Ref</th><th>Pickup</th><th>Route</th><th>Vehicle</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @foreach($bookings as $b)
                        <tr>
                            <td><a href="{{ route('bookings.show', $b) }}" class="mono">{{ $b->reference }}</a></td>
                            <td>{{ $b->pickup_at->format('D d M, H:i') }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($b->pickup_address, 25) }} →
                                {{ \Illuminate\Support\Str::limit($b->destination_address, 25) }}</td>
                            <td>{{ $b->vehicleType?->name }}</td>
                            <td><span class="badge badge-{{ $b->status->value }}">{{ $b->status->label() }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
