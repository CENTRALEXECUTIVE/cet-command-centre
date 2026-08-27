@extends('layouts.app')
@section('title', 'Business review')

@section('content')
    <div>
        <h1 class="page-title" style="margin-bottom:2px">Business review</h1>
        <p class="page-sub">Every corporate account, ranked by bookings. Who books the most, how many of their customers come back, and what they bring in — all time, cancellations excluded.</p>
    </div>

    @php $money = 'font-variant-numeric:tabular-nums;font-weight:700;white-space:nowrap'; @endphp

    {{-- Top-line tiles. --}}
    <div class="grid grid-4" style="margin:14px 0;gap:10px">
        <div class="card" style="padding:14px">
            <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">Businesses</div>
            <div style="font-size:26px;font-weight:800;{{ $money }}">{{ $totals['businesses'] }}</div>
        </div>
        <div class="card" style="padding:14px">
            <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">Total bookings</div>
            <div style="font-size:26px;font-weight:800;{{ $money }}">{{ number_format($totals['bookings']) }}</div>
        </div>
        <div class="card" style="padding:14px">
            <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">Revenue</div>
            <div style="font-size:26px;font-weight:800;{{ $money }}">£{{ number_format($totals['revenue'], 0) }}</div>
        </div>
        <div class="card" style="padding:14px">
            <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">Repeat customers</div>
            <div style="font-size:26px;font-weight:800;{{ $money }}">{{ number_format($totals['repeat_customers']) }}</div>
        </div>
    </div>

    @forelse($businesses as $i => $b)
        @php $href = $b['id'] ? route('reports.business', $b['id']) : null; @endphp
        <div class="card rowlink" @if($href) data-href="{{ $href }}" @endif style="margin-bottom:10px;{{ $href ? 'cursor:pointer' : '' }}">
            <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
                <h2 style="margin:0">
                    <span class="muted" style="font-size:14px;font-weight:600">{{ $i + 1 }}.</span>
                    {{ $b['name'] }}
                </h2>
                <div style="{{ $money }};font-size:20px">{{ number_format($b['bookings']) }} <span class="muted" style="font-size:13px;font-weight:500">booking{{ $b['bookings'] === 1 ? '' : 's' }}</span></div>
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">
                <span class="bh-chip">✅ {{ $b['completed'] }} completed</span>
                @if($b['upcoming'] > 0)<span class="bh-chip">📅 {{ $b['upcoming'] }} upcoming</span>@endif
                <span class="bh-chip">💷 £{{ number_format($b['revenue'], 0) }}</span>
                <span class="bh-chip">👤 {{ $b['customers'] }} customer{{ $b['customers'] === 1 ? '' : 's' }}</span>
                <span class="bh-chip" title="Customers who've booked 2+ times">🔁 {{ $b['repeat_customers'] }} repeat</span>
            </div>

            @if($b['top_customer'])
                <div class="muted" style="font-size:13px;margin-top:8px">
                    Most frequent: <strong>{{ $b['top_customer'] }}</strong> ({{ $b['top_customer_count'] }} trip{{ $b['top_customer_count'] === 1 ? '' : 's' }})@if($b['last_booking']) · last booking {{ $b['last_booking'] }}@endif
                </div>
            @elseif($b['last_booking'])
                <div class="muted" style="font-size:13px;margin-top:8px">Last booking {{ $b['last_booking'] }}</div>
            @endif

            @if($href)
                <div style="margin-top:8px"><a href="{{ $href }}" data-norowlink style="font-size:13px">View customers →</a></div>
            @endif
        </div>
    @empty
        <div class="card"><p class="muted mb-0">No corporate bookings yet. Assign a booking (or a customer) to a corporate account and it appears here.</p></div>
    @endforelse

    <style>
        .rowlink:hover{border-color:var(--gold, #FBBA2A)}
        .bh-chip{display:inline-block;padding:4px 10px;border-radius:999px;background:rgba(128,128,128,.12);font-size:13px;font-weight:600;white-space:nowrap}
    </style>
    <script>
        document.addEventListener('click', function (e) {
            var row = e.target.closest('.rowlink');
            if (!row || e.target.closest('a,button,form,input,[data-norowlink]')) return;
            var href = row.getAttribute('data-href');
            if (href) window.location = href;
        });
    </script>
@endsection
