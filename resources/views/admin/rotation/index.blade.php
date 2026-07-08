@extends('layouts.app')
@section('title', 'Driver rotation')

@section('content')
    <h1 class="page-title">Driver rotation</h1>
    <p class="page-sub">Who takes the next executive saloon job, per airport. The order advances automatically each time a job is allocated — and you can correct any "next up" below if it drifts from the real order.</p>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    {{-- The order the two rotation drivers take turns in. --}}
    <div class="card">
        <h2 style="margin-top:0">The order</h2>
        @if($drivers->isEmpty())
            <p class="muted" style="margin:0">No rotation drivers set up yet. A rotation driver is an active user with a driver profile that isn't third-party (that's Abdi and Maj).</p>
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

    {{-- Who's up next, per airport and rotation vehicle type. --}}
    <div class="card">
        <h2 style="margin-top:0">Up next</h2>
        @if(empty($rows))
            <p class="muted" style="margin:0">No airports or rotation vehicle types set up yet.</p>
        @else
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Airport / pool</th><th>Vehicle</th><th>Next driver</th><th>Pointer last moved</th><th>Correct it</th></tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr>
                                <td>{{ $row['airport']->name }}@if($row['airport']->is_general_pool)<span class="muted" style="font-size:12px"> (general)</span>@endif</td>
                                <td class="muted">{{ $row['vehicle_type']->name }}</td>
                                <td><strong>{{ $row['next']?->name ?? '—' }}</strong>@unless($row['seeded'])<span class="muted" style="font-size:12px"> (starts here)</span>@endunless</td>
                                <td class="muted" style="white-space:nowrap">{{ $row['last_advanced_at']?->format('D d M, H:i') ?? 'not yet' }}</td>
                                <td>
                                    <form method="POST" action="{{ route('rotation.set-next') }}" style="display:flex;gap:6px;align-items:center">
                                        @csrf
                                        <input type="hidden" name="airport_id" value="{{ $row['airport']->id }}">
                                        <input type="hidden" name="vehicle_type_id" value="{{ $row['vehicle_type']->id }}">
                                        <select name="driver_id" style="width:auto;padding:4px 8px;font-size:13px">
                                            @foreach($drivers as $d)
                                                <option value="{{ $d->id }}" @selected($row['next']?->id === $d->id)>{{ $d->name }}</option>
                                            @endforeach
                                        </select>
                                        <button class="btn btn-ghost" style="padding:4px 10px;font-size:12px">Set</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted" style="font-size:12px;margin:10px 0 0">“Starts here” means no saloon job has been allocated for that airport yet, so the first driver goes first. Use <strong>Set</strong> to correct a pointer — it's logged as a manual override and the paste-a-booking preview follows it immediately.</p>
        @endif
    </div>

    {{-- The memory: how the pointer moved, most recent first. --}}
    <div class="card">
        <h2 style="margin-top:0">Recent history</h2>
        <p class="muted" style="margin-top:0">This is the rotation’s memory — every time a job moved the pointer to the next driver, it’s logged here.</p>
        @if($log->isEmpty())
            <p class="muted" style="margin:0">Nothing logged yet. Entries appear as executive saloon jobs are allocated.</p>
        @else
            <div class="table-scroll">
                <table>
                    <thead><tr><th>When</th><th>Airport</th><th>Vehicle</th><th>Change</th><th>Why</th><th>Booking</th></tr></thead>
                    <tbody>
                        @foreach($log as $entry)
                            <tr>
                                <td class="muted" style="white-space:nowrap">{{ $entry->created_at->format('d M, H:i') }}</td>
                                <td>{{ $entry->airport?->name ?? '—' }}</td>
                                <td class="muted">{{ $entry->vehicleType?->name ?? '—' }}</td>
                                <td style="white-space:nowrap">{{ $entry->fromDriver?->name ?? '—' }} <span class="muted">→</span> <strong>{{ $entry->toDriver?->name ?? '—' }}</strong></td>
                                <td class="muted" style="font-size:13px">{{ str_replace('_', ' ', $entry->reason) }}</td>
                                <td>@if($entry->booking)<a href="{{ route('bookings.show', $entry->booking) }}" class="mono" style="font-size:13px">{{ $entry->booking->reference }}</a>@else — @endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
