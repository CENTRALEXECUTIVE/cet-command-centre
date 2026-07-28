{{-- Compact commission row (fits any width, no horizontal scroll). $r has
     name, jobs, fares, cost, profit. --}}
<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid rgba(128,128,128,.12)">
    <div style="min-width:0;flex:1">
        <div style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $r['name'] }}</div>
        <div class="muted" style="font-size:12px">{{ $r['jobs'] }} job{{ $r['jobs'] === 1 ? '' : 's' }} · fares £{{ number_format($r['fares'], 0) }} · cost £{{ number_format($r['cost'], 0) }}</div>
    </div>
    <div style="text-align:right;white-space:nowrap">
        <div style="font-weight:800;font-size:16px;color:{{ $r['profit'] >= 0 ? '#1f7a44' : '#b32020' }}">£{{ number_format($r['profit'], 2) }}</div>
        <div class="muted" style="font-size:11px">commission</div>
    </div>
</div>
