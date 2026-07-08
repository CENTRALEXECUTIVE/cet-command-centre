@extends('layouts.app')
@section('title', 'Dispatch Board')

@section('content')
    <h1 class="page-title">Dispatch Board</h1>
    <p class="page-sub">{{ $date->format('l d F Y') }}</p>

    @if($errors->any())
        <div class="alert alert-error">{{ $errors->first() }}</div>
    @endif

    @if(session('status'))
        <div class="card" style="border-left:4px solid #FBBA2A;background:rgba(251,186,42,.08);margin-bottom:12px">{{ session('status') }}</div>
    @endif

    @if(isset($blockedDrivers) && $blockedDrivers->isNotEmpty())
        <div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.06);margin-bottom:12px">
            <strong>🚫 Cannot be dispatched (expired documents):</strong>
            <ul style="margin:6px 0 0">
                @foreach($blockedDrivers as $bd)
                    <li>{{ $bd['name'] }} — {{ $bd['reason'] }}</li>
                @endforeach
            </ul>
            <a href="{{ route('driver-documents.index') }}" style="font-size:13px">Update documents →</a>
        </div>
    @endif

    <div class="toolbar">
        <form method="GET" action="{{ route('despatch.board') }}" style="display:flex;gap:8px;align-items:center">
            <input type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()" style="width:auto">
        </form>
        <div class="stat" style="padding:10px 16px"><strong>{{ $totals['all'] }}</strong> jobs &middot;
            <strong>{{ $totals['unallocated'] }}</strong> unallocated &middot;
            <strong>{{ $totals['active'] }}</strong> active</div>
    </div>

    <div class="board">
        @foreach($statuses as $status)
            @php $jobs = $columns[$status->value]; @endphp
            <div class="board-col">
                <h3>{{ $status->label() }} <span class="count">{{ $jobs->count() }}</span></h3>

                @forelse($jobs as $b)
                    <div class="job-card">
                        <div class="time">{{ $b->pickup_at->format('H:i') }}
                            <a href="{{ route('bookings.show', $b) }}" class="ref" title="Open booking">{{ $b->reference }}</a></div>
                        <div class="meta">
                            <a href="{{ route('bookings.show', $b) }}" style="color:inherit"><strong>{{ $b->displayName() }}</strong></a><br>
                            {{ $b->airport?->code ? $b->airport->code.' · ' : '' }}{{ $b->displayVehicleType() }}<br>
                            {{ \Illuminate\Support\Str::limit($b->displayPickupAddress(), 30) }}<br>
                            {{ $b->passengerCount() }} pax · {{ $b->luggageShort() }}<br>
                            Driver: {{ $b->driver?->name ?? '—' }}
                        </div>

                        <div class="actions">
                            {{-- Allocation controls for unallocated jobs --}}
                            @if($status === \App\Enums\BookingStatus::Pending)
                                @if($b->vehicleType?->affects_rotation)
                                    <form method="POST" action="{{ route('despatch.auto-allocate', $b) }}">
                                        @csrf
                                        <button class="btn-xs gold" type="submit">Auto (rotation)</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('despatch.allocate', $b) }}" style="display:flex;gap:4px">
                                    @csrf
                                    <select name="driver_id" class="inline-select" required>
                                        <option value="">Driver…</option>
                                        @foreach($drivers as $d)
                                            @php
                                                $reg = $d->driverProfile?->defaultVehicle?->registration;
                                                $label = $d->driverProfile?->callsign ?: $d->name;
                                            @endphp
                                            <option value="{{ $d->id }}">{{ $label }}@if($reg) ({{ $reg }})@endif</option>
                                        @endforeach
                                    </select>
                                    <button class="btn-xs" type="submit">Assign</button>
                                </form>
                            @endif

                            {{-- Step-by-step status transition buttons --}}
                            @foreach($b->status->nextStatuses() as $next)
                                @continue($next === \App\Enums\BookingStatus::Allocated && $status === \App\Enums\BookingStatus::Pending)
                                @continue(in_array($next, [\App\Enums\BookingStatus::Complete, \App\Enums\BookingStatus::Cancelled, \App\Enums\BookingStatus::NoShow], true))
                                <form method="POST" action="{{ route('despatch.status', $b) }}">
                                    @csrf
                                    <input type="hidden" name="status" value="{{ $next->value }}">
                                    <button class="btn-xs ghost" type="submit">{{ $next->label() }}</button>
                                </form>
                            @endforeach
                        </div>

                        {{-- One-tap close-out (admin): jump straight to done/no-show/cancelled --}}
                        @unless($b->status->isTerminal())
                            <div class="actions" style="margin-top:4px">
                                <form method="POST" action="{{ route('despatch.quick-status', $b) }}" onsubmit="return confirm('Mark {{ $b->reference }} completed?')">
                                    @csrf<input type="hidden" name="status" value="complete">
                                    <button class="btn-xs" style="background:#1f7a44;color:#fff" type="submit">✓ Completed</button>
                                </form>
                                <form method="POST" action="{{ route('despatch.quick-status', $b) }}" onsubmit="return confirm('Mark {{ $b->reference }} as no-show?')">
                                    @csrf<input type="hidden" name="status" value="no_show">
                                    <button class="btn-xs ghost" type="submit">No-show</button>
                                </form>
                                <form method="POST" action="{{ route('despatch.quick-status', $b) }}" onsubmit="return confirm('Cancel {{ $b->reference }}?')">
                                    @csrf<input type="hidden" name="status" value="cancelled">
                                    <button class="btn-xs ghost" style="color:#b32020" type="submit">Cancel</button>
                                </form>
                            </div>
                        @endunless
                    </div>
                @empty
                    <p class="muted" style="font-size:12px;margin:4px 0">—</p>
                @endforelse
            </div>
        @endforeach
    </div>
@endsection
