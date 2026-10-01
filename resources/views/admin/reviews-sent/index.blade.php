@extends('layouts.app')
@section('title', 'Reviews sent')

@section('content')
    <div class="list-head" style="align-items:center">
        <div class="form-hero" style="flex:1;margin-bottom:0">
            <div class="form-hero-glow"></div>
            <div class="fh-eyebrow">Fleet &amp; admin · reports</div>
            <div class="fh-title">Reviews sent</div>
            <div class="fh-sub">Every customer who's been asked for a Google review — fetched, checked and reviewed in one place.</div>
        </div>
    </div>

    {{-- Headline counts --}}
    <div class="grid grid-3" style="gap:12px;margin-bottom:14px">
        <div class="card" style="margin:0;text-align:center">
            <div style="font-size:26px;font-weight:800">{{ number_format($counts['sent']) }}</div>
            <div class="muted" style="font-size:13px">Sent</div>
        </div>
        <div class="card" style="margin:0;text-align:center">
            <div style="font-size:26px;font-weight:800">{{ number_format($counts['pending']) }}</div>
            <div class="muted" style="font-size:13px">Queued / not yet sent</div>
        </div>
        <div class="card" style="margin:0;text-align:center">
            <div style="font-size:26px;font-weight:800">{{ number_format($counts['total']) }}</div>
            <div class="muted" style="font-size:13px">Total asked</div>
        </div>
    </div>

    {{-- Filter --}}
    @php
        $tab = fn ($key, $label) => '<a href="'.route('reviews-sent.index', ['show' => $key]).'" class="btn '.($filter === $key ? 'btn-primary' : 'btn-light').'" style="padding:7px 14px;font-size:13px">'.$label.'</a>';
    @endphp
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
        {!! $tab('sent', 'Sent') !!}
        {!! $tab('pending', 'Queued') !!}
        {!! $tab('all', 'All') !!}
    </div>

    <div class="card">
        <div class="table-scroll">
            <table class="table-modern table-cards">
                <thead><tr><th>Customer</th><th>Sent to</th><th>Booking</th><th>Channel</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse($messages as $m)
                        @php
                            $booking = $m->booking;
                            $name = $booking?->displayName() ?: ($m->customer?->name ?: '—');
                            $when = $m->sent_at ?? $m->scheduled_for ?? $m->created_at;
                        @endphp
                        <tr>
                            <td data-label="Customer">{{ $name }}</td>
                            <td data-label="Sent to" class="muted mono" style="font-size:13px">{{ $m->to_address ?: '—' }}</td>
                            <td data-label="Booking">
                                @if($booking)
                                    <a href="{{ route('bookings.show', $booking) }}" class="mono">{{ $booking->reference }}</a>
                                    @if($booking->pickup_at)<span class="muted" style="font-size:12px"> · {{ $booking->pickup_at->format('d M Y') }}</span>@endif
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td data-label="Channel" style="text-transform:capitalize">{{ $m->channel ?: 'whatsapp' }}</td>
                            <td data-label="Status">
                                @if($m->status === 'sent')
                                    <span class="badge" style="background:#1f8b4c;color:#fff">Sent</span>
                                    @if($m->sent_at)<span class="muted" style="font-size:12px"> {{ $m->sent_at->format('d M Y, H:i') }}</span>@endif
                                @elseif($m->status === 'failed')
                                    <span class="badge" style="background:#b32020;color:#fff">Failed</span>
                                @else
                                    <span class="badge">Queued</span>
                                    @if($m->scheduled_for)<span class="muted" style="font-size:12px"> for {{ $m->scheduled_for->format('d M, H:i') }}</span>@endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted" style="text-align:center;padding:22px">
                            @if($filter === 'sent')No review requests have been sent yet.
                            @elseif($filter === 'pending')Nothing queued — every review request has been sent.
                            @else No review requests recorded yet.@endif
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($messages->hasPages())
            <div style="margin-top:12px">{{ $messages->links() }}</div>
        @endif
    </div>

    <p class="hint" style="margin-top:12px">
        A review is <strong>Sent</strong> once the office tapped its WhatsApp/email link; <strong>Queued</strong> means it's prepared and waiting to be sent. One review ask per customer, ever (a manual “Request a review” on a booking can override that).
    </p>
@endsection
