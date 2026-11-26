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
        <div class="card" style="overflow-x:auto;padding:0">
            <table class="table" style="width:100%;min-width:720px">
                <thead><tr><th>When</th><th>Result</th><th>Customer</th><th>Journey</th><th>Detail</th></tr></thead>
                <tbody>
                @foreach($attempts as $a)
                    @php
                        $label = ['booked' => '✅ Booked', 'quote_request' => '💬 Quote request', 'refused' => '⛔ Refused', 'error' => '❌ Error', 'price_on_request' => '⚠ Showed "On request"', 'submitted' => '❔ Submitted'][$a->outcome] ?? $a->outcome;
                        $d = (array) ($a->details ?? []);
                        $detail = isset($d['errors']) ? collect($d['errors'])->flatten()->implode(' ')
                            : ($d['message'] ?? (isset($d['vehicles']) ? implode(', ', $d['vehicles']) : (isset($d['price']) ? '£'.number_format((float) $d['price'], 2) : '')));
                    @endphp
                    <tr>
                        <td style="white-space:nowrap">{{ $a->created_at?->timezone(config('app.timezone'))->format('D d M H:i') }}<br><small>{{ $a->form }}</small></td>
                        <td style="white-space:nowrap">{{ $label }}@if($a->booking_id)<br><a href="{{ route('bookings.show', $a->booking_id) }}">{{ $a->reference }}</a>@endif</td>
                        <td>{{ $a->customer_name ?? '—' }}<br><small>{{ $a->contact }}</small></td>
                        <td><small>{{ $a->pickup }} → {{ $a->destination }}@if($a->pickup_at)<br>{{ $a->pickup_at }}@endif</small></td>
                        <td><small>{{ \Illuminate\Support\Str::limit($detail, 220) }}</small></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top:12px">{{ $attempts->links() }}</div>
    @endif
@endsection
