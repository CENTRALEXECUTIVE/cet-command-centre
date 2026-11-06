@extends('layouts.app')
@section('title', 'My assistant')

@section('content')
@php
    $gbp = fn ($v) => $v === null ? '–' : '£'.number_format((float) $v, 2);
    $m = $b['money'];
    $owedToUs = collect($m['owed_to_us'])->sum('amount');
    $weOwe = collect($m['we_owe'])->sum('amount');
    $cash = (float) ($m['ltd'] ?? 0) + (float) ($m['pvt'] ?? 0);
    $p = $b['prepaid'];
    $cushionGap = round($p['external_share'] - $cash, 2);
    $o = $b['outreach'];
    $openTodos = collect($b['todos'])->where('done', false);
    $tz = config('app.timezone');
    $jobLine = function ($j) use ($tz) {
        return ($j->pickup_at?->timezone($tz)->format('H:i') ?? '–').' · '.e($j->displayCustomerName() ?? 'Customer')
            .' · '.e($j->displayVehicleType() ?? '').' · '.($j->driver ? e($j->driver->name) : '<strong style="color:#b32020">no driver</strong>');
    };
@endphp

    <div class="list-head" style="align-items:center">
        <div class="form-hero" style="flex:1;margin-bottom:0">
            <div class="form-hero-glow"></div>
            <div class="fh-eyebrow">{{ $b['now']->format('l j F') }}</div>
            <div class="fh-title">My assistant</div>
            <div class="fh-sub">Everything for today in one place: jobs, money, drivers to pay, outreach and your to-do list.</div>
        </div>
    </div>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08)">{{ $errors->first() }}</div>@endif

    {{-- At a glance --}}
    <div class="grid grid-3" style="gap:12px;margin-bottom:14px">
        <a class="card" href="{{ route('despatch.board') }}" style="margin:0;text-decoration:none;color:inherit">
            <div class="muted" style="font-size:13px">Jobs today</div>
            <div style="font-size:26px;font-weight:800">{{ $b['today']->count() }}</div>
            <div style="font-size:12px">{{ $b['today']->whereNull('driver_id')->count() }} without a driver · tomorrow {{ $b['tomorrow']->count() }}</div>
        </a>
        <div class="card" style="margin:0">
            <div class="muted" style="font-size:13px">Cash in the bank (typed in)</div>
            <div style="font-size:26px;font-weight:800">{{ $gbp($cash) }}</div>
            <div style="font-size:12px">+ {{ $gbp($m['incoming']) }} incoming · {{ $gbp($owedToUs) }} owed to us</div>
        </div>
        <div class="card" style="margin:0;{{ $cushionGap > 0 ? 'border-left:4px solid #b32020' : 'border-left:4px solid #1f9d55' }}">
            <div class="muted" style="font-size:13px">Drivers' share of prepaid future jobs</div>
            <div style="font-size:26px;font-weight:800">{{ $gbp($p['external_share']) }}</div>
            <div style="font-size:12px">{{ $cushionGap > 0 ? 'Short by '.$gbp($cushionGap).' against cash. Pay external drivers first.' : 'Covered by cash in the bank.' }}</div>
        </div>
        <a class="card" href="{{ route('outreach.batch') }}" style="margin:0;text-decoration:none;color:inherit">
            <div class="muted" style="font-size:13px">Outreach today</div>
            <div style="font-size:26px;font-weight:800">{{ $o['sent_today'] }} / {{ $o['limit'] }}</div>
            <div style="font-size:12px">{{ $o['weekend'] ? 'Weekend: no sending' : $o['ready'].' ready to approve' }} · {{ $o['replies_week'] }} replies this week</div>
        </a>
        <a class="card" href="#todo" style="margin:0;text-decoration:none;color:inherit">
            <div class="muted" style="font-size:13px">To-do</div>
            <div style="font-size:26px;font-weight:800">{{ $openTodos->count() }}</div>
            <div style="font-size:12px">{{ $openTodos->filter(fn ($t) => ($t['due'] ?? null) && $t['due'] <= now($tz)->toDateString())->count() }} due today or overdue</div>
        </a>
        <div class="card" style="margin:0;{{ $b['unallocatedSoon']->count() ? 'border-left:4px solid #e6a100' : '' }}">
            <div class="muted" style="font-size:13px">No driver yet (next 7 days)</div>
            <div style="font-size:26px;font-weight:800">{{ $b['unallocatedSoon']->count() }}</div>
            <div style="font-size:12px">Allocate before the day</div>
        </div>
    </div>

    {{-- Jobs --}}
    <div class="grid grid-2" style="gap:12px">
        <div class="card" style="margin:0">
            <h3 style="margin-top:0">Today</h3>
            @forelse($b['today'] as $j)
                <div style="padding:6px 0;border-bottom:1px solid rgba(0,0,0,.06)"><a href="{{ route('bookings.show', $j) }}">{!! $jobLine($j) !!}</a></div>
            @empty <p class="muted mb-0">No jobs today.</p> @endforelse
        </div>
        <div class="card" style="margin:0">
            <h3 style="margin-top:0">Tomorrow</h3>
            @forelse($b['tomorrow'] as $j)
                <div style="padding:6px 0;border-bottom:1px solid rgba(0,0,0,.06)"><a href="{{ route('bookings.show', $j) }}">{!! $jobLine($j) !!}</a></div>
            @empty <p class="muted mb-0">No jobs tomorrow.</p> @endforelse
        </div>
    </div>

    @if($b['unallocatedSoon']->count())
        <div class="card" style="margin-top:12px">
            <h3 style="margin-top:0">Needs a driver (next 7 days)</h3>
            @foreach($b['unallocatedSoon'] as $j)
                <div style="padding:6px 0;border-bottom:1px solid rgba(0,0,0,.06)"><a href="{{ route('bookings.show', $j) }}">{{ $j->pickup_at?->timezone($tz)->format('D j M H:i') }} · {{ $j->displayCustomerName() }} · {{ $j->displayVehicleType() }}</a></div>
            @endforeach
        </div>
    @endif

    {{-- Money --}}
    <div class="card" style="margin-top:12px">
        <h3 style="margin-top:0">Money board</h3>
        <div class="grid grid-3" style="gap:12px">
            <div>
                <div class="muted" style="font-size:13px">Prepaid future jobs (live)</div>
                <div>{{ $p['jobs'] }} jobs · {{ $gbp($p['fares']) }} of fares</div>
                <div>External drivers' share: <strong>{{ $gbp($p['external_share']) }}</strong> ({{ $gbp($p['next_30_days_external']) }} in the next 30 days)</div>
                <div>Directors' own share: {{ $gbp($p['director_share']) }}</div>
            </div>
            <div>
                <div class="muted" style="font-size:13px">Owed to us</div>
                @forelse($m['owed_to_us'] as $l)<div>{{ $l['label'] }} @if($l['amount'] !== null)<strong>{{ $gbp($l['amount']) }}</strong>@endif</div>@empty<div class="muted">Nothing listed</div>@endforelse
            </div>
            <div>
                <div class="muted" style="font-size:13px">We owe</div>
                @forelse($m['we_owe'] as $l)<div>{{ $l['label'] }} @if($l['amount'] !== null)<strong>{{ $gbp($l['amount']) }}</strong>@endif</div>@empty<div class="muted">Nothing listed</div>@endforelse
                @if($weOwe)<div class="muted" style="font-size:12px">Total {{ $gbp($weOwe) }}</div>@endif
            </div>
        </div>
        <p class="muted" style="font-size:12px;margin:10px 0 0">Pay order: external drivers for completed jobs first, then running costs, then directors. Keep the external drivers' share of prepaid jobs in a Tide Budget.</p>

        <details style="margin-top:10px">
            <summary style="cursor:pointer"><strong>Update the money board</strong>@if($m['updated_at']) <span class="muted" style="font-size:12px">· last updated {{ \Illuminate\Support\Carbon::parse($m['updated_at'])->format('D j M H:i') }} by {{ $m['updated_by'] }}</span>@endif</summary>
            <form method="POST" action="{{ route('assistant.money') }}" style="margin-top:10px">
                @csrf
                <div class="grid grid-3" style="gap:10px">
                    <label>Ltd bank (£)<input type="number" step="0.01" name="ltd" class="form-control" value="{{ $m['ltd'] }}"></label>
                    <label>PVT bank (£)<input type="number" step="0.01" name="pvt" class="form-control" value="{{ $m['pvt'] }}"></label>
                    <label>Incoming payouts (£)<input type="number" step="0.01" name="incoming" class="form-control" value="{{ $m['incoming'] }}"></label>
                </div>
                <div class="grid grid-2" style="gap:10px;margin-top:10px">
                    <label>Owed to us (one per line, e.g. "Company X - 620")
                        <textarea name="owed_to_us" rows="4" class="form-control">{{ collect($m['owed_to_us'])->map(fn ($l) => $l['label'].($l['amount'] !== null ? ' - '.$l['amount'] : ''))->implode("\n") }}</textarea></label>
                    <label>We owe (one per line, e.g. "Abdi last week - 900")
                        <textarea name="we_owe" rows="4" class="form-control">{{ collect($m['we_owe'])->map(fn ($l) => $l['label'].($l['amount'] !== null ? ' - '.$l['amount'] : ''))->implode("\n") }}</textarea></label>
                </div>
                <button class="btn btn-primary" style="margin-top:10px">Save money board</button>
            </form>
        </details>
    </div>

    {{-- To-do --}}
    <div class="card" id="todo" style="margin-top:12px">
        <h3 style="margin-top:0">To-do (shared by both directors)</h3>
        <form method="POST" action="{{ route('assistant.todos.add') }}" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
            @csrf
            <input name="text" class="form-control" style="flex:1 1 220px" placeholder="Add a to-do, e.g. Chase the £620" required maxlength="200">
            <input type="date" name="due" class="form-control" style="flex:0 0 160px">
            <button class="btn btn-primary">Add</button>
        </form>
        @php($sorted = collect($b['todos'])->sortBy(fn ($t) => [($t['done'] ?? false) ? 1 : 0, $t['due'] ?? '9999']))
        @forelse($sorted as $t)
            @php($overdue = ! ($t['done'] ?? false) && ($t['due'] ?? null) && $t['due'] <= now($tz)->toDateString())
            <div style="display:flex;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid rgba(0,0,0,.06)">
                <form method="POST" action="{{ route('assistant.todos.toggle', $t['id']) }}">@csrf
                    <button class="btn btn-ghost" style="padding:2px 8px" title="Done / not done">{{ ($t['done'] ?? false) ? '☑' : '☐' }}</button></form>
                <span style="flex:1;{{ ($t['done'] ?? false) ? 'text-decoration:line-through;opacity:.6' : '' }}">
                    {{ $t['text'] }}
                    @if($t['due'] ?? null)<span style="font-size:12px;{{ $overdue ? 'color:#b32020;font-weight:700' : 'opacity:.7' }}"> · {{ \Illuminate\Support\Carbon::parse($t['due'])->format('D j M') }}</span>@endif
                    <span class="muted" style="font-size:11px"> · {{ $t['by'] ?? '' }}</span>
                </span>
                <form method="POST" action="{{ route('assistant.todos.delete', $t['id']) }}">@csrf
                    <button class="btn btn-ghost" style="padding:2px 8px" title="Delete">✕</button></form>
            </div>
        @empty
            <p class="muted mb-0">Nothing on the list.</p>
        @endforelse
    </div>
@endsection
