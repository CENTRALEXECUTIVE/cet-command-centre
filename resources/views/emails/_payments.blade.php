@php
    // Clear money breakdown for emails: total, what's paid, what's left, and a line
    // per recorded payment (deposit / cash to driver / card) so the story is obvious.
    $paidTx = $booking->payments->where('status', 'paid')->sortBy('paid_at')->values();
    $totalFare = $booking->amountPayable() ?? $booking->fareGross() ?? 0;
    $paidSoFar = round($booking->transactionsPaidTotal(), 2);
    $remaining = $booking->transactionsAmountDue();
    $txLabel = function ($p) {
        $name = trim((string) ($p->meta['name'] ?? ''));
        $method = ucfirst((string) $p->method);
        return $name !== '' ? $name.' ('.$method.')' : $method;
    };
@endphp
@if($totalFare > 0)
    <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px;width:100%;max-width:360px">
        <tr><td style="padding:4px 14px 4px 0;color:#666">Total fare:</td><td style="padding:4px 0;color:#111;text-align:right">£{{ number_format($totalFare, 2) }}</td></tr>
        <tr><td style="padding:4px 14px 4px 0;color:#666">Paid:</td><td style="padding:4px 0;color:#1f7a44;font-weight:700;text-align:right">£{{ number_format($paidSoFar, 2) }}</td></tr>
        @if($remaining !== null)
            <tr><td style="padding:4px 14px 4px 0;color:#666">{{ $remaining > 0.001 ? 'Remaining:' : 'Balance:' }}</td>
                <td style="padding:4px 0;font-weight:700;text-align:right;color:{{ $remaining > 0.001 ? '#b9770a' : '#1f7a44' }}">{{ $remaining > 0.001 ? '£'.number_format($remaining, 2) : 'Paid in full' }}</td></tr>
        @endif
    </table>
    @if($paidTx->isNotEmpty())
        <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:13px;width:100%;max-width:360px;margin-top:6px;border-top:1px solid #eee">
            @foreach($paidTx as $p)
                <tr><td style="padding:4px 14px 4px 0;color:#555">• {{ $txLabel($p) }}</td><td style="padding:4px 0;color:#111;text-align:right">£{{ number_format((float) $p->amount, 2) }}</td></tr>
            @endforeach
        </table>
    @endif
@endif
