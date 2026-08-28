@extends('layouts.app')
@section('title', $account->name)

@section('content')
    <a href="{{ route('review.index') }}" class="da-back" style="display:inline-block;margin-bottom:10px">← Back to Review</a>

    <div>
        <h1 class="page-title" style="margin-bottom:2px">{{ $account->name }}</h1>
        <p class="page-sub">Every customer who's travelled with {{ $account->name }}, ranked by bookings. Repeat clients are flagged. Cancellations excluded.</p>
    </div>

    @php $money = 'font-variant-numeric:tabular-nums;font-weight:700;white-space:nowrap'; @endphp

    <div class="grid grid-4" style="margin:14px 0;gap:10px">
        <div class="card" style="padding:14px">
            <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">Bookings</div>
            <div style="font-size:26px;font-weight:800;{{ $money }}">{{ number_format($totals['bookings']) }}</div>
        </div>
        <div class="card" style="padding:14px">
            <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">Revenue</div>
            <div style="font-size:26px;font-weight:800;{{ $money }}">£{{ number_format($totals['revenue'], 0) }}</div>
        </div>
        <div class="card" style="padding:14px">
            <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">Customers</div>
            <div style="font-size:26px;font-weight:800;{{ $money }}">{{ number_format($totals['customers']) }}</div>
        </div>
        <div class="card" style="padding:14px">
            <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">Repeat customers</div>
            <div style="font-size:26px;font-weight:800;{{ $money }}">{{ number_format($totals['repeat_customers']) }}</div>
        </div>
    </div>

    <div class="card">
        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>#</th><th>Customer</th><th>Bookings</th><th>Revenue</th><th>Last trip</th></tr></thead>
                <tbody>
                @forelse($customers as $i => $c)
                    <tr @if($c['customer']) class="rowlink" data-href="{{ route('customers.show', $c['customer']) }}" style="cursor:pointer" @endif>
                        <td class="muted">{{ $i + 1 }}</td>
                        <td>
                            {{ $c['name'] }}
                            @if($c['repeat'])<span class="bh-chip" style="margin-left:6px">🔁 repeat</span>@endif
                        </td>
                        <td style="{{ $money }}">{{ number_format($c['bookings']) }}</td>
                        <td style="{{ $money }}">£{{ number_format($c['revenue'], 0) }}</td>
                        <td>{{ $c['last_booking'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No bookings for this business yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <style>
        tr.rowlink:hover td{background:rgba(251,186,42,.08)}
        .bh-chip{display:inline-block;padding:2px 8px;border-radius:999px;background:rgba(128,128,128,.14);font-size:12px;font-weight:600}
    </style>
    <script>
        document.addEventListener('click', function (e) {
            var row = e.target.closest('tr.rowlink');
            if (!row || e.target.closest('a,button,form,input,[data-norowlink]')) return;
            var href = row.getAttribute('data-href');
            if (href) window.location = href;
        });
    </script>
@endsection
