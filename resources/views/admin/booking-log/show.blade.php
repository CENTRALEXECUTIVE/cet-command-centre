@extends('layouts.app')
@section('title', 'Booking log entry')

@section('content')
    @php
        $label = ['booked' => '✅ Booked', 'quote_request' => '💬 Quote request', 'refused' => '⛔ Refused', 'error' => '❌ Error', 'price_on_request' => '⚠ Showed "On request"', 'submitted' => '❔ Pressed Book — no booking found'][$a->outcome] ?? $a->outcome;
        $d = (array) ($a->details ?? []);
        $form = (array) ($d['form'] ?? []);
        $names = [
            'customer_first_name' => 'First name', 'customer_surname' => 'Surname', 'customer_name' => 'Name',
            'customer_phone' => 'Phone', 'customer_email' => 'Email', 'journey_type' => 'Journey',
            'pickup_address' => 'Pickup', 'pickup' => 'Pickup', 'destination_address' => 'Drop-off', 'destination' => 'Drop-off',
            'pickup_at' => 'Pickup time', 'return_pickup_at' => 'Return pickup time', 'passengers' => 'Passengers',
            'suitcases' => 'Suitcases', 'hand_luggage' => 'Hand luggage', 'flight_number' => 'Flight',
            'other_flight_number' => 'Other flight', 'flight_landing_at' => 'Flight lands', 'meet_greet' => 'Meet & greet',
            'need_child_seat' => 'Child seat', 'lead_passenger_name' => 'Passenger', 'lead_passenger_first_name' => 'Passenger first name',
            'lead_passenger_surname' => 'Passenger surname', 'lead_passenger_phone' => 'Passenger phone',
            'vat_invoice' => 'VAT invoice', 'business_name' => 'Company', 'business_vat_number' => 'VAT number',
            'business_address' => 'Company address', 'payment_method' => 'Payment', 'notes' => 'Notes', 'voucher' => 'Discount code',
        ];
    @endphp
    <p><a href="{{ route('booking-log.index') }}">← Booking log</a></p>
    <h1 class="page-title">{{ $label }}</h1>
    <p class="page-sub">{{ $a->created_at?->timezone(config('app.timezone'))->format('l d M Y, H:i') }} · {{ $a->form }}</p>

    <div class="card" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
        @if($a->booking_id)
            <a class="btn btn-primary" href="{{ route('bookings.show', $a->booking_id) }}">Open booking {{ $a->reference }}</a>
        @else
            <a class="btn btn-primary" href="{{ route('booking-log.book', $a) }}">➕ Make booking from this</a>
            <span class="muted" style="font-size:13px;align-self:center">Opens New booking filled in. Check it, add the price, then save. Nothing is saved or sent until you do.</span>
        @endif
    </div>

    <div class="card">
        <table style="width:100%;border-collapse:collapse">
            <tbody>
            @if($a->customer_name)<tr><th style="text-align:left;padding:6px 8px 6px 0;width:38%">Customer</th><td>{{ $a->customer_name }}</td></tr>@endif
            @if($a->contact)<tr><th style="text-align:left;padding:6px 8px 6px 0">Contact</th><td style="word-break:break-all">{{ $a->contact }}</td></tr>@endif
            @if(! $form)
                <tr><th style="text-align:left;padding:6px 8px 6px 0">Pickup</th><td>{{ $a->pickup }}</td></tr>
                <tr><th style="text-align:left;padding:6px 8px 6px 0">Drop-off</th><td>{{ $a->destination }}</td></tr>
                @if($a->pickup_at)<tr><th style="text-align:left;padding:6px 8px 6px 0">Pickup time</th><td>{{ $a->pickup_at }}</td></tr>@endif
            @endif
            @if(! empty($d['vehicle']))<tr><th style="text-align:left;padding:6px 8px 6px 0">Vehicle</th><td>{{ $d['vehicle'] }}</td></tr>@endif
            @if(isset($d['price']))<tr><th style="text-align:left;padding:6px 8px 6px 0">Price</th><td>£{{ number_format((float) $d['price'], 2) }}</td></tr>@endif
            @if(! empty($d['vehicles']))<tr><th style="text-align:left;padding:6px 8px 6px 0">On request for</th><td>{{ implode(', ', $d['vehicles']) }}</td></tr>@endif
            @if(! empty($d['errors']))<tr><th style="text-align:left;padding:6px 8px 6px 0">Refused because</th><td>{{ collect($d['errors'])->flatten()->implode(' ') }}</td></tr>@endif
            @if(! empty($d['message']))<tr><th style="text-align:left;padding:6px 8px 6px 0">Error</th><td>{{ $d['message'] }}</td></tr>@endif
            @foreach($form as $k => $v)
                @continue(in_array($k, ['customer_first_name', 'customer_surname', 'customer_phone', 'customer_email', 'vehicle_type_id', 'accept_terms', 'accept_privacy', 'ribbons', 'create_account'], true))
                <tr><th style="text-align:left;padding:6px 8px 6px 0">{{ $names[$k] ?? ucfirst(str_replace('_', ' ', $k)) }}</th><td style="word-break:break-word">{{ ($v === '1' && in_array($k, ['meet_greet', 'need_child_seat', 'vat_invoice', 'booking_for_other'], true)) ? 'Yes' : $v }}</td></tr>
            @endforeach
            </tbody>
        </table>
        @if(! $form && in_array($a->outcome, ['submitted', 'quote_request', 'refused', 'error'], true))
            <p class="muted" style="font-size:13px;margin-top:10px">This entry was logged before full details were kept (from 27 Nov). Passenger, flight and luggage weren't stored, so check them with the customer.</p>
        @endif
    </div>
@endsection
