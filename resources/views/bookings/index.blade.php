@extends('layouts.app')
@section('title', 'Bookings')

@section('content')
    <div class="list-head">
        <div>
            <h1 class="page-title" style="margin:0">Bookings</h1>
            @if(!empty($q))
                <p class="page-sub" style="margin:2px 0 0">Search results for “<strong>{{ $q }}</strong>” · {{ $bookings->total() }} found — <a href="{{ route('bookings.index') }}">clear</a></p>
            @else
                <p class="page-sub" style="margin:2px 0 0">
                    {{ $bookings->total() }} {{ Str::plural('journey', $bookings->total()) }}
                    @if(!empty($statusFilter))· <strong>{{ \App\Enums\BookingStatus::from($statusFilter)->label() }}</strong> only@else<span class="muted">· cancelled hidden</span>@endif
                </p>
            @endif
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <form method="GET" action="{{ route('bookings.index') }}" role="search">
                <input type="search" name="q" value="{{ $q ?? '' }}" placeholder="🔍 Search ref, ETO, name, phone…"
                       style="width:240px;max-width:52vw;padding:9px 12px;border-radius:10px">
            </form>
            @if(auth()->user()->isAdmin() || auth()->user()->isCorporateClient())
                <a href="{{ route('bookings.create') }}" class="btn btn-primary" style="padding:9px 16px;white-space:nowrap">+ New</a>
            @endif
        </div>
    </div>

    {{-- Filter tabs give the list an order: upcoming soonest-first by default. --}}
    @php
        $tabs = ['upcoming' => 'Upcoming', 'today' => 'Today', 'past' => 'Past', 'all' => 'All'];
    @endphp
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <div class="bk-tabs">
            @foreach($tabs as $key => $label)
                <a href="{{ route('bookings.index', array_filter(['filter' => $key, 'q' => $q ?: null, 'status' => ($statusFilter ?? null) ?: null])) }}"
                   class="bk-tab {{ empty($month) && ($filter ?? 'upcoming') === $key ? 'active' : '' }}">{{ $label }}</a>
            @endforeach
        </div>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            {{-- Status filter: pending / completed / cancelled / … --}}
            <form method="GET" action="{{ route('bookings.index') }}" style="display:flex;align-items:center;gap:6px">
                @if(!empty($q))<input type="hidden" name="q" value="{{ $q }}">@endif
                @if(!empty($month))<input type="hidden" name="month" value="{{ $month->format('Y-m') }}">@else<input type="hidden" name="filter" value="{{ $filter ?? 'upcoming' }}">@endif
                <label for="bk-status" class="muted" style="font-size:13px">Status</label>
                @php
                    // Common statuses first; the unlikely Cancelled / No-show sit at the bottom.
                    $statusOptions = collect(\App\Enums\BookingStatus::cases())
                        ->sortBy(fn ($s) => in_array($s->value, ['cancelled', 'no_show'], true) ? 1 : 0)
                        ->values();
                @endphp
                <select id="bk-status" name="status" onchange="this.form.submit()" style="width:auto">
                    <option value="">All active</option>
                    @foreach($statusOptions as $st)
                        <option value="{{ $st->value }}" @selected(($statusFilter ?? null) === $st->value)>{{ $st->label() }}</option>
                    @endforeach
                </select>
            </form>
            {{-- Month view: pick a month to see every booking in it (for payroll). --}}
            <form method="GET" action="{{ route('bookings.index') }}" style="display:flex;align-items:center;gap:6px">
                @if(!empty($q))<input type="hidden" name="q" value="{{ $q }}">@endif
                @if(!empty($statusFilter))<input type="hidden" name="status" value="{{ $statusFilter }}">@endif
                <label for="bk-month" class="muted" style="font-size:13px">Month</label>
                <input id="bk-month" type="month" name="month"
                       value="{{ $month?->format('Y-m') }}" onchange="this.form.submit()" style="width:auto">
            </form>
            @if(auth()->user()->isAdmin())
                @php $exportParams = array_filter(['month' => $month?->format('Y-m'), 'filter' => empty($month) ? ($filter ?? null) : null, 'status' => $statusFilter ?: null, 'q' => $q ?: null]); @endphp
                <a href="{{ route('bookings.export', $exportParams) }}" class="btn btn-ghost" style="padding:8px 12px;font-size:13px;white-space:nowrap" title="Download this view as CSV">⬇ Export</a>
            @endif
        </div>
    </div>
    @if(!empty($month))
        <p class="page-sub" style="margin:8px 0 0">Showing <strong>{{ $bookings->total() }}</strong> booking{{ $bookings->total() === 1 ? '' : 's' }} in <strong>{{ $month->format('F Y') }}</strong> — <a href="{{ route('bookings.index') }}">back to upcoming</a></p>
    @elseif(($filter ?? '') === 'booked-today')
        <p class="page-sub" style="margin:8px 0 0">Showing <strong>{{ $bookings->total() }}</strong> booking{{ $bookings->total() === 1 ? '' : 's' }} that came in <strong>today</strong> — <a href="{{ route('bookings.index') }}">back to upcoming</a></p>
    @endif

    @if(!empty($driverName))
        <p class="page-sub" style="margin:8px 0 0"><strong>{{ $bookings->total() }}</strong> booking{{ $bookings->total() === 1 ? '' : 's' }} for driver <strong>{{ $driverName }}</strong>@if(!empty($month)) in {{ $month->format('F Y') }}@endif — <a href="{{ route('bookings.index', ['month' => $month?->format('Y-m')]) }}">clear driver</a></p>
    @endif

    {{-- Needs attention: unallocated soon, flagged duplicate, or audit issue. --}}
    @if(!empty($attention) && $attention->isNotEmpty())
        <div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.05);margin:14px 0 0;padding:12px 14px">
            <strong>⚠ Needs attention <span class="muted">({{ $attention->count() }})</span></strong>
            <div style="margin-top:6px">
                @foreach($attention as $item)
                    @php $ab = $item['booking']; @endphp
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:6px 0;border-bottom:1px solid rgba(128,128,128,.12)">
                        <a href="{{ route('bookings.show', $ab) }}" class="mono">{{ $ab->reference }}</a>
                        <span style="flex:1;min-width:160px">{{ $ab->displayCustomerName() }} <span class="muted">· {{ $ab->pickup_at->format('D d M, H:i') }}@if($ab->airport?->code) · ✈ {{ $ab->airport->code }}@endif</span></span>
                        @foreach($item['reasons'] as $r)
                            @if($r === 'unallocated')<span class="badge" style="background:#b32020;color:#fff">Unallocated</span>
                            @elseif($r === 'duplicate')<a href="{{ route('bookings.show', $ab) }}#duplicate" class="badge" style="background:#8a5a00;color:#fff;text-decoration:none">⑂ dup?</a>
                            @elseif($r === 'audit')<span class="badge" style="background:#8a5a00;color:#fff">⚠ audit</span>@endif
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if($bookings->isEmpty())
        <div class="card" style="text-align:center;padding:40px 20px">
            <div style="font-size:34px">🗓️</div>
            <p class="muted" style="margin:8px 0 0">No {{ ($filter ?? 'upcoming') === 'all' ? '' : ($filter ?? 'upcoming').' ' }}bookings{{ !empty($q) ? ' match your search' : '' }}.</p>
            @if(auth()->user()->isAdmin() || auth()->user()->isCorporateClient())
                <a href="{{ route('bookings.create') }}" class="btn btn-primary" style="margin-top:14px;padding:9px 18px">+ New booking</a>
            @endif
        </div>
    @else
        @php
            $grouped = $bookings->getCollection()->groupBy(fn ($b) => $b->pickup_at->toDateString());
            $dayLabel = function ($dateStr) {
                $d = \Illuminate\Support\Carbon::parse($dateStr);
                if ($d->isToday()) return 'Today';
                if ($d->isTomorrow()) return 'Tomorrow';
                if ($d->isYesterday()) return 'Yesterday';
                return $d->format('D d M Y');
            };
        @endphp

        <div class="bk-list">
            @foreach($grouped as $day => $dayBookings)
                <div class="bk-day">
                    @php $dayTakings = $dayBookings->sum(fn ($b) => $b->fareAmount() ?? 0); @endphp
                    <div class="bk-day-label">{{ $dayLabel($day) }} <span class="bk-day-count">{{ $dayBookings->count() }}</span>@if($dayTakings > 0 && (auth()->user()->isAdmin() || auth()->user()->isCorporateClient()))<span class="muted" style="font-weight:500;margin-left:6px">· £{{ number_format($dayTakings, 0) }}</span>@endif</div>

                    @foreach($dayBookings as $b)
                        <a href="{{ route('bookings.show', $b) }}" class="bk-card s-{{ $b->status->value }}">
                            <div class="bk-time">
                                <span class="t">{{ $b->pickup_at->format('H:i') }}</span>
                                @if($b->airport?->code)<span class="d">✈ {{ $b->airport->code }}</span>@endif
                            </div>

                            <div class="bk-main">
                                <div class="bk-name">
                                    {{ $b->displayCustomerName() }}
                                    <span class="bk-ref">{{ $b->reference }}</span>
                                    @if(!empty($b->meta['audit_issues']))
                                        <span title="Flagged by ETO audit — {{ implode('; ', $b->meta['audit_issues']) }}">⚠</span>
                                    @endif
                                    @if($b->looksDuplicated())
                                        <span title="Possible duplicate — tap to review &amp; merge" style="color:#b32020;font-weight:700;cursor:pointer"
                                              onclick="event.preventDefault();event.stopPropagation();window.location='{{ route('bookings.show', $b) }}#duplicate'">⑂ dup?</span>
                                    @endif
                                </div>
                                <div class="bk-route">{{ Str::limit($b->displayPickupAddress(), 26) }} <span class="arr">→</span> {{ Str::limit($b->displayDropoffAddress(), 26) }}</div>
                                <div class="bk-tags">
                                    <span>{{ $b->displayVehicleType() }}</span>
                                    <span>·</span><span>{{ $b->passengerCount() }} pax</span>
                                    <span>·</span><span>{{ $b->driver?->name ?? 'Unassigned' }}</span>
                                    @if($b->payment_method)<span>·</span><span>{{ $b->payment_method->emoji() ?: $b->payment_method->label() }}</span>@endif
                                </div>
                            </div>

                            <div class="bk-side">
                                <span class="badge badge-{{ $b->status->value }}">{{ $b->status->label() }}</span>
                                @if($b->driverFullyPaid())
                                    <span class="badge" style="background:#1f7a44;color:#fff" title="Driver paid for this job">💷 Driver paid</span>
                                @elseif(($b->driverPayRemaining() ?? 0) > 0)
                                    <span class="badge" style="background:#8a5a00;color:#fff" title="Owed to the driver">💷 £{{ number_format($b->driverPayRemaining(), 0) }} owed</span>
                                @endif
                                @if(auth()->user()->isAdmin() && $b->driverWhatsAppLink())
                                    <span class="bk-edit" title="WhatsApp {{ $b->driver?->name ?? 'the driver' }}"
                                          onclick="event.preventDefault();event.stopPropagation();window.open('{{ $b->driverWhatsAppLink() }}','_blank','noopener')">💬 Driver</span>
                                @endif
                                @if(auth()->user()->isAdmin() && ! $b->status->isTerminal())
                                    <span class="bk-edit" title="Edit booking"
                                          onclick="event.preventDefault();event.stopPropagation();window.location='{{ route('bookings.edit', $b) }}'">✏️ Edit</span>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            @endforeach
        </div>

        <div style="margin-top:14px">{{ $bookings->links() }}</div>
    @endif
@endsection
