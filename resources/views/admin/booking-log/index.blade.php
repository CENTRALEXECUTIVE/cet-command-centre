@extends('layouts.app')
@section('title', 'Booking log')

@section('content')
    <h1 class="page-title">Booking log</h1>
    <p class="page-sub">Every website booking attempt, recorded silently: booked, quote request, refused (with the reason), errors, and any journey that showed "On request". Kept 90 days. Customers never see this.</p>

    <form method="get" class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <select name="show" onchange="this.form.submit()">
            <option value="problems" @selected($filter === 'problems')>Problems only</option>
            <option value="all" @selected($filter === 'all')>Everything</option>
        </select>
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, phone, email, ref or address" style="flex:1;min-width:180px">
        <button class="btn">Search</button>
    </form>

    @if($attempts === null)
        <div class="card">The log isn't switched on yet (the database update hasn't run). It starts recording after the next deploy.</div>
    @elseif($attempts->isEmpty())
        <div class="card">Nothing recorded{{ $filter === 'problems' ? ' — no problems' : '' }}.</div>
    @else
        {{-- Cards (phone-friendly): every entry shows when, result, customer, journey, detail and a button to the booking. --}}
        <div style="display:flex;flex-direction:column;gap:10px">
        @foreach($attempts as $a)
            @php
                $label = ['booked' => '✅ Booked', 'quote_request' => '💬 Quote request', 'refused' => '⛔ Refused', 'error' => '❌ Error', 'price_on_request' => '⚠ Showed "On request"', 'submitted' => '❔ Submitted (repeat press — see their booking)'][$a->outcome] ?? $a->outcome;
                $d = (array) ($a->details ?? []);
                $detail = trim(implode(' · ', array_filter([
                    $d['vehicle'] ?? null,
                    isset($d['price']) ? '£'.number_format((float) $d['price'], 2) : null,
                    isset($d['vehicles']) ? 'On request: '.implode(', ', $d['vehicles']) : null,
                    isset($d['errors']) ? collect($d['errors'])->flatten()->implode(' ') : null,
                    $d['message'] ?? null,
                ])));
            @endphp
            <div class="card" style="display:flex;flex-direction:column;gap:6px">
                <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
                    <strong>{{ $label }}</strong>
                    <span class="muted" style="font-size:13px">{{ $a->created_at?->timezone(config('app.timezone'))->format('D d M H:i') }} · {{ $a->form }}</span>
                </div>
                @if($a->customer_name || $a->contact)<div>{{ $a->customer_name ?? '—' }} <span class="muted" style="font-size:13px;word-break:break-all">{{ $a->contact }}</span></div>@endif
                <div style="font-size:14px">{{ $a->pickup }} → {{ $a->destination }}@if($a->pickup_at) <span class="muted">· {{ $a->pickup_at }}</span>@endif</div>
                @if($detail)<div class="muted" style="font-size:13px">{{ \Illuminate\Support\Str::limit($detail, 300) }}</div>@endif
                @if($a->booking_id)<div><a class="btn" href="{{ route('bookings.show', $a->booking_id) }}">Open booking {{ $a->reference }}</a></div>@endif
            </div>
        @endforeach
        </div>
        <div style="margin-top:12px">{{ $attempts->links() }}</div>
    @endif
@endsection
