@extends('layouts.app')
@section('title', 'Format a booking for the calendar')

@section('content')
    <h1 class="page-title">Format a booking for the calendar</h1>
    <p class="page-sub">Paste the booking you were sent. It gets formatted into the exact CET calendar block for you to <strong>copy onto Google Calendar</strong>. The 5-minute sync then imports it into the Command Centre — the calendar stays the single source, so no duplicates.</p>

    @if($errors->any())
        <div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08);margin-bottom:16px">{{ $errors->first() }}</div>
    @endif

    <div class="card">
        <form method="POST" action="{{ route('intake.preview') }}">
            @csrf
            <label for="raw" style="font-weight:600">Paste the message</label>
            <textarea id="raw" name="raw" rows="10" required autofocus
                placeholder="e.g. Hi, can you book a car for Jo Smith, 07700 900123. Pickup Fri 12th at 14:30 from 21 Ecclesall Rd Sheffield to Manchester Airport T2, flight BA123. 2 passengers, 2 bags. Executive. Paying cash. Booked by Kerry."
                style="width:100%;margin:8px 0 12px">{{ old('raw') }}</textarea>
            <button type="submit" class="btn btn-primary">Format for the calendar →</button>
        </form>
        <p class="muted" style="font-size:12px;margin-bottom:0">You’ll be able to edit anything before you copy it across.</p>
    </div>
@endsection
