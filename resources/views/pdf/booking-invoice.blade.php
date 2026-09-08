<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { color:#111; font-size:12px; margin:0; }
    table { border-collapse:collapse; }
    .head { width:100%; }
    .head td { vertical-align:top; }
    .logo { width:120px; height:110px; background:#0b0b0b; color:#fff; text-align:center;
        font-weight:700; letter-spacing:2px; font-size:15px; line-height:110px; }
    .meta { width:100%; font-size:12px; }
    .meta td { padding:2px 0; }
    .meta .k { color:#555; }
    .meta .v { text-align:right; }
    h3 { font-size:12px; margin:0 0 4px; }
    .parties { width:100%; margin-top:24px; }
    .parties td { vertical-align:top; width:50%; color:#333; }
    .muted { color:#666; }
    table.items { width:100%; margin-top:22px; font-size:11px; }
    table.items th { text-align:left; border-bottom:1px solid #ccc; padding:8px 6px; color:#333; }
    table.items td { padding:8px 6px; border-bottom:1px solid #eee; vertical-align:top; }
    .num { text-align:right; }
    .det { color:#555; font-size:10.5px; margin-top:3px; }
    table.vat { width:100%; font-size:11px; }
    table.vat th, table.vat td { padding:5px 8px; border-bottom:1px solid #eee; text-align:left; }
    table.totals { width:100%; font-size:12px; }
    table.totals .k { color:#555; text-align:right; padding:4px 12px 4px 0; }
    table.totals .v { text-align:right; padding:4px 0; }
    table.totals .due { font-weight:700; font-size:13px; }
    .pay { margin-top:26px; font-size:11px; color:#333; }
</style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width:130px"><div class="logo">C=NTRAL</div></td>
            <td>
                <table class="meta">
                    <tr><td class="k">Invoice number:</td><td class="v">{{ $reference }}</td></tr>
                    <tr><td class="k">Issue date:</td><td class="v">{{ $issueDate }}</td></tr>
                    <tr><td class="k">Invoice date:</td><td class="v">{{ $issueDate }}</td></tr>
                    <tr><td class="k">Payment due:</td><td class="v">{{ $dueDate }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                <h3>Bill from:</h3>
                {{ $company['name'] ?? 'Central Executive Transfers' }}<br>
                @if($vatNumber)<span class="muted">VAT No: {{ $vatNumber }}</span><br>@endif
                <span class="muted">Operator Licence {{ $company['operator_licence'] ?? '' }}</span>
            </td>
            <td>
                <h3>Bill to:</h3>
                {{ $customerName }}<br>
                @if($customerPhone)<span class="muted">{{ $customerPhone }}</span><br>@endif
                @if($customerEmail)<span class="muted">{{ $customerEmail }}</span>@endif
            </td>
        </tr>
    </table>

    <table class="items">
        <tr>
            <th style="width:34%">Description</th>
            <th class="num">Qty</th>
            <th class="num">Price (net)</th>
            <th class="num">VAT rate</th>
            <th class="num">Total (net)</th>
            <th class="num">VAT</th>
            <th class="num">Total (gross)</th>
        </tr>
        @foreach($lines as $line)
            <tr>
                <td>
                    <strong>{{ $line['description'] }}</strong>
                    @foreach($line['details'] as $k => $v)
                        <div class="det">{{ $k }}: {{ $v }}</div>
                    @endforeach
                </td>
                <td class="num">{{ $line['qty'] }}</td>
                <td class="num">£{{ number_format($line['net'], 2) }}</td>
                <td class="num">{{ (int) round($line['rate'] * 100) }}%</td>
                <td class="num">£{{ number_format($line['net'], 2) }}</td>
                <td class="num">£{{ number_format($line['vat'], 2) }}</td>
                <td class="num">£{{ number_format($line['gross'], 2) }}</td>
            </tr>
        @endforeach
    </table>

    <table style="width:100%;margin-top:20px">
        <tr>
            <td style="width:55%;vertical-align:top">
                <table class="vat">
                    <tr><th>VAT rate</th><th>Total (net)</th><th>VAT</th><th>Total (gross)</th></tr>
                    <tr><td>{{ $ratePercent }}%</td><td>£{{ number_format($net, 2) }}</td><td>£{{ number_format($vatAmount, 2) }}</td><td>£{{ number_format($gross, 2) }}</td></tr>
                </table>
            </td>
            <td style="width:45%;vertical-align:top">
                <table class="totals">
                    <tr><td class="k">Total:</td><td class="v">£{{ number_format($gross, 2) }}</td></tr>
                    <tr><td class="k">Paid:</td><td class="v">£{{ number_format($paidAmount, 2) }}</td></tr>
                    <tr><td class="k due">Amount due:</td><td class="v due">£{{ number_format($amountDue, 2) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="pay">
        <strong>Payments:</strong><br>
        Full amount (Square) — {{ $paid ? 'Paid' : 'Pending' }}: £{{ number_format($gross, 2) }}
    </div>
</body>
</html>
