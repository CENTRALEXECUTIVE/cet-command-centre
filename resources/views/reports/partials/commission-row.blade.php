{{-- Compact commission row (fits any width, no horizontal scroll). $r has
     name, jobs, fares, cost, profit. Optional $href makes the name + job
     count open that driver's bookings so the count can be verified.
     Under the commission we show 10% of fares — the minimum commission
     benchmark — so you can see at a glance if you're beating it. --}}
@php
    $href = $href ?? null;
    $benchmark = round(($r['fares'] ?? 0) * 0.10, 2);
    $beating = $r['profit'] >= $benchmark;
@endphp
<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid rgba(128,128,128,.12)">
    <div style="min-width:0;flex:1">
        <div style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            @if($href)<a href="{{ $href }}" style="color:inherit;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:2px">{{ $r['name'] }}</a>@else{{ $r['name'] }}@endif
        </div>
        <div class="muted" style="font-size:12px">
            @if($href)<a href="{{ $href }}" style="color:inherit">{{ $r['jobs'] }} job{{ $r['jobs'] === 1 ? '' : 's' }}</a>@else{{ $r['jobs'] }} job{{ $r['jobs'] === 1 ? '' : 's' }}@endif
            · fares £{{ number_format($r['fares'], 0) }} · cost £{{ number_format($r['cost'], 0) }}
        </div>
    </div>
    <div style="text-align:right;white-space:nowrap">
        <div style="font-weight:800;font-size:16px;color:{{ $r['profit'] >= 0 ? '#1f7a44' : '#b32020' }}">£{{ number_format($r['profit'], 2) }}</div>
        <div class="muted" style="font-size:11px">
            commission
            @if($r['fares'] > 0)· <span style="color:{{ $beating ? '#1f7a44' : '#b8860b' }}">10% £{{ number_format($benchmark, 0) }} {{ $beating ? '✓' : '↓' }}</span>@endif
        </div>
    </div>
</div>
