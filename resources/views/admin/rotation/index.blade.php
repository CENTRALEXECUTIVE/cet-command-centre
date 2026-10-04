@extends('layouts.app')
@section('title', 'Driver rotation')

@section('content')
    <h1 class="page-title">✈ Airport order — executive bookings &amp; rotation driver</h1>
    <p class="page-sub"><strong>Click an airport below</strong> to see its <strong>executive</strong> jobs in the order they came through, with the rotation driver each was given. Spot a job on the wrong driver? Change it inline. (Executive only — that's what Abdi&nbsp;↔&nbsp;Maj rotate on.)</p>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    {{-- Whose turn is it — and who did the last job — so the running order stays
         visible (the turn moves on, but we still see who just went). --}}
    @php
        $turnRows = collect($rotationRows ?? [])->filter(fn ($r) => $r['seeded'] || $r['last_booking']);
    @endphp
    <div class="card" style="margin-bottom:16px">
        <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;margin-bottom:4px">
            <h2 style="margin:0">🔁 Whose turn</h2>
            @if(($rotationDrivers ?? collect())->isNotEmpty())
                <span class="muted" style="font-size:13px">Order:
                    <strong>{{ ($rotationDrivers)->map(fn ($d) => $d->driverProfile?->callsign ?: $d->name)->implode(' → ') }}</strong>
                </span>
            @endif
        </div>
        <p class="hint" style="margin:-2px 0 12px">The <strong>turn</strong> is who's up next; <strong>last job</strong> is who just went — kept alongside so you can still see the order. Executive only.</p>

        @if($turnRows->isEmpty())
            <p class="muted mb-0">No executive airport jobs yet — <strong>{{ ($rotationDrivers ?? collect())->first()?->driverProfile?->callsign ?: (($rotationDrivers ?? collect())->first()?->name ?: 'the first driver') }}</strong> goes first everywhere.</p>
        @else
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px">
                @foreach($turnRows as $r)
                    @php
                        $nextName = $r['next']?->driverProfile?->callsign ?: $r['next']?->name;
                        $lastName = $r['last_driver']?->driverProfile?->callsign ?: $r['last_driver']?->name;
                    @endphp
                    <div style="border:1px solid var(--line);border-radius:12px;padding:11px 13px">
                        <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em">
                            {{ $r['airport']->is_general_pool ? '🧭 '.$r['airport']->name : '✈ '.($r['airport']->code ?: $r['airport']->name) }}
                            @if(($rotationRows ? collect($rotationRows)->pluck('vehicle_type.id')->unique()->count() : 1) > 1)
                                · {{ $r['vehicle_type']->name }}
                            @endif
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;margin-top:8px">
                            <span style="flex:1">
                                <span class="muted" style="font-size:11px;display:block">Turn now</span>
                                <strong style="font-size:16px;color:#b8860b">{{ $nextName ?: '—' }}</strong>
                            </span>
                            <span style="color:var(--muted);font-size:18px">←</span>
                            <span style="flex:1;text-align:right">
                                <span class="muted" style="font-size:11px;display:block">Last job</span>
                                @if($r['last_driver'])
                                    <strong style="font-size:15px">{{ $lastName }}</strong>
                                    @if($r['last_booking'])
                                        <a href="{{ route('bookings.show', $r['last_booking']) }}" class="mono muted" style="font-size:11px;display:block">{{ $r['last_booking']->reference }}</a>
                                    @endif
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </span>
                        </div>
                        {{-- Set who's up next for this airport × vehicle. Logged as a
                             manual override; only the rotation drivers are selectable. --}}
                        @if(($rotationDrivers ?? collect())->count() > 1)
                            <form method="POST" action="{{ route('rotation.set-next') }}" style="margin-top:10px;display:flex;gap:6px;align-items:center">
                                @csrf
                                <input type="hidden" name="airport_id" value="{{ $r['airport']->id }}">
                                <input type="hidden" name="vehicle_type_id" value="{{ $r['vehicle_type']->id }}">
                                <span class="muted" style="font-size:11px">Set turn:</span>
                                <select name="driver_id" onchange="this.form.submit()" style="flex:1;font-size:13px;padding:5px 8px;border:1px solid var(--line);border-radius:8px">
                                    @foreach($rotationDrivers as $d)
                                        <option value="{{ $d->id }}" @selected($r['next'] && $r['next']->id === $d->id)>{{ $d->driverProfile?->callsign ?: $d->name }}</option>
                                    @endforeach
                                </select>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    @include('admin.route-order._panel', [
        'panelRoute' => 'rotation.index',
        'scope' => $orderScope,
        'tabs' => $orderTabs,
        'selected' => $orderSelected,
        'vehicleTabs' => $orderVehicleTabs,
        'selectedVehicle' => $orderSelectedVehicle,
        'rows' => $orderRows,
        'drivers' => $orderDrivers,
    ])

    {{-- Rotation history: the running record — each job, whose turn it was, who
         did it, and why. Stored in rotation_logs on every allocation. --}}
    @php
        $reasonLabel = [
            'advance' => 'Normal turn — advanced',
            'paired_return_no_advance' => 'Return leg — same driver',
            'paired_same_driver' => 'Return leg — same driver',
            'same_customer_continuity' => 'Same customer — kept driver',
            'substitution_no_advance' => 'Substitute — no advance',
            'manual_override' => 'Office set next',
        ];
        $callsign = fn ($u) => $u?->driverProfile?->callsign ?: $u?->name;
    @endphp
    <div class="card" style="margin-top:16px">
        <h2 style="margin-top:0">🗂 Rotation history</h2>
        <p class="hint" style="margin:-2px 0 12px">Every executive job in order — whose turn it was, who actually did it, and why. Newest first.</p>
        @if(($rotationHistory ?? collect())->isEmpty())
            <p class="muted mb-0">No rotation history yet. It fills in as executive airport jobs are allocated.</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%">
                    <thead>
                        <tr>
                            <th>When</th><th>Job</th><th>Airport · Vehicle</th>
                            <th>Whose turn</th><th>Did it</th><th>Why</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rotationHistory as $log)
                            <tr>
                                <td class="muted" style="white-space:nowrap">{{ $log->created_at?->format('D d M, H:i') }}</td>
                                <td style="white-space:nowrap">
                                    @if($log->booking)
                                        <a href="{{ route('bookings.show', $log->booking) }}" class="mono">{{ $log->booking->external_reference ?: $log->booking->reference }}</a>
                                        @if($log->booking->customer) <span class="muted" style="font-size:12px;display:block">{{ $log->booking->customer->name }}</span>@endif
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                                <td style="font-size:13px">{{ $log->airport?->code ?: $log->airport?->name ?: '—' }} · {{ $log->vehicleType?->name ?: '—' }}</td>
                                <td>{{ $callsign($log->fromDriver) ?: '—' }}</td>
                                <td><strong>{{ $callsign($log->toDriver) ?: '—' }}</strong></td>
                                <td class="muted" style="font-size:12px">{{ $reasonLabel[$log->reason] ?? $log->reason }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
