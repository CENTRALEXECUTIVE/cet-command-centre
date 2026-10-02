@extends('layouts.app')
@section('title', 'System health')

@section('content')
<style>
    .health-head{ display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:6px }
    .health-pill{ font-size:13px;font-weight:800;padding:7px 14px;border-radius:999px;letter-spacing:.3px }
    .health-pill.ok{ background:rgba(31,122,68,.14);color:#1c935c }
    .health-pill.off{ background:rgba(128,128,128,.14);color:#6a6a70 }
    .health-pill.warn{ background:rgba(233,164,19,.16);color:#b9770d }
    .health-pill.critical{ background:rgba(207,59,49,.14);color:#cf3b31 }
    .hgroup{ margin-top:22px }
    .hgroup h2{ font-size:13px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin:0 0 10px }
    .hgrid{ display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px }
    .hcard{ background:var(--panel,#fff);border:1px solid var(--line);border-radius:14px;padding:14px 15px;
        box-shadow:0 1px 2px rgba(10,12,18,.04),0 12px 30px -22px rgba(10,12,18,.2);border-left:4px solid var(--line) }
    .hcard.ok{ border-left-color:#1c935c } .hcard.off{ border-left-color:#9aa2ad }
    .hcard.warn{ border-left-color:#e9a413 } .hcard.critical{ border-left-color:#cf3b31 }
    .hcard .top{ display:flex;align-items:center;justify-content:space-between;gap:8px }
    .hcard .name{ font-weight:700;font-size:15px }
    .hcard .dot{ font-size:12px;font-weight:800;padding:3px 9px;border-radius:999px }
    .hcard .dot.ok{ background:rgba(31,122,68,.14);color:#1c935c } .hcard .dot.off{ background:rgba(128,128,128,.14);color:#6a6a70 }
    .hcard .dot.warn{ background:rgba(233,164,19,.16);color:#b9770d } .hcard .dot.critical{ background:rgba(207,59,49,.14);color:#cf3b31 }
    .hcard .detail{ font-size:13px;color:var(--muted);margin-top:7px;line-height:1.5 }
    .jobs{ margin-top:8px;font-size:13px;color:var(--muted) }
    .jobs table{ width:100%;border-collapse:collapse;margin-top:6px }
    .jobs th,.jobs td{ text-align:left;padding:6px 8px;border-bottom:1px solid var(--line);font-size:13px }
    .jobs th{ color:var(--faint,#8a93a3);font-weight:600 }
</style>

<div class="health-head">
    <h1 class="page-title" style="margin:0">🩺 System health</h1>
    @php $labels = ['ok'=>'All good','off'=>'Some features off','warn'=>'Needs attention','critical'=>'Action needed']; @endphp
    <span class="health-pill {{ $worst }}">{{ $labels[$worst] ?? 'Status' }}</span>
    <a href="{{ route('health.index') }}" class="btn btn-ghost" style="margin-left:auto;padding:7px 14px;font-size:13px">↻ Refresh</a>
</div>
<p class="page-sub">Everything that keeps the business running, at a glance. Green is good, grey is a feature that isn't switched on yet, amber needs a look, red needs action.</p>

@if($worst === 'critical')
    <div class="alert alert-error" style="border-left:4px solid #cf3b31">
        <strong>Action needed.</strong> One or more core systems are down — see the red cards below.
    </div>
@endif

@foreach($groups as $group => $checks)
    <div class="hgroup">
        <h2>{{ $group }}</h2>
        <div class="hgrid">
            @foreach($checks as $c)
                <div class="hcard {{ $c['status'] }}">
                    <div class="top">
                        <span class="name">{{ $c['label'] }}</span>
                        @php $dot = ['ok'=>'OK','off'=>'Off','warn'=>'Check','critical'=>'Down']; @endphp
                        <span class="dot {{ $c['status'] }}">{{ $dot[$c['status']] ?? '' }}</span>
                    </div>
                    <div class="detail">{{ $c['detail'] }}</div>
                </div>
            @endforeach
        </div>
    </div>
@endforeach

<div class="hgroup">
    <h2>Background jobs — last run</h2>
    <div class="card jobs">
        <p style="margin:0 0 2px">When each key scheduled job last ran. If the scheduler is healthy these stay recent; blanks mean it hasn't run since the last deploy.</p>
        <table>
            <thead><tr><th>Job</th><th>Last ran</th></tr></thead>
            <tbody>
                @foreach($jobs as $name => $when)
                    <tr>
                        <td class="mono">{{ $name }}</td>
                        <td>{{ $when ? $when->diffForHumans() : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="hint" style="margin:10px 0 0">If the scheduler shows <strong>Down</strong>, install the cron on the server:
            <code>* * * * * cd ~/cet-staging &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</code></p>
    </div>
</div>
@endsection
