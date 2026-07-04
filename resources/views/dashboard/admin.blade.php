@extends('layouts.app')
@section('title', 'Admin Dashboard')

@section('content')
    <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
        <div>
            <h1 class="page-title" style="margin-bottom:2px">Despatch Overview</h1>
            <p class="page-sub">{{ now()->format('l d F Y · H:i') }} · {{ auth()->user()->name }}</p>
        </div>
        <a href="{{ route('dashboard', ['refresh' => 1]) }}" class="btn btn-light" style="padding:6px 14px">↻ Refresh</a>
    </div>

    @if(!empty($reviewReminder))
        <div class="card" style="border-left:4px solid #FBBA2A;background:rgba(251,186,42,.08);margin-bottom:16px">
            <strong>🗓️ Monthly review due (4th–4th).</strong>
            Export the latest bookings from EasyTaxiOffice (and your Google Ads report), then drop them in.
            <a href="{{ route('imports.index') }}" style="margin-left:6px">Go to Imports →</a>
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
    <div class="grid grid-2" style="margin-bottom:20px">
        <a class="stat" href="{{ route('review.index') }}" style="text-decoration:none;color:inherit;display:block">
            <div class="n">£{{ number_format($todayRevenue, 0) }}</div><div class="l">Today's booked value →</div>
        </a>
        <div class="stat">
            <div class="n">{{ $weekCount }}</div><div class="l">Jobs booked · next 7 days</div>
        </div>
    </div>

    {{-- Quick actions --}}
    <div class="toolbar" style="gap:8px;flex-wrap:wrap;margin-bottom:20px">
        <a href="{{ route('bookings.create') }}" class="btn btn-primary" style="padding:9px 16px">+ New Booking</a>
        <a href="{{ route('quotes.create') }}" class="btn btn-dark" style="padding:9px 16px">New Quote</a>
        <a href="{{ route('despatch.board') }}" class="btn btn-light" style="padding:9px 16px">Despatch Board</a>
        <a href="{{ route('bookings.index') }}" class="btn btn-light" style="padding:9px 16px">All Bookings</a>
        <a href="{{ route('review.index') }}" class="btn btn-light" style="padding:9px 16px">Review</a>
    </div>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <h2 style="margin:0">Next 10 Upcoming Jobs</h2>
            <a href="{{ route('despatch.board') }}" style="font-size:13px">Open despatch board →</a>
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
