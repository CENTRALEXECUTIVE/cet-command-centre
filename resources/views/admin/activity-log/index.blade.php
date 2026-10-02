@extends('layouts.app')
@section('title', 'Activity log')

@section('content')
    <div class="list-head" style="align-items:center">
        <div class="form-hero" style="flex:1;margin-bottom:0">
            <div class="form-hero-glow"></div>
            <div class="fh-eyebrow">Fleet &amp; admin · oversight</div>
            <div class="fh-title">Activity log</div>
            <div class="fh-sub">Who did what, and when — across the whole system.</div>
        </div>
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('activity-log.index') }}" class="card" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <div class="field" style="margin:0">
            <label>Action</label>
            <select name="action" onchange="this.form.submit()">
                <option value="">All actions</option>
                @foreach($actions as $a)
                    <option value="{{ $a }}" @selected($action===$a)>{{ ucfirst($a) }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0">
            <label>Person</label>
            <select name="user" onchange="this.form.submit()">
                <option value="">Everyone</option>
                @foreach($users as $u)
                    <option value="{{ $u->id }}" @selected($userId===$u->id)>{{ $u->name }}</option>
                @endforeach
            </select>
        </div>
        @if($action || $userId)<a href="{{ route('activity-log.index') }}" class="btn btn-ghost" style="padding:8px 14px">Clear</a>@endif
    </form>

    @php
        $actionColor = ['created'=>'#1f8b4c','updated'=>'#b8860b','deleted'=>'#b32020','exported'=>'#555','login'=>'#2b6cb0','logout'=>'#888','erased'=>'#b32020'];
    @endphp
    <div class="card">
        <div class="table-scroll">
            <table class="table-modern table-cards">
                <thead><tr><th>When</th><th>Who</th><th>Action</th><th>What</th></tr></thead>
                <tbody>
                    @forelse($logs as $log)
                        @php
                            $type = $log->auditable_type ? class_basename($log->auditable_type) : null;
                            $isBooking = $type === 'Booking' && $log->auditable_id;
                        @endphp
                        <tr>
                            <td data-label="When" style="font-size:13px;white-space:nowrap">{{ $log->created_at?->format('d M Y, H:i') }}</td>
                            <td data-label="Who">{{ $log->user?->name ?? 'System' }}</td>
                            <td data-label="Action"><span class="badge" style="background:{{ $actionColor[$log->action] ?? '#777' }};color:#fff">{{ ucfirst($log->action) }}</span></td>
                            <td data-label="What" style="font-size:13px">
                                @if($isBooking)
                                    <a href="{{ route('bookings.show', $log->auditable_id) }}">Booking #{{ $log->auditable_id }}</a>
                                @elseif($type)
                                    {{ $type }}@if($log->auditable_id) #{{ $log->auditable_id }}@endif
                                @else
                                    <span class="muted">—</span>
                                @endif
                                @if($log->ip_address)<span class="muted" style="font-size:11px"> · {{ $log->ip_address }}</span>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted" style="text-align:center;padding:22px">No activity recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($logs->hasPages())<div style="margin-top:12px">{{ $logs->links() }}</div>@endif
    </div>
@endsection
