@extends('layouts.app')
@section('title', 'Growth dashboard')

@section('content')
@php
    $keys = $data['keys'];
    $thisWk = end($keys);
    $lastWk = count($keys) > 1 ? $keys[count($keys) - 2] : null;
    $t = $data['totals'];
    $o = $data['outreach'];
    $money = fn ($v) => '£'.number_format((float) $v, 0);
    $delta = function ($now, $before) {
        if ($before === null) return '';
        $d = $now - $before;
        if ($d == 0) return '<span class="muted">same as last week</span>';
        $col = $d > 0 ? '#1f9d55' : '#c0392b';
        return '<span style="color:'.$col.'">'.($d > 0 ? '▲ ' : '▼ ').abs($d).' vs last week</span>';
    };
    $wkLabel = fn ($k) => \Illuminate\Support\Carbon::parse($k)->format('j M');
    $log = $data['social'];
    $thisLog = $log[$thisWk] ?? [];
@endphp

    <div class="list-head" style="align-items:center">
        <div class="form-hero" style="flex:1;margin-bottom:0">
            <div class="form-hero-glow"></div>
            <div class="fh-eyebrow">Marketing &amp; reports</div>
            <div class="fh-title">Growth dashboard</div>
            <div class="fh-sub">Where bookings come from, how outreach is going, and social media and reviews, week by week (Monday to Sunday, by the date a booking came in).</div>
        </div>
    </div>

    @if(session('status'))
        <div class="card" style="border-left:4px solid #1f9d55;margin-bottom:12px">{{ session('status') }}</div>
    @endif

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px;align-items:center">
        @foreach([4, 8, 13] as $w)
            <a href="{{ route('marketing.growth', ['weeks' => $w]) }}" class="btn {{ $weeksShown === $w ? 'btn-primary' : 'btn-ghost' }}">{{ $w }} weeks</a>
        @endforeach
    </div>

    @include('admin.growth.partials.export-form')

    {{-- This week at a glance --}}
    <div class="grid grid-3" style="gap:12px;margin-bottom:14px">
        <div class="card" style="margin:0">
            <div class="muted" style="font-size:13px">Bookings this week</div>
            <div style="font-size:26px;font-weight:800">{{ $t['count'][$thisWk] }}</div>
            <div style="font-size:12px">{!! $delta($t['count'][$thisWk], $lastWk ? $t['count'][$lastWk] : null) !!}</div>
        </div>
        <div class="card" style="margin:0">
            <div class="muted" style="font-size:13px">Booked value this week (ex-VAT)</div>
            <div style="font-size:26px;font-weight:800">{{ $money($t['revenue'][$thisWk]) }}</div>
            <div class="muted" style="font-size:12px">Last week {{ $lastWk ? $money($t['revenue'][$lastWk]) : '–' }}</div>
        </div>
        <div class="card" style="margin:0">
            <div class="muted" style="font-size:13px">New customers this week</div>
            <div style="font-size:26px;font-weight:800">{{ $t['new'][$thisWk] }}</div>
            <div style="font-size:12px">{!! $delta($t['new'][$thisWk], $lastWk ? $t['new'][$lastWk] : null) !!}</div>
        </div>
        <div class="card" style="margin:0">
            <div class="muted" style="font-size:13px">Outreach emails this week</div>
            <div style="font-size:26px;font-weight:800">{{ $o['contacted_by_week'][$thisWk] }}</div>
            <div class="muted" style="font-size:12px">{{ $o['with_email_not_yet_emailed'] }} ready to send (have an email)</div>
        </div>
        <div class="card" style="margin:0">
            <div class="muted" style="font-size:13px">Outreach replies</div>
            <div style="font-size:26px;font-weight:800">{{ $o['replied'] }}</div>
            <div class="muted" style="font-size:12px">{{ $o['reply_rate'] !== null ? $o['reply_rate'].'% of '.$o['contacted'].' contacted' : 'none contacted yet' }} · {{ $o['accounts_opened'] }} account(s) opened</div>
        </div>
        <div class="card" style="margin:0">
            <div class="muted" style="font-size:13px">Review requests sent this week</div>
            <div style="font-size:26px;font-weight:800">{{ $data['reviews'][$thisWk] }}</div>
            <div class="muted" style="font-size:12px">Google reviews logged: {{ $thisLog['google_reviews'] ?? '–' }}</div>
        </div>
    </div>

    {{-- Bookings by channel --}}
    <div class="card">
        <h3 style="margin-top:0">Bookings by channel</h3>
        <p class="muted" style="font-size:13px;margin-top:-4px">Number of bookings (return legs not counted twice) with the booked value ex-VAT underneath. "Social media" = web bookings that arrived through a tagged link in a post (utm_source facebook, instagram, linkedin, tiktok or gbp).</p>
        <div class="table-scroll">
            <table class="table-modern" style="font-variant-numeric:tabular-nums">
                <thead><tr><th>Channel</th>@foreach($keys as $k)<th style="text-align:right">{{ $wkLabel($k) }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach($channels as $c => $label)
                        <tr>
                            <td>{{ $label }}</td>
                            @foreach($keys as $k)
                                <td style="text-align:right">{{ $data['channels'][$c]['count'][$k] }}
                                    @if($data['channels'][$c]['revenue'][$k] > 0)<div class="muted" style="font-size:11px">{{ $money($data['channels'][$c]['revenue'][$k]) }}</div>@endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr style="font-weight:700">
                        <td>Total</td>
                        @foreach($keys as $k)<td style="text-align:right">{{ $t['count'][$k] }}<div style="font-size:11px">{{ $money($t['revenue'][$k]) }}</div></td>@endforeach
                    </tr>
                    <tr>
                        <td>New customers</td>
                        @foreach($keys as $k)<td style="text-align:right">{{ $t['new'][$k] }}</td>@endforeach
                    </tr>
                    <tr>
                        <td>Returning customers</td>
                        @foreach($keys as $k)<td style="text-align:right">{{ $t['repeat'][$k] }}</td>@endforeach
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Outreach --}}
    <div class="card">
        <h3 style="margin-top:0">Outreach (corporate and partner accounts)</h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
            @foreach($o['funnel'] as $key => $row)
                <a href="{{ route('outreach.index', ['status' => $key]) }}" class="chip" style="display:inline-flex;gap:6px;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid var(--border, #ddd);text-decoration:none">
                    <span>{{ $row['label'] }}</span><strong>{{ $row['count'] }}</strong>
                </a>
            @endforeach
        </div>
        <div class="table-scroll">
            <table class="table-modern" style="font-variant-numeric:tabular-nums">
                <thead><tr><th>Week</th>@foreach($keys as $k)<th style="text-align:right">{{ $wkLabel($k) }}</th>@endforeach</tr></thead>
                <tbody>
                    <tr><td>Prospects emailed (latest contact)</td>@foreach($keys as $k)<td style="text-align:right">{{ $o['contacted_by_week'][$k] }}</td>@endforeach</tr>
                    <tr><td>Replies</td>@foreach($keys as $k)<td style="text-align:right">{{ $o['replied_by_week'][$k] }}</td>@endforeach</tr>
                </tbody>
            </table>
        </div>
        <p class="muted" style="font-size:12px;margin-bottom:0">Target: 10 to 15 first emails a day. "Prospects emailed" counts each prospect once, in the week of their most recent email or follow-up.</p>
    </div>

    {{-- Reviews + social log --}}
    <div class="card">
        <h3 style="margin-top:0">Social media and Google reviews</h3>
        <p class="muted" style="font-size:13px;margin-top:-4px">Type the numbers in once a week (Monday is easiest) from each app. Leave a box blank if you don't have it.</p>
        <div class="table-scroll">
            <table class="table-modern" style="font-variant-numeric:tabular-nums">
                <thead><tr><th>Week</th>@foreach($keys as $k)<th style="text-align:right">{{ $wkLabel($k) }}</th>@endforeach</tr></thead>
                <tbody>
                    <tr><td>Review requests sent</td>@foreach($keys as $k)<td style="text-align:right">{{ $data['reviews'][$k] }}</td>@endforeach</tr>
                    @foreach($socialFields as $f => $label)
                        <tr><td>{{ $label }}</td>@foreach($keys as $k)<td style="text-align:right">{{ $log[$k][$f] ?? '–' }}</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('marketing.growth.social') }}" style="margin-top:14px">
            @csrf
            <div class="grid grid-3" style="gap:10px">
                <label>Week
                    <select name="week" class="form-control">
                        @foreach(array_reverse($keys) as $k)
                            <option value="{{ $k }}">w/c {{ \Illuminate\Support\Carbon::parse($k)->format('j M Y') }}</option>
                        @endforeach
                    </select>
                </label>
                @foreach($socialFields as $f => $label)
                    <label>{{ $label }}
                        <input type="number" name="{{ $f }}" class="form-control" min="0" @if($f === 'google_rating') step="0.1" max="5" @endif value="{{ old($f, $thisLog[$f] ?? '') }}">
                    </label>
                @endforeach
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:10px">Save this week's numbers</button>
        </form>
    </div>
@endsection
