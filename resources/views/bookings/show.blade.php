@extends('layouts.app')
@section('title', 'Booking ' . $booking->reference)

@section('content')
    <h1 class="page-title">
        Booking <span class="mono">{{ $booking->reference }}</span>
        <span class="badge badge-{{ $booking->status->value }}">{{ $booking->status->label() }}</span>
    </h1>
    <p class="page-sub">Created {{ $booking->created_at->format('D d M Y, H:i') }}
        @if($booking->createdBy) by {{ $booking->createdBy->name }} @endif</p>

    <div class="grid grid-2">
        <div class="card">
            <h2>Journey</h2>
            <table>
                <tr><th>Pickup</th><td>{{ $booking->pickup_at->format('D d M Y, H:i') }}</td></tr>
                <tr><th>From</th><td>{{ $booking->pickup_address }}</td></tr>
                @foreach($booking->stops as $stop)
                    <tr><th>Via {{ $stop->sequence }}</th><td>{{ $stop->address }}</td></tr>
                @endforeach
                <tr><th>To</th><td>{{ $booking->destination_address }}</td></tr>
                @if($booking->airport)<tr><th>Airport</th><td>{{ $booking->airport->code }} — {{ $booking->airport->name }}</td></tr>@endif
                @if($booking->flight_number)<tr><th>Flight</th><td>{{ $booking->flight_number }}</td></tr>@endif
                <tr><th>Passengers</th><td>{{ $booking->passengers }} &middot; {{ $booking->luggage }} bags</td></tr>
                <tr><th>Type</th><td>{{ ucfirst(str_replace('_',' ',$booking->journey_type)) }}{{ $booking->is_return_leg ? ' (return leg)' : '' }}</td></tr>
                @if($booking->special_requests)<tr><th>Notes</th><td>{{ $booking->special_requests }}</td></tr>@endif
            </table>
        </div>

        <div class="card">
            <h2>Service &amp; Payment</h2>
            <table>
                <tr><th>Customer</th><td>{{ $booking->customer?->name }}</td></tr>
                <tr><th>Vehicle</th><td>{{ $booking->vehicleType?->name }}</td></tr>
                <tr><th>Driver</th><td>{{ $booking->driver?->name ?? 'Awaiting allocation' }}</td></tr>
                @if($booking->corporateAccount)
                    <tr><th>Account</th><td>{{ $booking->corporateAccount->name }}</td></tr>
                    @if($booking->cost_code)<tr><th>Cost code</th><td>{{ $booking->cost_code }}</td></tr>@endif
                @endif
                <tr><th>Payment</th><td>{{ $booking->payment_method->emoji() }} {{ $booking->payment_method->label() }}</td></tr>
                @if($booking->quoted_price)<tr><th>Quoted</th><td>£{{ number_format($booking->quoted_price, 2) }}</td></tr>@endif
            </table>
        </div>
    </div>

    @if($booking->calendarEvent)
        <div class="card">
            <h2>Calendar Event</h2>
            <table>
                <tr><th>Title</th><td class="mono">{{ $booking->calendarEvent->title }}</td></tr>
                <tr><th>Location</th><td>{{ $booking->calendarEvent->location }}</td></tr>
                <tr><th>Start → End</th><td>{{ $booking->calendarEvent->start_at->format('H:i') }} → {{ $booking->calendarEvent->end_at->format('H:i') }}</td></tr>
                <tr><th>Sync</th><td>{{ ucfirst($booking->calendarEvent->sync_status) }}</td></tr>
            </table>
        </div>
    @endif

    <a href="{{ route('bookings.index') }}" class="btn btn-ghost">← Back to bookings</a>
@endsection
