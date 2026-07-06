@extends('layouts.app')
@section('title', 'Jobs')

@section('content')
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <h1 class="page-title" style="margin-bottom:2px">
            {{ $day->isToday() ? "Today's Jobs" : $day->format('l d F Y') }}
            <span class="muted" style="font-weight:400;font-size:16px">· {{ count($jobs) }} job(s)</span>
        </h1>
        <div class="toolbar" style="gap:6px">
            <a class="btn btn-light" href="{{ route('jobs.day', ['date' => $day->copy()->subDay()->toDateString()]) }}" style="padding:6px 12px">← Prev</a>
            <form method="GET" action="{{ route('jobs.day') }}" style="display:inline">
                <input type="date" name="date" value="{{ $day->toDateString() }}" onchange="this.form.submit()" style="width:auto">
            </form>
            <a class="btn btn-light" href="{{ route('jobs.day', ['date' => $day->copy()->addDay()->toDateString()]) }}" style="padding:6px 12px">Next →</a>
        </div>
    </div>
    <p class="page-sub"><a href="{{ route('dashboard') }}">← Dashboard</a></p>

    @forelse($jobs as $job)
        <div class="card" style="margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
                <div>
                    <span style="font-size:20px;font-weight:800">{{ $job['pickup']->format('H:i') }}</span>
                    <span style="font-weight:700;margin-left:8px">{{ $job['title'] ?: $job['customer'] }}</span>
                </div>
                <div>
                    <span class="badge badge-{{ \Illuminate\Support\Str::slug($job['status']) }}">{{ $job['status'] }}</span>
                    @if($job['url'])
                        <a href="{{ $job['url'] }}" style="font-size:13px;margin-left:8px">Open booking →</a>
                    @elseif(auth()->user()->isAdmin() && !empty($job['event_id']))
                        <form method="POST" action="{{ route('jobs.import') }}" style="display:inline;margin-left:8px">
                            @csrf
                            <input type="hidden" name="event_id" value="{{ $job['event_id'] }}">
                            <button class="btn btn-primary" style="padding:4px 12px;font-size:12px" title="Pull this calendar job into the system so you can edit it and message the customer">＋ Add to bookings</button>
                        </form>
                    @else
                        <span class="muted" title="This job is on the calendar but not in the booking system." style="font-size:12px;margin-left:8px">calendar only</span>
                    @endif
                </div>
            </div>

            <div class="muted" style="font-size:13px;margin:4px 0 8px">
                {{ $job['customer'] }} · {{ $job['vehicle'] }} · Driver: {{ $job['driver'] }}
                @if($job['ref'] && $job['ref'] !== '—') · <span class="mono">{{ $job['ref'] }}</span> @endif
            </div>
            @if(!empty($job['flight']))
                <div style="margin:0 0 8px">
                    <span class="mono" style="font-size:13px">✈ {{ $job['flight'] }}</span>
                    <a href="{{ \App\Models\Booking::flightRadarLink($job['flight']) }}" target="_blank" rel="noopener" class="btn" style="background:#fc3d02;color:#fff;padding:3px 10px;font-size:12px;margin-left:8px">Track on Flightradar24</a>
                    <a href="{{ \App\Models\Booking::flightSearchLink($job['flight']) }}" target="_blank" rel="noopener" class="btn btn-ghost" style="padding:3px 10px;font-size:12px">Live status</a>
                </div>
            @endif

            @if($job['description'])
                <div style="white-space:pre-wrap;font-size:14px;line-height:1.55;background:rgba(128,128,128,.06);border-radius:8px;padding:12px">{{ trim(preg_replace('/[*]/', '', $job['description'])) }}</div>
            @else
                <div class="muted">Pickup: {{ $job['location'] }}</div>
            @endif
        </div>
    @empty
        <div class="card"><p class="muted mb-0">No jobs on this day.</p></div>
    @endforelse
@endsection
