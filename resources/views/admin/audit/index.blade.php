@extends('layouts.app')
@section('title', 'ETO audit')

@section('content')
    <h1 class="page-title">ETO audit</h1>
    <p class="page-sub">Reconfirm every booking against the calendar. Upload the ETO export and Command Centre checks each reference one-by-one — on the system, on the calendar, in the right format, right time, fare stored. Anything off gets a ⚠ marker on its booking.</p>

    @if($errors->any())
        <div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08);margin-bottom:16px">{{ $errors->first() }}</div>
    @endif

    <div class="card">
        <h2 style="margin-top:0">🔎 Reconcile ETO bookings</h2>
        <p class="muted" style="margin-top:0">Export your bookings from EasyTaxiOffice, then drop the .csv here. Nothing is changed on the calendar — this only reads and reports.</p>
        <form method="POST" action="{{ route('audit.run') }}" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:10px">
            @csrf
            <input type="file" name="file" required>
            <button type="submit" class="btn btn-primary" style="align-self:flex-start">Run audit</button>
        </form>
        <p class="muted" style="font-size:12px;margin-bottom:0">🔒 The file is read on the spot and never stored — it holds customer details.</p>
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
                <div class="table-scroll">
                    <table>
                        <thead><tr><th>Ref</th><th>Passenger</th><th>Pickup</th><th>Result</th></tr></thead>
                        <tbody>
                            @foreach($results as $r)
                                <tr style="{{ $r['status'] === 'ok' ? 'opacity:.6' : '' }}">
                                    <td style="white-space:nowrap">
                                        @if($r['url'])<a href="{{ $r['url'] }}">{{ $r['reference'] }}</a>@else {{ $r['reference'] }} @endif
                                    </td>
                                    <td>{{ $r['name'] }}</td>
                                    <td class="muted" style="white-space:nowrap">{{ $r['pickup']?->format('D d M · H:i') ?? '—' }}</td>
                                    <td>
                                        @if($r['status'] === 'ok')
                                            <span style="color:#1f7a44">✓ OK</span>
                                        @elseif($r['status'] === 'missing')
                                            <span style="color:#b32020">✗ Not imported</span>
                                            <div class="muted" style="font-size:12px">Import it via Imports → ETO bookings.</div>
                                        @else
                                            <span style="color:#b8860b;font-weight:600">⚠ Check</span>
                                            <ul style="margin:4px 0 0;padding-left:18px;font-size:13px">
                                                @foreach($r['issues'] as $issue)<li>{{ $issue }}</li>@endforeach
                                            </ul>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endisset
@endsection
