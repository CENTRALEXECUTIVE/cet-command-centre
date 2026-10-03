@extends('layouts.app')
@section('title', 'Calendar')

@section('content')
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <div>
            <h1 class="page-title" style="margin-bottom:2px">{{ $month->format('F Y') }}</h1>
            <p class="page-sub" style="margin:0">{{ $total }} {{ \Illuminate\Support\Str::plural('booking', $total) }} this month · tap a day to open it</p>
        </div>
        <div class="toolbar" style="gap:6px">
            <a class="btn btn-light" href="{{ route('calendar.index', ['month' => $month->copy()->subMonth()->format('Y-m')]) }}" style="padding:6px 12px">← Prev</a>
            <a class="btn btn-light" href="{{ route('calendar.index') }}" style="padding:6px 12px">Today</a>
            <a class="btn btn-light" href="{{ route('calendar.index', ['month' => $month->copy()->addMonth()->format('Y-m')]) }}" style="padding:6px 12px">Next →</a>
        </div>
    </div>

    <div class="cal-grid cal-head">
        @foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dow)
            <div class="cal-dow">{{ $dow }}</div>
        @endforeach
    </div>

    @foreach($weeks as $week)
        <div class="cal-grid">
            @foreach($week as $cell)
                @php $jobs = $cell['jobs']; @endphp
                <a class="cal-cell{{ $cell['in_month'] ? '' : ' cal-out' }}{{ $cell['is_today'] ? ' cal-today' : '' }}"
                   href="{{ route('jobs.day', ['date' => $cell['date']->toDateString()]) }}">
                    <div class="cal-daynum">
                        {{ $cell['date']->format('j') }}
                        @if($jobs->count())<span class="cal-count">{{ $jobs->count() }}</span>@endif
                    </div>
                    <div class="cal-jobs">
                        @foreach($jobs->take(4) as $b)
                            <div class="cal-job" title="{{ $b->pickup_at->format('H:i') }} {{ $b->displayName() }}">
                                <span class="cal-time">{{ $b->pickup_at->format('H:i') }}</span>
                                <span class="cal-who">{{ $b->displayName() }}@if($b->allocatedDriverCallsign()) <span class="cal-drv">{{ $b->allocatedDriverCallsign() }}</span>@endif</span>
                            </div>
                        @endforeach
                        @if($jobs->count() > 4)
                            <div class="cal-more">+{{ $jobs->count() - 4 }} more</div>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @endforeach

    <style>
        .cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:6px; margin-top:10px; }
        .cal-head { margin-top:14px; }
        .cal-dow { text-align:center; font-size:12px; font-weight:700; color:var(--muted,#6b6b6b); padding:2px 0; }
        .cal-cell { display:block; min-height:96px; background:var(--white,#fff); border:1px solid var(--line,#e6e6e6);
                    border-radius:10px; padding:6px; text-decoration:none; color:inherit; overflow:hidden; }
        .cal-cell:hover { border-color:var(--gold,#FBBA2A); }
        .cal-out { opacity:.45; }
        .cal-today { border:2px solid var(--gold,#FBBA2A); }
        .cal-daynum { display:flex; justify-content:space-between; align-items:center; font-weight:700; font-size:13px; margin-bottom:4px; }
        .cal-count { background:#0b0b0b; color:var(--gold,#FBBA2A); border-radius:10px; font-size:11px; padding:1px 7px; font-weight:700; }
        .cal-jobs { display:flex; flex-direction:column; gap:3px; }
        .cal-job { font-size:11px; line-height:1.25; display:flex; gap:4px; background:rgba(251,186,42,.12); border-radius:5px; padding:2px 4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .cal-time { font-weight:700; }
        .cal-who { overflow:hidden; text-overflow:ellipsis; }
        .cal-drv { color:#1f7a44; font-weight:700; }
        .cal-more { font-size:11px; color:var(--muted,#6b6b6b); font-weight:600; }
        @media (max-width:720px) {
            .cal-cell { min-height:62px; padding:4px; border-radius:8px; }
            .cal-who { display:none; }           /* tight phones: show the times, tap for detail */
            .cal-job { justify-content:center; padding:1px 3px; }
            .cal-daynum { font-size:12px; margin-bottom:2px; }
        }
    </style>
@endsection
