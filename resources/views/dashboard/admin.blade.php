@extends('layouts.app')
@section('title', 'Admin Dashboard')

@section('content')
    <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
        <div>
            <h1 class="page-title" style="margin-bottom:2px">Dispatch Overview</h1>
            <p class="page-sub">{{ now()->format('l d F Y · H:i') }} · {{ auth()->user()->name }}</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <form method="POST" action="{{ route('dashboard.fix-times') }}"
                  onsubmit="return confirm('Match every booking to its calendar time now? The calendar itself is not changed.')">
                @csrf
                <button class="btn btn-light" style="padding:6px 14px">🗓 Match times to calendar</button>
            </form>
            <a href="{{ route('dashboard', ['refresh' => 1]) }}" class="btn btn-light" style="padding:6px 14px">↻ Refresh</a>
        </div>
    </div>

    @if(!empty($reviewReminder))
        <div class="card" style="border-left:4px solid #FBBA2A;background:rgba(251,186,42,.08);margin-bottom:16px">
            <strong>🗓️ Monthly review due (4th–4th).</strong>
            Export the latest bookings from EasyTaxiOffice (and your Google Ads report), then drop them in.
            <a href="{{ route('imports.index') }}" style="margin-left:6px">Go to Imports →</a>
        </div>
    @endif

    @if(!empty($remindersToSend))
        @php $dueCount = collect($remindersToSend)->where('is_due', true)->count(); @endphp
        <div class="card" style="border-left:4px solid #25D366;background:rgba(37,211,102,.07);margin-bottom:16px">
            <strong>📲 Pickup reminders</strong>
            <span class="muted" style="font-size:13px">— {{ $dueCount }} due now, {{ count($remindersToSend) - $dueCount }} upcoming</span>
            <div style="margin-top:8px">
                @foreach($remindersToSend as $r)
                    <div style="display:flex;gap:12px;align-items:baseline;padding:5px 0;border-bottom:1px solid rgba(128,128,128,.1)">
                        <span style="width:64px;flex:none">
                            @if($r['is_due'])<span class="badge badge-pending">Send</span>@else<span class="muted" style="font-size:12px">{{ $r['due']?->format('D H:i') }}</span>@endif
                        </span>
                        <span style="flex:1">{{ $r['customer'] ?? 'Customer' }} <span class="muted">· pickup {{ $r['pickup']->format('D d M, H:i') }}</span></span>
                        <a href="{{ $r['url'] }}" class="btn" style="{{ $r['is_due'] ? 'background:#25D366;color:#fff' : '' }};padding:4px 12px;font-size:12px" @class(['btn-light' => ! $r['is_due']])>{{ $r['is_due'] ? 'Open & send →' : 'Open →' }}</a>
                    </div>
                @endforeach
            </div>
            <p class="hint" style="margin:8px 0 0"><strong>Send</strong> = due now; the rest are coming up. Open the booking, tap "Send on WhatsApp" (from <strong>WhatsApp Business</strong>), then "Mark sent".</p>
        </div>
    @endif

    @if(!empty($reviewsToSend))
        @php $dueReviews = collect($reviewsToSend)->where('is_due', true)->count(); @endphp
        <div class="card" style="border-left:4px solid #FBBA2A;background:rgba(251,186,42,.09);margin-bottom:16px">
            <strong>⭐ Review requests</strong>
            <span class="muted" style="font-size:13px">— {{ $dueReviews }} due now, {{ count($reviewsToSend) - $dueReviews }} shortly</span>
            <div style="margin-top:8px">
                @foreach($reviewsToSend as $r)
                    <div style="display:flex;gap:12px;align-items:baseline;padding:5px 0;border-bottom:1px solid rgba(128,128,128,.1)">
                        <span style="width:64px;flex:none">
                            @if($r['is_due'])<span class="badge badge-pending">Send</span>@else<span class="muted" style="font-size:12px">{{ $r['due']?->format('D H:i') }}</span>@endif
                        </span>
                        <span style="flex:1">{{ $r['customer'] ?? 'Customer' }} <span class="muted">· journey {{ $r['pickup']->format('D d M, H:i') }}</span></span>
                        <a href="{{ $r['url'] }}" class="btn" style="{{ $r['is_due'] ? 'background:#FBBA2A;color:#111' : '' }};padding:4px 12px;font-size:12px" @class(['btn-light' => ! $r['is_due']])>{{ $r['is_due'] ? 'Open & send →' : 'Open →' }}</a>
                    </div>
                @endforeach
            </div>
            <p class="hint" style="margin:8px 0 0">Sent after each completed journey. Open the booking, tap "Send on WhatsApp" (from <strong>WhatsApp Business</strong>), then "Mark sent".</p>
        </div>
    @endif

    @if(!empty($correctedTimes))
        <div class="card" style="border-left:4px solid #1f7a44;background:rgba(31,122,68,.07);margin-bottom:16px">
            <strong>✓ {{ count($correctedTimes) }} booking time(s) matched to the calendar</strong>
            <span class="muted" style="font-size:13px">— the calendar was not changed</span>
            <div style="margin-top:8px">
                @foreach($correctedTimes as $tm)
                    <div style="padding:6px 0;border-bottom:1px solid rgba(128,128,128,.1)">
                        <a href="{{ $tm['url'] }}" class="mono">{{ $tm['ref'] }}</a> <span class="muted">· {{ $tm['customer'] }}</span>
                        <div style="font-size:12px;margin-top:2px">
                            <span class="muted" style="text-decoration:line-through">{{ $tm['from'] }}</span> → <strong>{{ $tm['to'] }}</strong>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if(!empty($timeMismatches))
        <div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.06);margin-bottom:16px">
            <strong>⏰ {{ count($timeMismatches) }} booking(s) don't match our copy of the calendar</strong>
            <span class="muted" style="font-size:13px">— tap “Match times to calendar” (top right) to fix these; for a time you changed live on Google, open the booking and use “Match time to calendar”</span>
            <div style="margin-top:8px">
                @foreach($timeMismatches as $tm)
                    <div style="padding:6px 0;border-bottom:1px solid rgba(128,128,128,.1)">
                        <a href="{{ $tm['url'] }}" class="mono">{{ $tm['ref'] }}</a> <span class="muted">· {{ $tm['customer'] }}</span>
                        <div style="font-size:12px;margin-top:2px">
                            @foreach($tm['times'] as $source => $time){{ $source }}: <strong>{{ $time }}</strong>@if(!$loop->last) <span class="muted">·</span> @endif @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if(!empty($complianceAlerts))
        <div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.06);margin-bottom:16px">
            <strong>🚫 {{ count($complianceAlerts) }} driver(s) blocked — expired documents:</strong>
            <ul style="margin:6px 0 4px">
                @foreach($complianceAlerts as $a)<li>{{ $a['name'] }} — {{ $a['reason'] }}</li>@endforeach
            </ul>
            <a href="{{ route('driver-documents.index') }}" style="font-size:13px">Update documents →</a>
        </div>
    @endif

    {{-- Clickable stat tiles --}}
    <div class="grid grid-3" style="margin-bottom:14px">
        <a class="stat" href="{{ route('jobs.day') }}" style="text-decoration:none;color:inherit;display:block">
            <div class="n">{{ $todayCount }}</div><div class="l">Jobs Today →</div>
        </a>
        <a class="stat" href="{{ route('despatch.board') }}" style="text-decoration:none;color:inherit;display:block">
            <div class="n" style="{{ $pendingCount > 0 ? 'color:#b8860b' : '' }}">{{ $pendingCount }}</div><div class="l">Awaiting Allocation →</div>
        </a>
        <a class="stat" href="{{ route('despatch.board') }}" style="text-decoration:none;color:inherit;display:block">
            <div class="n" style="{{ $activeCount > 0 ? 'color:#1f7a44' : '' }}">{{ $activeCount }}</div><div class="l">Active Now →</div>
        </a>
    </div>
    <div class="grid grid-3" style="margin-bottom:20px">
        <a class="stat" href="{{ route('review.index') }}" style="text-decoration:none;color:inherit;display:block">
            <div class="n">£{{ number_format($todayRevenue, 0) }}</div><div class="l">Today's booked value →</div>
        </a>
        <a class="stat" href="{{ route('payments.index') }}" style="text-decoration:none;color:inherit;display:block">
            <div class="n" style="{{ $cashToday > 0 ? 'color:#b8860b' : '' }}">£{{ number_format($cashToday, 0) }}</div><div class="l">💰 Cash to collect today →</div>
        </a>
        <div class="stat">
            <div class="n">{{ $weekCount }}</div><div class="l">Jobs booked · next 7 days</div>
        </div>
    </div>

    {{-- Quick actions --}}
    <div class="toolbar" style="gap:8px;flex-wrap:wrap;margin-bottom:20px">
        <a href="{{ route('bookings.create') }}" class="btn btn-primary" style="padding:9px 16px">+ New Booking</a>
        <a href="{{ route('quotes.create') }}" class="btn btn-dark" style="padding:9px 16px">New Quote</a>
        <a href="{{ route('despatch.board') }}" class="btn btn-light" style="padding:9px 16px">Dispatch Board</a>
        <a href="{{ route('bookings.index') }}" class="btn btn-light" style="padding:9px 16px">All Bookings</a>
        <a href="{{ route('review.index') }}" class="btn btn-light" style="padding:9px 16px">Review</a>
    </div>

    {{-- Driver status strip --}}
    @if(!empty($driverStatus))
        @php $chip = ['available' => ['#1f7a44','🟢','Available'], 'on-job' => ['#2a6bb0','🔵','On a job'], 'blocked' => ['#b32020','🔴','Blocked'], 'off' => ['#888','⚪','Off']]; @endphp
        <div class="card" style="margin-bottom:16px">
            <h2 style="margin:0 0 10px">Drivers</h2>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                @foreach($driverStatus as $d)
                    @php $c = $chip[$d['status']]; @endphp
                    <div title="{{ $d['reason'] ?? ($d['next'] ? 'Next: '.$d['next']->format('D H:i') : 'No upcoming jobs') }}"
                         style="border:1px solid {{ $c[0] }}33;border-left:3px solid {{ $c[0] }};border-radius:8px;padding:8px 12px;min-width:150px">
                        <div style="font-weight:600">{{ $c[1] }} {{ $d['name'] }}</div>
                        <div class="muted" style="font-size:12px">{{ $c[2] }}@if($d['next']) · next {{ $d['next']->format('D H:i') }}@endif</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Today's schedule --}}
    <div class="card" style="margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <h2 style="margin:0">Today's schedule</h2>
            <a href="{{ route('jobs.day') }}" style="font-size:13px">Full detail →</a>
        </div>
        @forelse(array_slice($todaySchedule, 0, 12) as $j)
            <div style="display:flex;gap:12px;align-items:baseline;padding:7px 0;border-bottom:1px solid rgba(128,128,128,.12)">
                <span style="font-weight:700;width:48px;flex:none">{{ $j['pickup']->format('H:i') }}</span>
                <span style="flex:1">{{ $j['customer'] }} <span class="muted">· {{ $j['vehicle'] }}</span></span>
                <span class="muted" style="font-size:13px">{{ $j['driver'] }}</span>
            </div>
        @empty
            <p class="muted mb-0" style="margin-top:10px">No jobs today.</p>
        @endforelse
    </div>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <h2 style="margin:0">Next 10 Upcoming Jobs</h2>
            <a href="{{ route('despatch.board') }}" style="font-size:13px">Open dispatch board →</a>
        </div>
        @if(empty($upcoming))
            <p class="muted mb-0" style="margin-top:12px">No upcoming bookings. <a href="{{ route('bookings.create') }}">Create one</a>.</p>
        @else
            <table style="margin-top:10px">
                <thead>
                    <tr><th>Ref</th><th>Pickup</th><th>Customer</th><th>Vehicle</th><th>Driver</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @foreach($upcoming as $row)
                        <tr>
                            <td>
                                @if($row['url'])
                                    <a href="{{ $row['url'] }}" class="mono">{{ $row['ref'] }}</a>
                                @else
                                    <a href="{{ route('jobs.day', ['date' => $row['pickup']->toDateString()]) }}" class="mono">{{ $row['ref'] }}</a>
                                @endif
                            </td>
                            <td><a href="{{ route('jobs.day', ['date' => $row['pickup']->toDateString()]) }}" style="color:inherit">{{ $row['pickup']->format('D d M, H:i') }}</a></td>
                            <td>{{ $row['customer'] }}</td>
                            <td>{{ $row['vehicle'] }}</td>
                            <td>
                                @if($row['driver'] === '—' || $row['driver'] === 'COVER')
                                    <a href="{{ route('despatch.board') }}" style="color:#b8860b">Allocate →</a>
                                @else
                                    {{ $row['driver'] }}
                                @endif
                            </td>
                            <td><span class="badge badge-{{ \Illuminate\Support\Str::slug($row['status']) }}">{{ $row['status'] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Live-ish: quietly refresh the figures every 2 minutes. --}}
    <script>setTimeout(function(){ location.href = "{{ route('dashboard', ['refresh' => 1]) }}"; }, 120000);</script>
@endsection
