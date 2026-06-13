@extends('layouts.app')
@section('title', 'Despatch Board')

@section('content')
    <h1 class="page-title">Despatch Board</h1>
    <p class="page-sub">{{ $date->format('l d F Y') }}</p>

    @if($errors->any())
        <div class="alert alert-error">{{ $errors->first() }}</div>
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
                            <span class="ref">{{ $b->reference }}</span></div>
                        <div class="meta">
                            <strong>{{ $b->customer?->name }}</strong><br>
                            {{ $b->airport?->code ? $b->airport->code.' · ' : '' }}{{ $b->vehicleType?->name }}<br>
                            {{ \Illuminate\Support\Str::limit($b->pickup_address, 30) }}<br>
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
                                            <option value="{{ $d->id }}">{{ $d->name }}</option>
                                        @endforeach
                                    </select>
                                    <button class="btn-xs" type="submit">Assign</button>
                                </form>
                            @endif

                            {{-- Status transition buttons --}}
                            @foreach($b->status->nextStatuses() as $next)
                                @continue($next === \App\Enums\BookingStatus::Allocated && $status === \App\Enums\BookingStatus::Pending)
                                <form method="POST" action="{{ route('despatch.status', $b) }}">
                                    @csrf
                                    <input type="hidden" name="status" value="{{ $next->value }}">
                                    <button class="btn-xs ghost" type="submit">{{ $next->label() }}</button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="muted" style="font-size:12px;margin:4px 0">—</p>
                @endforelse
            </div>
        @endforeach
    </div>
@endsection
