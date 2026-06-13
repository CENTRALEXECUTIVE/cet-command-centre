@extends('layouts.app')
@section('title', 'Driver Dashboard')

@section('content')
    <h1 class="page-title">Your Jobs Today</h1>
    <p class="page-sub">{{ auth()->user()->name }} &middot; {{ now()->format('l d F Y') }}</p>

    <div class="card">
        @if($todayJobs->isEmpty())
            <p class="muted mb-0">You have no jobs scheduled for today.</p>
        @else
            <table>
                <thead>
                    <tr><th>Time</th><th>Ref</th><th>Pickup</th><th>Destination</th><th>Pax</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @foreach($todayJobs as $b)
                        <tr>
                            <td><strong>{{ $b->pickup_at->format('H:i') }}</strong></td>
                            <td><a href="{{ route('bookings.show', $b) }}" class="mono">{{ $b->reference }}</a></td>
                            <td>{{ \Illuminate\Support\Str::limit($b->pickup_address, 40) }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($b->destination_address, 40) }}</td>
                            <td>{{ $b->passengers }}</td>
                            <td><span class="badge badge-{{ $b->status->value }}">{{ $b->status->label() }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
