@extends('layouts.app')
@section('title', 'My Jobs')

@section('content')
    <h1 class="page-title">My Jobs</h1>
    <p class="page-sub">{{ $label }} &middot; {{ auth()->user()->name }}</p>

    <div class="filters">
        <a href="{{ route('driver.jobs', ['filter' => 'today']) }}" class="{{ $filter === 'today' ? 'active' : '' }}">Today</a>
        <a href="{{ route('driver.jobs', ['filter' => 'tomorrow']) }}" class="{{ $filter === 'tomorrow' ? 'active' : '' }}">Tomorrow</a>
        <a href="{{ route('driver.jobs', ['filter' => 'week']) }}" class="{{ $filter === 'week' ? 'active' : '' }}">This Week</a>
    </div>

    @forelse($jobs as $b)
        <a href="{{ route('driver.job', $b) }}" class="job-tile">
            <div class="top">
                <span class="big-time">{{ $b->pickup_at->format('H:i') }}</span>
                <span class="badge badge-{{ $b->status->value }}">{{ $b->status->label() }}</span>
            </div>
            <div class="ref mono" style="font-size:11px;color:var(--muted)">{{ $b->reference }}</div>
            <div class="meta" style="margin-top:8px">
                <strong>{{ $b->customer?->name }}</strong> &middot; {{ $b->vehicleType?->name }}
                {{ $b->airport?->code ? '· '.$b->airport->code : '' }}<br>
                {{ \Illuminate\Support\Str::limit($b->pickup_address, 50) }}<br>
                <span class="muted">→ {{ \Illuminate\Support\Str::limit($b->destination_address, 50) }}</span>
            </div>
        </a>
    @empty
        <div class="card"><p class="muted mb-0">No jobs for this period.</p></div>
    @endforelse
@endsection
