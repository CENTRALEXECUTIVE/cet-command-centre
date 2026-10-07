<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@verbatim
<style>
  * { font-family: DejaVu Sans, sans-serif; }
  body { color:#1a1a1a; font-size:12px; margin:0; line-height:1.45; }
  table { border-collapse:collapse; }
  .sheet { padding:4px 0; }
  .top { width:100%; }
  .top td { vertical-align:top; }
  .brand { font-size:22px; font-weight:bold; letter-spacing:.5px; line-height:1.15; }
  .doc { text-align:right; }
  .doc .kind { font-size:15px; font-weight:bold; letter-spacing:1px; }
  .doc .meta { font-size:11px; color:#444; margin-top:2px; }
  .rule { height:2px; background:#111; margin:14px 0 20px; }
  .parties { width:100%; }
  .parties td { vertical-align:top; width:50%; }
  .lbl { font-weight:bold; font-size:12px; margin-bottom:4px; }
  .muted { color:#555; }
  table.items { width:100%; margin-top:26px; font-size:11.5px; }
  table.items th { text-align:left; background:#f2eef0; color:#222; padding:9px 10px; font-size:11px; font-weight:bold; border-bottom:1px solid #e3dce0; }
  table.items th.num, table.items td.num { text-align:right; white-space:nowrap; }
  table.items td { padding:11px 10px; border-bottom:1px solid #eee; vertical-align:top; }
  table.items .title { font-weight:bold; }
  table.items .sub { color:#666; font-size:10.5px; margin-top:3px; }
  .totals { width:300px; margin-left:auto; margin-top:18px; font-size:12px; }
  .totals td { padding:5px 2px; }
  .totals .k { color:#333; text-align:left; }
  .totals .v { text-align:right; white-space:nowrap; }
  .totals .strong td { font-weight:bold; border-top:1px solid #ccc; padding-top:8px; }
  .totals .due td { font-weight:bold; font-size:15px; border-top:2px solid #111; padding-top:9px; }
  .paysum { margin-top:34px; font-size:11.5px; }
  .paysum .h { font-weight:bold; margin-bottom:3px; }
  .foot { margin-top:40px; font-size:11px; font-weight:bold; }
  .foot .reg { font-weight:normal; color:#555; font-size:10px; margin-top:4px; }
</style>
@endverbatim
</head>
<body>
<div class="sheet">
  @php
    $kind = ($isCover ?? false) ? 'INVOICE' : (($isVat ?? false) ? 'VAT INVOICE' : 'RECEIPT');
  @endphp
  <table class="top">
    <tr>
      <td><div class="brand">{{ \Illuminate\Support\Str::upper($company['name'] ?: 'Central Executive Transfers') }}</div></td>
      <td class="doc">
        <div class="kind">{{ $kind }}</div>
        <div class="meta">Invoice date: {{ now()->format('d/m/Y') }}</div>
        <div class="meta">Reference: {{ $booking->external_reference ?: $booking->reference }}</div>
      </td>
    </tr>
  </table>
  <div class="rule"></div>

  <table class="parties">
    <tr>
      <td>
        <div class="lbl">Bill from</div>
        <div>{{ $company['name'] ?: 'Central Executive Transfers Ltd' }}</div>
        @if($company['vat_number'])<div class="muted">VAT Registration No: {{ $company['vat_number'] }}</div>@endif
        @if($company['address'])<div class="muted">{!! nl2br(e($company['address'])) !!}</div>@endif
      </td>
      <td>
        <div class="lbl">Bill to</div>
        <div>{{ $billedTo }}</div>
        @if(($attn ?? null) && $attn !== $billedTo)<div class="muted">Attn: {{ $attn }}</div>@endif
        @if($customerEmail)<div class="muted">{{ $customerEmail }}</div>@endif
      </td>
    </tr>
  </table>

  <table class="items">
    <thead>
      <tr>
        <th>Description</th>
        <th class="num">Net</th>
        <th class="num">VAT</th>
        <th class="num">Total</th>
      </tr>
    </thead>
    <tbody>
      @foreach($lines as $line)
        <tr>
          <td>
            <div class="title">{{ $line['title'] }}</div>
            <div class="sub">{!! nl2br(e($line['detail'])) !!}</div>
          </td>
          <td class="num">£{{ number_format($line['net'], 2) }}</td>
          <td class="num">£{{ number_format($line['vat'], 2) }}</td>
          <td class="num">£{{ number_format($line['total'], 2) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <table class="totals">
    <tr><td class="k">Subtotal (net)</td><td class="v">£{{ number_format($netTotal, 2) }}</td></tr>
    @if($isVat ?? false)
      <tr><td class="k">VAT ({{ $ratePercent }}%)</td><td class="v">£{{ number_format($vatTotal, 2) }}</td></tr>
    @endif
    <tr class="strong"><td class="k">Total{{ ($isVat ?? false) ? ' including VAT' : '' }}</td><td class="v">£{{ number_format($grossTotal, 2) }}</td></tr>
    @if($paymentsReceived > 0)
      <tr><td class="k">Payments received</td><td class="v">£{{ number_format($paymentsReceived, 2) }}</td></tr>
    @endif
    <tr class="due"><td class="k">{{ $balanceDue > 0 ? 'BALANCE DUE' : 'PAID IN FULL' }}</td><td class="v">£{{ number_format(max(0, $balanceDue), 2) }}</td></tr>
  </table>

  @if($paymentsReceived > 0 && $balanceDue > 0)
    <div class="paysum">
      <div class="h">Payment summary</div>
      <div>Payments received to date: £{{ number_format($paymentsReceived, 2) }}.
        @if(($isVat ?? false)) Additional VAT due: £{{ number_format($balanceDue, 2) }}.@else Balance outstanding: £{{ number_format($balanceDue, 2) }}.@endif
      </div>
    </div>
  @endif

  @if($footerNote)<div class="paysum">{!! nl2br(e($footerNote)) !!}</div>@endif

  <div class="foot">
    {{ $company['name'] ?: 'Central Executive Transfers Ltd' }}
    <div class="reg">
      @if($company['number'])Company No. {{ $company['number'] }}@endif
      @if($company['vat_number']) · VAT {{ $company['vat_number'] }}@endif
      @if($company['operator_licence']) · Operator Licence {{ $company['operator_licence'] }}@endif
      @if($company['phone']) · {{ $company['phone'] }}@endif
      @if($company['email']) · {{ $company['email'] }}@endif
    </div>
  </div>
</div>
</body>
</html>
