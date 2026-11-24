{{-- Website bookings awaiting payment (card / quote). Directors, 24 Nov 2026: never
     forget or lose one. Stays here until paid or cancelled by hand; nothing is
     automatic and nothing is sent to the customer without a tap. --}}
@if($rows->isNotEmpty())
    <div class="card" style="margin:16px 0;border:1px solid rgb(185 119 10 / .45);background:rgb(251 186 42 / .07)">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;flex-wrap:wrap">
            <h2 style="margin:0">💳 Awaiting payment ({{ $rows->count() }})</h2>
            <span class="muted" style="font-size:13px">Not on the jobs list until paid. Reminders keep coming until you deal with them.</span>
        </div>
        <div style="margin-top:10px;display:grid;gap:10px">
            @foreach($rows as $b)
                @php
                    $tz = config('app.timezone');
                    $ref = $b->external_reference ?: $b->reference;
                    $phone = $b->customerContactNumber() ?: $b->customer?->phone;
                    $wa = \App\Support\Phone::wa($phone);
                    $first = \Illuminate\Support\Str::before(trim((string) $b->displayName()), ' ') ?: 'there';
                    $price = $b->fareGross();
                    $quote = ! empty($b->meta['quote_request']);
                    $payUrl = \Illuminate\Support\Facades\URL::signedRoute('widget.pay', ['booking' => $b->id]);
                    $msg = "Hi {$first}, thank you for booking with Central Executive Transfers ({$ref}, "
                        .$b->pickup_at->timezone($tz)->format('D j M, H:i').'). '
                        .($price && ! $quote ? 'To confirm your booking, please pay securely here: '.$payUrl : 'We’ll confirm your price shortly.');
                    $soon = $b->pickup_at->lte(now()->addHours(24));
                @endphp
                <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;padding:10px 12px;border-radius:10px;background:var(--panel, #fff);border:1px solid rgb(0 0 0 / .08)">
                    <div style="min-width:0">
                        <strong>{{ $b->pickup_at->timezone($tz)->format('D d M · H:i') }}</strong>
                        @if($soon)<span class="badge badge-danger" style="margin-left:6px">soon</span>@endif
                        <div>{{ $b->displayName() }} · <span class="muted">{{ $ref }}</span></div>
                        <div class="muted" style="font-size:13px">{{ $quote ? 'Quote request' : ($price ? '£'.number_format($price, 2).' card, unpaid' : 'Card, unpaid') }} · booked {{ $b->created_at->diffForHumans() }}</div>
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        @if($phone)<a class="btn btn-light" href="tel:{{ \App\Support\Phone::e164($phone) ?? $phone }}">📞 Call</a>@endif
                        @if($wa)<a class="btn btn-light" target="_blank" rel="noopener" href="https://wa.me/{{ $wa }}?text={{ rawurlencode($msg) }}">📲 {{ $quote ? 'WhatsApp' : 'WhatsApp pay link' }}</a>@endif
                        @if(! $quote)
                            <form method="POST" action="{{ route('payments.paid', $b) }}" onsubmit="return confirm('Mark {{ $ref }} as PAID? Only do this if the money has arrived.')">@csrf<button class="btn btn-light">✓ Mark paid</button></form>
                        @endif
                        <a class="btn" href="{{ route('bookings.show', $b) }}">Open</a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
