@extends('layouts.app')
@section('title', 'ETO audit')

@section('content')
    <h1 class="page-title">ETO audit</h1>
    <p class="page-sub">Reconfirm every booking against the calendar. Upload the ETO export and Command Centre checks each reference one-by-one — on the system, on the calendar, in the right format, right time, fare stored. Anything off gets a ⚠ marker on its booking.</p>

    @if($errors->any())
        <div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08);margin-bottom:16px">{{ $errors->first() }}</div>
    @endif

    {{-- Look up and reconfirm a single booking without the CSV. --}}
    <div class="card" style="margin-bottom:16px">
        <h2 style="margin-top:0">🔍 Find & reconfirm one booking</h2>
        <p class="muted" style="margin-top:0">Type a reference number or customer name to pull up the booking and re-check it — on the calendar, correct format, location still on the event, addresses present.</p>
        <form method="GET" action="{{ route('audit.index') }}" style="display:flex;gap:8px;flex-wrap:wrap">
            <input type="text" name="q" value="{{ $query ?? '' }}" placeholder="ZWR6MM  or  Jo Smith" style="flex:1;min-width:200px" autofocus>
            <button type="submit" class="btn btn-primary">Search</button>
        </form>

        @isset($searchResults)
            @if(count($searchResults))
                <div class="table-scroll" style="margin-top:14px">
                    <table>
                        <thead><tr><th>Ref</th><th>Passenger</th><th>Pickup</th><th>Result</th></tr></thead>
                        <tbody>
                            @foreach($searchResults as $r)
                                @include('admin.audit._row', ['r' => $r])
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="muted" style="margin:14px 0 0">No booking found for “{{ $query }}”. Try the exact reference, or part of the customer name.</p>
            @endif
        @endisset
    </div>

    <div class="card">
        <h2 style="margin-top:0">🔎 Reconcile ETO bookings</h2>
        <p class="muted" style="margin-top:0">Export your bookings from EasyTaxiOffice, then drop the .csv here. Nothing is changed on the calendar — this only reads and reports.</p>
        <form method="POST" action="{{ route('audit.run') }}" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:10px">
            @csrf
            <input type="file" name="file" required>
            <button type="submit" class="btn btn-primary" style="align-self:flex-start">Run audit</button>
        </form>
        <p class="muted" style="font-size:12px;margin-bottom:0">🔒 The file is read on the spot and never stored — it holds customer details.</p>
        <details style="margin-top:12px">
            <summary style="cursor:pointer;font-weight:600">What each booking is checked for</summary>
            <ul style="margin:8px 0 0;padding-left:18px;font-size:13px;line-height:1.7">
                <li>It exists in Command Centre (matched on ETO reference)</li>
                <li>It's on the calendar with a synced event <span class="muted">(cancelled/no-shows skipped)</span></li>
                <li>The calendar title is in CET format — <span class="mono">*Name WHERE (TAG)*</span></li>
                <li><strong>The pickup location hasn't dropped off the calendar event</strong></li>
                <li>The booking details block is still on the event</li>
                <li>The pickup <strong>date &amp; time</strong> match ETO and the calendar</li>
                <li>Pickup &amp; drop-off addresses are present (not blank/“Unknown”)</li>
                <li>A fare is stored when ETO shows a total</li>
            </ul>
        </details>
    </div>

    @isset($counts)
        <div class="grid grid-4" style="margin-top:16px">
            <div class="card" style="text-align:center"><div style="font-size:28px;font-weight:800">{{ $counts['checked'] }}</div><div class="muted">Checked</div></div>
            <div class="card" style="text-align:center"><div style="font-size:28px;font-weight:800;color:#1f7a44">{{ $counts['ok'] }}</div><div class="muted">All good</div></div>
            <div class="card" style="text-align:center"><div style="font-size:28px;font-weight:800;color:#b8860b">{{ $counts['flagged'] }}</div><div class="muted">Flagged ⚠</div></div>
            <div class="card" style="text-align:center"><div style="font-size:28px;font-weight:800;color:#b32020">{{ $counts['missing'] }}</div><div class="muted">Not imported</div></div>
        </div>

        <div class="card" style="margin-top:16px">
            @if(($counts['flagged'] + $counts['missing']) === 0 && $counts['checked'] > 0)
                <div class="alert alert-success" style="margin:0">✓ All {{ $counts['checked'] }} bookings reconcile — on the calendar, correct format, matching times.</div>
            @elseif($counts['checked'] === 0)
                <p class="muted" style="margin:0">No bookings found in that file. Make sure you exported the bookings list from ETO.</p>
            @endif

            @if(count($results))
                <p class="muted" style="font-size:12px;margin:0 0 8px">Newest bookings first. Problems are grouped at the top.</p>
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Ref</th><th>Passenger</th><th>Pickup</th><th>Result</th></tr></thead>
                        <tbody>
                            @foreach($results as $r)
                                @include('admin.audit._row', ['r' => $r])
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endisset
@endsection
