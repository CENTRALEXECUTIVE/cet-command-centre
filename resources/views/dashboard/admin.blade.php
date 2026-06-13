@extends('layouts.app')
@section('title', 'Admin Dashboard')

@section('content')
    <h1 class="page-title">Despatch Overview</h1>
    <p class="page-sub">Welcome back, {{ auth()->user()->name }}. Here is today at a glance.</p>

    <div class="grid grid-3" style="margin-bottom:24px">
        <div class="stat"><div class="n">{{ $todayCount }}</div><div class="l">Jobs Today</div></div>
        <div class="stat"><div class="n">{{ $pendingCount }}</div><div class="l">Awaiting Allocation</div></div>
        <div class="stat"><div class="n">{{ $activeCount }}</div><div class="l">Active Now</div></div>
    </div>

    <div class="card">
        <h2>Next 10 Upcoming Jobs</h2>
        @if($upcoming->isEmpty())
            <p class="muted mb-0">No upcoming bookings. <a href="{{ route('bookings.create') }}">Create one</a>.</p>
        @else
            <table>
                <thead>
                    <tr><th>Ref</th><th>Pickup</th><th>Customer</th><th>Vehicle</th><th>Driver</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @foreach($upcoming as $b)
                        <tr>
                            <td><a href="{{ route('bookings.show', $b) }}" class="mono">{{ $b->reference }}</a></td>
                            <td>{{ $b->pickup_at->format('D d M, H:i') }}</td>
                            <td>{{ $b->customer?->name }}</td>
                            <td>{{ $b->vehicleType?->name }}</td>
                            <td>{{ $b->driver?->name ?? '—' }}</td>
                            <td><span class="badge badge-{{ $b->status->value }}">{{ $b->status->label() }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <a href="{{ route('bookings.create') }}" class="btn btn-primary">+ New Booking</a>
@endsection
