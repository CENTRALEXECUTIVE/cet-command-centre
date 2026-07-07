@extends('layouts.app')
@section('title', 'New booking from a message')

@section('content')
    <h1 class="page-title">New booking from a message</h1>
    <p class="page-sub">Paste the booking you were sent. It gets formatted into the exact CET calendar format for you to check. <strong>Nothing is created or added to the calendar until you review it and press Confirm.</strong></p>

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
            <button type="submit" class="btn btn-primary">Generate preview →</button>
        </form>
        <p class="muted" style="font-size:12px;margin-bottom:0">You’ll be able to edit anything before it’s created.</p>
    </div>
@endsection
