@extends('layouts.app')
@section('title', 'Driver rotation')

@section('content')
    <h1 class="page-title">Driver rotation</h1>
    <p class="page-sub">The running order of who took each executive saloon job and who’s up next. Read-only — the order advances automatically as jobs are allocated.</p>

    {{-- THE MAIN THING: job-by-job memory — who took each job, who's next. --}}
    <div class="card" style="border-left:4px solid #FBBA2A">
        <h2 style="margin-top:0">Job-by-job order <span class="muted" style="font-weight:400;font-size:14px">— newest first</span></h2>
        <p class="muted" style="margin-top:0">Every executive saloon job in order: the driver who took it, and the driver who’s up next. This is the rotation’s memory.</p>
        @if($log->isEmpty())
            <p class="muted" style="margin:0">Nothing logged yet. Each executive saloon job will appear here as it’s allocated.</p>
        @else
            <div class="table-scroll">
                <table>
                    <thead><tr><th>When</th><th>Job</th><th>Passenger</th><th>Airport</th><th>Took the job</th><th>Next up</th></tr></thead>
                    <tbody>
                        @foreach($log as $entry)
                            @php
                                // "advance": from = who took it, to = who's next.
                                // "paired return" / "substitution": to = who took it.
                                $tookJob = $entry->reason === 'advance' ? $entry->fromDriver : $entry->toDriver;
                                $nextUp = $entry->reason === 'advance' ? $entry->toDriver : null;
                            @endphp
                            <tr>
                                <td class="muted" style="white-space:nowrap">{{ $entry->created_at->format('d M, H:i') }}</td>
                                <td>@if($entry->booking)<a href="{{ route('bookings.show', $entry->booking) }}" class="mono" style="font-size:13px">{{ $entry->booking->reference }}</a>@else — @endif</td>
                                <td>{{ $entry->booking?->displayName() ?? '—' }}</td>
                                <td class="muted">{{ $entry->airport?->name ?? '—' }}</td>
                                <td><strong>{{ $tookJob?->name ?? '—' }}</strong>@if($entry->reason !== 'advance')<span class="muted" style="font-size:12px"> ({{ str_replace('_no_advance','',str_replace('_',' ',$entry->reason)) }})</span>@endif</td>
                                <td>{{ $nextUp?->name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div style="margin-top:16px">{{ $log->links() }}</div>
        @endif
    </div>

    {{-- Who's up next, per airport and rotation vehicle type. --}}
    <div class="card">
        <h2 style="margin-top:0">Up next by airport</h2>
        @if(empty($rows))
            <p class="muted" style="margin:0">No airports or rotation vehicle types set up yet.</p>
        @else
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Airport / pool</th><th>Vehicle</th><th>Next driver</th><th>Pointer last moved</th></tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr>
                                <td>{{ $row['airport']->name }}@if($row['airport']->is_general_pool)<span class="muted" style="font-size:12px"> (general)</span>@endif</td>
                                <td class="muted">{{ $row['vehicle_type']->name }}</td>
                                <td><strong>{{ $row['next']?->name ?? '—' }}</strong>@unless($row['seeded'])<span class="muted" style="font-size:12px"> (starts here)</span>@endunless</td>
                                <td class="muted" style="white-space:nowrap">{{ $row['last_advanced_at']?->format('D d M, H:i') ?? 'not yet' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted" style="font-size:12px;margin:10px 0 0">“Starts here” means no saloon job has been allocated for that airport yet, so the first driver goes first.</p>
        @endif
    </div>

    {{-- The order the two rotation drivers take turns in. --}}
    <div class="card">
        <h2 style="margin-top:0">The order</h2>
        @if($drivers->isEmpty())
            <p class="muted" style="margin:0">No rotation drivers set up yet. A rotation driver is an active user with a driver profile that isn’t third-party (that’s Abdi and Maj).</p>
        @else
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                @foreach($drivers as $i => $d)
                    <span class="badge" style="background:#0b0b0b;color:#FBBA2A;font-size:14px;padding:6px 12px">{{ $i + 1 }}. {{ $d->name }}</span>
                    @if(! $loop->last)<span class="muted" style="font-size:18px">→</span>@endif
                @endforeach
                <span class="muted" style="font-size:18px">↺</span>
            </div>
            <p class="muted" style="font-size:13px;margin:10px 0 0">
                They alternate on executive saloon jobs only. A return leg keeps the same driver (the pointer moves once for the pair), and a stand-in covering a job doesn’t change whose turn it is.
            </p>
        @endif
    </div>
@endsection
