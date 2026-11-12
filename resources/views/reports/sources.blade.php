@extends('layouts.app')
@section('title', 'Bookings by source')

@section('content')
    <h1 class="page-title">Bookings by source</h1>
    <p class="page-sub">{{ $start->format('j M Y') }} – {{ $end->format('j M Y') }} · week by week · Google Ads paused {{ $adsOff->format('j M Y') }}</p>

    <form method="GET" action="{{ route('reports.sources') }}" class="toolbar">
        <input type="date" name="start" value="{{ $start->toDateString() }}" style="width:auto">
        <input type="date" name="end" value="{{ $end->toDateString() }}" style="width:auto">
        <button class="btn btn-dark" type="submit" style="padding:9px 16px">Apply</button>
    </form>

    <p class="hint" style="margin:0 0 14px">
        Bookings are counted in the week they <strong>came in</strong> (not the travel date). A return counts as one booking;
        revenue is every leg's fare. Cancelled and no-show jobs are left out. <strong>How they came in</strong> is the office's
        record (or Website for online bookings). <strong>How they heard</strong> comes from the new question on the booking form,
        or a Google Ads / social link they arrived from.
    </p>

    @php $s = $summary; @endphp
    <div class="grid grid-3" style="margin-bottom:16px">
        <div class="stat"><div class="n">{{ $s['before_bookings'] ?? '—' }} → {{ $s['after_bookings'] ?? '—' }}</div><div class="l">Bookings a week · before ads paused ({{ $s['before_weeks'] }} wks) → since ({{ $s['after_weeks'] }} full wks)</div></div>
        <div class="stat"><div class="n">£{{ number_format((float) ($s['before_revenue'] ?? 0)) }} → £{{ number_format((float) ($s['after_revenue'] ?? 0)) }}</div><div class="l">Revenue a week · before → since</div></div>
        <div class="stat"><div class="n">{{ array_sum($heardSince) }}</div><div class="l">Bookings since ads paused</div></div>
    </div>

    <div class="card">
        <h2>Week by week</h2>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Week of</th>
                        <th style="text-align:right">Bookings</th>
                        <th style="text-align:right">Revenue</th>
                        @foreach($channelLabels as $label)<th style="text-align:right">{{ $label }}</th>@endforeach
                    </tr>
                </thead>
                <tbody>
                @foreach($weeks as $w)
                    <tr style="{{ $w['ads_off'] ? 'border-top:2px solid #FBBA2A' : '' }}">
                        <td>{{ $w['start']->format('D j M') }} @if($w['ads_off'])<span class="badge" style="background:#FBBA2A;color:#000">Ads paused</span>@endif</td>
                        <td style="text-align:right"><strong>{{ $w['bookings'] }}</strong></td>
                        <td style="text-align:right">£{{ number_format($w['revenue']) }}</td>
                        @foreach(array_keys($channelLabels) as $k)<td style="text-align:right">{{ $w['channels'][$k] ?: '·' }}</td>@endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>How they heard about us</h2>
        @if(! $heard)
            <p class="muted">No bookings in this range yet.</p>
        @else
            <table>
                <thead><tr><th>Source</th><th style="text-align:right">Whole range</th><th style="text-align:right">Since ads paused</th></tr></thead>
                <tbody>
                @foreach($heard as $label => $n)
                    <tr><td>{{ $label }}</td><td style="text-align:right">{{ $n }}</td><td style="text-align:right">{{ $heardSince[$label] ?? 0 }}</td></tr>
                @endforeach
                </tbody>
            </table>
            <p class="hint" style="margin:8px 0 0">"Not asked / unknown" shrinks as customers answer the new question and the office records it on phone bookings.</p>
        @endif
    </div>
@endsection
