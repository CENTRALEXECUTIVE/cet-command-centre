@extends('layouts.app')
@section('title', 'Admin Dashboard')

@section('content')
    <h1 class="page-title">Despatch Overview</h1>
    <p class="page-sub">Welcome back, {{ auth()->user()->name }}. Here is today at a glance.</p>

    @if(!empty($reviewReminder))
        <div class="card" style="border-left:4px solid #FBBA2A;background:rgba(251,186,42,.08);margin-bottom:20px">
            <strong>🗓️ Monthly review due (4th–4th).</strong>
            Export the latest bookings CSV from EasyTaxiOffice and upload it, then run the import to refresh this month's figures.
            <a href="{{ route('review.index', ['preset' => 'last_month']) }}" style="margin-left:6px">Open last month's review →</a>
        </div>
    @endif

    <div class="grid grid-3" style="margin-bottom:24px">
        <div class="stat"><div class="n">{{ $todayCount }}</div><div class="l">Jobs Today</div></div>
        <div class="stat"><div class="n">{{ $pendingCount }}</div><div class="l">Awaiting Allocation</div></div>
        <div class="stat"><div class="n">{{ $activeCount }}</div><div class="l">Active Now</div></div>
    </div>

    <div class="card">
        <h2>Next 10 Upcoming Jobs</h2>
        @if(empty($upcoming))
            <p class="muted mb-0">No upcoming bookings. <a href="{{ route('bookings.create') }}">Create one</a>.</p>
        @else
            <table>
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
                                    <span class="mono">{{ $row['ref'] }}</span>
                                @endif
                            </td>
                            <td>{{ $row['pickup']->format('D d M, H:i') }}</td>
                            <td>{{ $row['customer'] }}</td>
                            <td>{{ $row['vehicle'] }}</td>
                            <td>{{ $row['driver'] }}</td>
                            <td><span class="badge badge-{{ \Illuminate\Support\Str::slug($row['status']) }}">{{ $row['status'] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <a href="{{ route('bookings.create') }}" class="btn btn-primary">+ New Booking</a>
@endsection
