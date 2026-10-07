<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@verbatim
<style>
  @page { margin: 42px 46px; }
  * { font-family: DejaVu Sans, sans-serif; }
  body { color:#15161a; font-size:11.5px; margin:0; line-height:1.5; }
  table { border-collapse:collapse; }
  .top { width:100%; }
  .top td { vertical-align:top; }
  .logo { width:84px; height:84px; }
  .company { margin-top:10px; font-size:14px; font-weight:bold; letter-spacing:.3px; color:#0b0b0b; }
  .company .reg { font-size:9.5px; font-weight:normal; color:#777; letter-spacing:.2px; margin-top:2px; }
  .doc { text-align:right; }
  .doc .kind { font-size:16px; font-weight:bold; letter-spacing:3px; color:#0b0b0b; }
  .doc .meta { font-size:10.5px; color:#555; margin-top:8px; }
  .doc .meta b { color:#15161a; }
  .accent { height:2.5px; background:#FBBA2A; margin:18px 0 0; }
  .hair { height:1px; background:#e7e7e7; margin:0 0 22px; }
  .parties { width:100%; margin-top:4px; }
  .parties td { vertical-align:top; width:50%; padding-right:18px; }
  .plabel { font-size:9px; text-transform:uppercase; letter-spacing:.14em; color:#9a9a9a; margin-bottom:5px; }
  .pname { font-weight:bold; font-size:12.5px; color:#0b0b0b; }
  .muted { color:#555; }
  table.items { width:100%; margin-top:30px; font-size:11px; }
  table.items th { text-align:left; padding:0 10px 8px; font-size:9px; text-transform:uppercase; letter-spacing:.12em; color:#9a9a9a; border-bottom:1.5px solid #15161a; font-weight:bold; }
  table.items th.num, table.items td.num { text-align:right; white-space:nowrap; }
  table.items td { padding:13px 10px; border-bottom:1px solid #efefef; vertical-align:top; }
  table.items .title { font-weight:bold; font-size:11.5px; color:#15161a; }
  table.items .sub { color:#777; font-size:10px; margin-top:4px; line-height:1.5; }
  .totals { width:290px; margin-left:auto; margin-top:22px; font-size:11.5px; }
  .totals td { padding:6px 2px; }
  .totals .k { color:#555; text-align:left; }
  .totals .v { text-align:right; white-space:nowrap; color:#15161a; }
  .totals .strong td { font-weight:bold; border-top:1px solid #ddd; padding-top:9px; color:#0b0b0b; }
  .totals .due td { font-weight:bold; font-size:15px; border-top:2.5px solid #FBBA2A; padding-top:11px; color:#0b0b0b; }
  .paysum { margin-top:40px; font-size:10.5px; color:#555; border-top:1px solid #efefef; padding-top:14px; }
  .paysum .h { font-weight:bold; color:#15161a; margin-bottom:4px; letter-spacing:.02em; }
  .foot { margin-top:30px; font-size:10px; color:#8a8a8a; letter-spacing:.2px; }
  .foot b { color:#555; }
</style>
@endverbatim
</head>
<body>
  @php $kind = ($isCover ?? false) ? 'INVOICE' : (($isVat ?? false) ? 'VAT INVOICE' : 'RECEIPT'); @endphp
  <table class="top">
    <tr>
      <td>
        @if($logo ?? null)<img src="{{ $logo }}" class="logo" alt="">@endif
        <div class="company">{{ $company['name'] ?: 'Central Executive Transfers Ltd' }}
          <div class="reg">
            @if($company['number'])Company No. {{ $company['number'] }}@endif
            @if($company['vat_number']) · VAT {{ $company['vat_number'] }}@endif
          </div>
        </div>
      </td>
      <td class="doc">
        <div class="kind">{{ $kind }}</div>
        <div class="meta">
          <div>Invoice date: <b>{{ now()->format('d/m/Y') }}</b></div>
          <div>Reference: <b>{{ $booking->external_reference ?: $booking->reference }}</b></div>
        </div>
      </td>
    </tr>
  </table>
  <div class="accent"></div>
  <div class="hair"></div>

  <table class="parties">
    <tr>
      <td>
        <div class="plabel">Bill from</div>
        <div class="pname">{{ $company['name'] ?: 'Central Executive Transfers Ltd' }}</div>
        @if($company['vat_number'])<div class="muted">VAT Registration No: {{ $company['vat_number'] }}</div>@endif
        @if($company['address'])<div class="muted">{!! nl2br(e($company['address'])) !!}</div>@endif
        @php $contact = trim(implode(' · ', array_filter([$company['phone'] ?? '', $company['email'] ?? '']))); @endphp
        @if($contact)<div class="muted">{{ $contact }}</div>@endif
      </td>
      <td>
        <div class="plabel">Bill to</div>
        <div class="pname">{{ $billedTo }}</div>
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
      <tr><td class="k">Payments received</td><td class="v">−£{{ number_format($paymentsReceived, 2) }}</td></tr>
    @endif
    <tr class="due"><td class="k">{{ $balanceDue > 0 ? 'Balance due' : 'Paid in full' }}</td><td class="v">£{{ number_format(max(0, $balanceDue), 2) }}</td></tr>
  </table>

  @if($paymentsReceived > 0 && $balanceDue > 0)
    <div class="paysum">
      <div class="h">Payment summary</div>
      Payments received to date: £{{ number_format($paymentsReceived, 2) }}.
      @if(($isVat ?? false)) Additional VAT now due: £{{ number_format($balanceDue, 2) }}.@else Balance outstanding: £{{ number_format($balanceDue, 2) }}.@endif
    </div>
  @endif

  @if($footerNote)<div class="paysum">{!! nl2br(e($footerNote)) !!}</div>@endif

  <div class="foot">
    <b>{{ $company['name'] ?: 'Central Executive Transfers Ltd' }}</b>
    @if($company['operator_licence']) · Operator Licence {{ $company['operator_licence'] }}@endif
    @if($company['website']) · {{ $company['website'] }}@endif
    @if(config('cet.ico_registration_number')) · ICO {{ config('cet.ico_registration_number') }}@endif
  </div>
</body>
</html>
