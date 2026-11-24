@extends('layouts.app')
@section('title', 'AI phone call')

@section('content')
    <p><a href="{{ route('phone-calls.index') }}">&larr; AI phone calls</a></p>
    <h1 class="page-title">Call from {{ $call->from_number ?: 'a withheld number' }}</h1>
    <p class="page-sub">{{ $call->created_at->timezone(config('app.timezone'))->format('l j F Y, H:i') }}@if($call->minutes()) &middot; {{ $call->minutes() }} min @endif</p>

    @if($fields)
        <div class="card" style="margin-bottom:16px">
            <h2>Booking request</h2>
            <p class="hint">Check it, then open it in Paste a booking to see the calendar preview and confirm. Nothing is booked until you do.</p>
            <dl style="display:grid;grid-template-columns:max-content 1fr;gap:6px 18px;margin:12px 0">
                @foreach(['lead_name' => 'Passenger', 'contact_no' => 'Contact', 'email' => 'Email', 'pickup_at' => 'Pickup time', 'pickup_address' => 'Pickup', 'destination_address' => 'Drop-off', 'flight_number' => 'Flight', 'passengers' => 'Passengers', 'suitcases' => 'Large cases', 'hand_luggage' => 'Hand luggage', 'vehicle' => 'Vehicle', 'payment' => 'Payment', 'price' => 'Quoted', 'booked_by' => 'Booked by', 'notes' => 'Notes'] as $k => $label)
                    @if(filled($fields[$k] ?? null))
                        <dt class="muted">{{ $label }}</dt><dd style="margin:0">{{ $k === 'price' ? '£'.number_format((float) $fields[$k], 2) : $fields[$k] }}</dd>
                    @endif
                @endforeach
            </dl>
            <form method="POST" action="{{ route('intake.preview') }}" style="display:inline">
                @csrf
                @foreach($fields as $k => $v)<input type="hidden" name="fields[{{ $k }}]" value="{{ $v }}">@endforeach
                <button class="btn btn-primary">Review and confirm booking</button>
            </form>
        </div>
    @endif

    @if($call->message)
        <div class="card" style="margin-bottom:16px"><h2>Message</h2><p class="mb-0">{{ $call->message }}</p></div>
    @endif

    <div class="card" style="margin-bottom:16px">
        <h2>Transcript</h2>
        @foreach($call->transcript ?? [] as $line)
            @continue($line['text'] === '[silence]')
            <p style="margin:0 0 10px">
                <strong>{{ $line['role'] === 'caller' ? 'Caller' : ($line['role'] === 'agent' ? config('cet.ai_voice.agent_name', 'Agent') : 'Note') }}:</strong>
                {{ $line['text'] }}
            </p>
        @endforeach
    </div>

    @if($call->quotes)
        <div class="card" style="margin-bottom:16px">
            <h2>Quotes given</h2>
            @foreach($call->quotes as $q)
                <p style="margin:0 0 6px">{{ $q['pickup'] ?? '' }} &rarr; {{ $q['destination'] ?? '' }}, {{ $q['pickup_date_time'] ?? '' }}, {{ $q['vehicle'] ?? '' }}:
                    <strong>{{ isset($q['total_gbp']) ? '£'.number_format($q['total_gbp'], 2) : 'on request' }}</strong>@if(! empty($q['includes_vat'])) inc VAT @endif</p>
            @endforeach
        </div>
    @endif

    @unless($call->reviewed_at)
        <form method="POST" action="{{ route('phone-calls.reviewed', $call) }}">@csrf<button class="btn">Mark as dealt with</button></form>
    @endunless
@endsection
