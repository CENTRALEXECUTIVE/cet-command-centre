@extends('layouts.app')
@section('title', 'Imports')

@section('content')
    <h1 class="page-title">Imports</h1>
    <p class="page-sub">Upload your monthly exports straight here — no File Manager, no commands.</p>

    @if(session('status'))
        <div class="card" style="border-left:4px solid #1f7a44;background:rgba(31,122,68,.08);margin-bottom:16px">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08);margin-bottom:16px">{{ $errors->first() }}</div>
    @endif

    <div class="grid grid-2">
        {{-- Google Ads --}}
        <div class="card">
            <h2>📈 Google Ads report</h2>
            <p class="muted" style="margin-top:0">Export from Google Ads (Campaigns or keyword report for the period), then drop the .csv here. Fills the Review's spend, clicks, conversions and ROAS.</p>
            <form method="POST" action="{{ route('imports.ads') }}" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:10px">
                @csrf
                <input type="file" name="file" accept=".csv,.txt" required>
                <button type="submit" class="btn btn-primary" style="align-self:flex-start">Import ads report</button>
            </form>
            <p class="muted" style="font-size:12px;margin-bottom:0">
                @if($lastAds) Last imported: {{ \Illuminate\Support\Carbon::parse($lastAds)->diffForHumans() }} @else Not imported yet. @endif
            </p>
        </div>

        {{-- ETO bookings --}}
        <div class="card">
            <h2>🚕 ETO bookings export</h2>
            <p class="muted" style="margin-top:0">Export your bookings from EasyTaxiOffice, then drop the .csv here. Fills real fares/revenue and updates existing bookings. Keyed by reference — no duplicates, calendar untouched.</p>
            <form method="POST" action="{{ route('imports.eto') }}" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:10px">
                @csrf
                <input type="file" name="file" accept=".csv,.txt" required>
                <button type="submit" class="btn btn-primary" style="align-self:flex-start">Import ETO bookings</button>
            </form>
            <p class="muted" style="font-size:12px;margin-bottom:0">
                @if($lastEto) Last imported: {{ \Illuminate\Support\Carbon::parse($lastEto)->diffForHumans() }} @else Not imported yet. @endif
            </p>
        </div>
    </div>

    <p class="muted" style="font-size:12px;margin-top:16px">🔒 Files are read and imported on the spot — never stored on the server. The ETO export holds customer details, so nothing is left behind.</p>
@endsection
