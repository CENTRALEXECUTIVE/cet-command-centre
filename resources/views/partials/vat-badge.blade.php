{{-- Which ACCOUNT this job's money landed in (Ltd/Tide vs PVT LTD/Stripe, or cash)
     — so the office pays the driver from the right one. See Booking::moneyAccount(). --}}
@php $acct = $booking->moneyAccount(); @endphp
<div style="margin-top:3px">
    <span class="badge" style="font-size:10px;padding:2px 7px;border-radius:999px;{{ match ($acct['key']) { 'pvt' => 'background:#1f3a8a;color:#fff', 'cash' => 'background:#e9e9ec;color:#333', default => 'background:#FBBA2A;color:#111' } }}"
          title="Money in: {{ $acct['label'] }} — {{ $acct['why'] }}">
        {{ $acct['key'] === 'cash' ? 'Cash' : 'Paid into '.$acct['label'] }}
    </span>
    <span class="muted" style="font-size:10.5px;display:block">{{ $acct['why'] }}</span>
</div>
