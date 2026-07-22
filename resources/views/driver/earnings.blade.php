@extends('layouts.app')
@section('title', 'My Earnings')

@section('content')
    <h1 class="page-title">My Earnings</h1>
    <p class="page-sub">{{ auth()->user()->name }} &middot; <a href="{{ route('driver.jobs') }}">Back to jobs</a></p>

    <div class="grid grid-2" style="margin-bottom:24px">
        <div class="stat">
            <div class="n">£{{ number_format($today['earnings'], 2) }}</div>
            <div class="l">Today &middot; {{ $today['jobs'] }} {{ \Illuminate\Support\Str::plural('job', $today['jobs']) }}</div>
        </div>
        <div class="stat">
            <div class="n">£{{ number_format($week['earnings'], 2) }}</div>
            <div class="l">This week &middot; {{ $week['jobs'] }} {{ \Illuminate\Support\Str::plural('job', $week['jobs']) }}</div>
        </div>
    </div>

    <div class="card">
        <h2>Recent completed jobs</h2>
        @if($recent->isEmpty())
            <p class="muted mb-0">No completed jobs yet.</p>
        @else
            <table>
                <thead><tr><th>Date</th><th>Ref</th><th>Vehicle</th><th class="right">Your pay</th></tr></thead>
                <tbody>
                    @foreach($recent as $b)
                        <tr>
                            <td>{{ $b->pickup_at->format('d M, H:i') }}</td>
                            <td class="mono">{{ $b->reference }}</td>
                            <td>{{ $b->vehicleType?->name }}</td>
                            <td class="right">{{ $b->driverPay() !== null ? '£'.number_format($b->driverPay(), 2) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="hint" style="margin:8px 0 0">— means the office hasn't set the pay for that job yet.</p>
        @endif
    </div>
@endsection
