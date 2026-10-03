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
                <input type="file" name="file" required>
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
                <input type="file" name="file" required>
                <button type="submit" class="btn btn-primary" style="align-self:flex-start">Import ETO bookings</button>
            </form>
            <p class="muted" style="font-size:12px;margin-bottom:0">
                @if($lastEto) Last imported: {{ \Illuminate\Support\Carbon::parse($lastEto)->diffForHumans() }} @else Not imported yet. @endif
            </p>
        </div>

        {{-- Resync with ETO emails on demand --}}
        <div class="card">
            <h2>📧 Resync with ETO emails</h2>
            <p class="muted" style="margin-top:0">Reads the recent ETO booking emails now (instead of waiting for the automatic 2-minute check) and updates any booking whose latest email hasn’t come through yet. Matched by reference — no duplicates, your edits kept, calendar untouched. <strong>No notifications are sent.</strong></p>
            <form method="POST" action="{{ route('imports.resync-email') }}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
                @csrf
                <label class="muted" style="font-size:13px">Look back
                    <select name="days" style="margin-left:4px">
                        <option value="7">7 days</option>
                        <option value="30" selected>30 days</option>
                        <option value="60">60 days</option>
                        <option value="90">90 days</option>
                    </select>
                </label>
                <button type="submit" class="btn btn-primary" @unless($emailConnected) disabled title="Connect the Outlook mailbox first" @endunless>🔄 Resync with email now</button>
            </form>
            <p class="muted" style="font-size:12px;margin-bottom:0">
                @unless($emailConnected)
                    ⚠ Email isn’t connected — add the Microsoft/Outlook mailbox credentials on the server to enable this.
                @elseif($lastEmailResync)
                    Last resync: {{ \Illuminate\Support\Carbon::parse($lastEmailResync)->diffForHumans() }}.
                @else
                    Ready — runs automatically every 2 minutes; tap to run it now.
                @endunless
            </p>
        </div>
    </div>

    <p class="muted" style="font-size:12px;margin-top:16px">🔒 Files are read and imported on the spot — never stored on the server. The ETO export holds customer details, so nothing is left behind.</p>
@endsection
