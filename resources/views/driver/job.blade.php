@extends('layouts.app')
@section('title', 'Job ' . $booking->reference)

@section('content')
    <a href="{{ route('driver.jobs') }}" class="muted" style="font-size:13px">← All jobs</a>
    <h1 class="page-title" style="margin-top:8px">
        {{ $booking->pickup_at->format('H:i') }} · {{ $booking->customer?->name }}
        <span class="badge badge-{{ $booking->status->value }}">{{ $booking->status->label() }}</span>
    </h1>
    <p class="page-sub">{{ $booking->reference }} · {{ $booking->vehicleType?->name }}</p>

    @if($errors->any())
        <div class="alert alert-error">{{ $errors->first() }}</div>
    @endif

    <div class="card">
        <table>
            <tr><th>Pickup</th><td>{{ $booking->pickup_at->format('D d M, H:i') }}</td></tr>
            <tr><th>From</th><td>{{ $booking->pickup_address }}</td></tr>
            @foreach($booking->stops as $stop)
                <tr><th>Via {{ $stop->sequence }}</th><td>{{ $stop->address }}</td></tr>
            @endforeach
            <tr><th>To</th><td>{{ $booking->destination_address }}</td></tr>
            @if($booking->flight_number)<tr><th>Flight</th><td>{{ $booking->flight_number }}</td></tr>@endif
            <tr><th>Passengers</th><td>{{ $booking->passengers }} · {{ $booking->luggage }} bags</td></tr>
            @if($booking->customer?->phone)
                <tr><th>Contact</th><td><a href="tel:{{ $booking->customer->phone }}">{{ $booking->customer->phone }}</a></td></tr>
            @endif
            @if($booking->special_requests)<tr><th>Notes</th><td>{{ $booking->special_requests }}</td></tr>@endif
        </table>
    </div>

    @php $next = $booking->status->nextStatuses(); @endphp
    @if(!empty($next))
        <div class="tap-actions">
            @foreach($next as $status)
                @php
                    $class = match($status->value) {
                        'accepted' => 'tap-accept', 'en_route' => 'tap-go',
                        'collected' => 'tap-collect', 'complete' => 'tap-complete',
                        default => 'tap-cancel',
                    };
                @endphp
                <form method="POST" action="{{ route('driver.job.status', $booking) }}" class="status-form">
                    @csrf
                    <input type="hidden" name="status" value="{{ $status->value }}">
                    <input type="hidden" name="lat" class="lat-input">
                    <input type="hidden" name="lng" class="lng-input">
                    <button type="submit" class="{{ $class }}" style="width:100%">{{ $status->label() }}</button>
                </form>
            @endforeach
        </div>
    @else
        <div class="card"><p class="muted mb-0">This job is {{ $booking->status->label() }} — no further action.</p></div>
    @endif

    @verbatim
    <script>
        // Capture GPS position at the moment of a one-tap status change so the
        // audit trail records where the driver was. Submits with or without a fix.
        document.querySelectorAll('.status-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (form.dataset.located || !navigator.geolocation) return;
                e.preventDefault();
                navigator.geolocation.getCurrentPosition(function (pos) {
                    form.querySelector('.lat-input').value = pos.coords.latitude;
                    form.querySelector('.lng-input').value = pos.coords.longitude;
                    form.dataset.located = '1';
                    form.submit();
                }, function () {
                    form.dataset.located = '1';
                    form.submit();
                }, { enableHighAccuracy: true, timeout: 5000 });
            });
        });
    </script>
    @endverbatim
@endsection
