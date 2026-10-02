@extends('layouts.app')
@section('title', 'Paste a booking')

@section('content')
    @include('partials.smart-form-skin')

    <div class="smart-form">
        <div class="smart-hero">
            <div class="brand"><span class="mark">C</span><span class="name">CENTRAL <span>EXECUTIVE</span> TRANSFERS</span></div>
            <span class="eyebrow">Sales · Paste a booking</span>
            <h1>Paste a booking</h1>
            <p>Paste the message a customer sent and the AI pulls out every detail into the exact CET format for you to review before anything is created.</p>
        </div>

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
                <button type="submit" class="btn btn-primary">Format the booking →</button>
            </form>
            <p class="muted" style="font-size:12px;margin:12px 0 0">You’ll be able to review and edit everything before anything is created.</p>
        </div>
    </div>{{-- /.smart-form --}}
@endsection
