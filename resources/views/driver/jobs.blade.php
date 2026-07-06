@extends('layouts.app')
@section('title', 'My Jobs')

@section('content')
    <h1 class="page-title">My Jobs</h1>
    <p class="page-sub">{{ $label }} &middot; {{ auth()->user()->name }}
        &middot; <a href="{{ route('driver.earnings') }}">Earnings</a></p>

    <div class="filters">
        <a href="{{ route('driver.jobs', ['filter' => 'today']) }}" class="{{ $filter === 'today' ? 'active' : '' }}">Today</a>
        <a href="{{ route('driver.jobs', ['filter' => 'tomorrow']) }}" class="{{ $filter === 'tomorrow' ? 'active' : '' }}">Tomorrow</a>
        <a href="{{ route('driver.jobs', ['filter' => 'week']) }}" class="{{ $filter === 'week' ? 'active' : '' }}">This Week</a>
    </div>

    @forelse($jobs as $b)
        @php $isOffer = $b->status === \App\Enums\BookingStatus::Allocated; @endphp
        <div class="job-tile" @if($isOffer) style="border:2px solid var(--gold)" @endif>
            <a href="{{ route('driver.job', $b) }}" style="color:inherit;text-decoration:none;display:block">
                <div class="top">
                    <span class="big-time">{{ $b->pickup_at->format('H:i') }}</span>
                    <span class="badge badge-{{ $b->status->value }}">{{ $isOffer ? 'NEW OFFER' : $b->status->label() }}</span>
                </div>
                <div class="ref mono" style="font-size:11px;color:var(--muted)">{{ $b->reference }}</div>
                <div class="meta" style="margin-top:8px">
                    <strong>{{ $b->displayName() }}</strong> &middot; {{ $b->vehicleType?->name }}
                    {{ $b->airport?->code ? '· '.$b->airport->code : '' }}<br>
                    {{ \Illuminate\Support\Str::limit($b->pickup_address, 50) }}<br>
                    <span class="muted">→ {{ \Illuminate\Support\Str::limit($b->destination_address, 50) }}</span>
                </div>
            </a>
            @if($isOffer)
                <div style="display:flex;gap:8px;margin-top:10px">
                    <form method="POST" action="{{ route('driver.job.status', $b) }}" style="flex:1">
                        @csrf <input type="hidden" name="status" value="accepted">
                        <button class="tap-accept" style="width:100%;padding:12px;border:none;border-radius:8px;font-weight:700;color:#fff">Accept</button>
                    </form>
                    <form method="POST" action="{{ route('driver.job.decline', $b) }}" style="flex:1"
                          onsubmit="return confirm('Decline this job? It goes back to the office.');">
                        @csrf
                        <button class="tap-cancel" style="width:100%;padding:12px;border-radius:8px;font-weight:700">Decline</button>
                    </form>
                </div>
            @endif
        </div>
    @empty
        <div class="card"><p class="muted mb-0">No jobs for this period.</p></div>
    @endforelse

    <div id="offers-cfg" data-url="{{ route('driver.offers-count') }}" data-count="{{ $jobs->where('status', \App\Enums\BookingStatus::Allocated)->count() }}" hidden></div>
    @verbatim
    <script>
        // Live offers: poll every 30s; if the number of new offers changes, reload
        // so the driver sees a fresh job offer without manually refreshing.
        (function () {
            var cfg = document.getElementById('offers-cfg');
            if (!cfg) return;
            var known = parseInt(cfg.dataset.count, 10) || 0;
            setInterval(function () {
                fetch(cfg.dataset.url, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { if (typeof d.offers === 'number' && d.offers !== known) location.reload(); })
                    .catch(function () {});
            }, 30000);
        })();
    </script>
    @endverbatim
@endsection
