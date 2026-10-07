<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@verbatim
<style>
  @page { margin: 40px 44px; }
  * { font-family: DejaVu Sans, sans-serif; }
  body { color:#000; font-size:11px; margin:0; line-height:1.5; }
  table { border-collapse:collapse; }
  .top { width:100%; }
  .top td { vertical-align:top; }
  .logo { width:76px; height:76px; }
  .company { margin-top:8px; font-size:13px; font-weight:bold; letter-spacing:.3px; color:#000; }
  .company .reg { font-size:10px; font-weight:normal; color:#000; margin-top:2px; }
  .doc { text-align:right; }
  .doc .kind { font-size:17px; font-weight:bold; letter-spacing:3px; color:#000; }
  .metatab { font-size:10.5px; margin-top:10px; margin-left:auto; }
  .metatab td { padding:2px 0; color:#000; }
  .metatab .k { text-align:right; padding-right:12px; color:#000; }
  .metatab .v { text-align:right; font-weight:bold; color:#000; }
  .accent { height:3px; background:#FBBA2A; margin:16px 0 0; }
  .hair { height:1px; background:#bbb; margin:0 0 22px; }
  .parties { width:100%; }
  .parties td { vertical-align:top; width:50%; padding-right:18px; }
  .plabel { font-size:9px; text-transform:uppercase; letter-spacing:.14em; color:#000; font-weight:bold; margin-bottom:5px; }
  .pname { font-weight:bold; font-size:12px; color:#000; }
  .parties div { color:#000; }
  table.items { width:100%; margin-top:28px; font-size:10.5px; }
  table.items th { text-align:left; padding:0 9px 8px; font-size:9px; text-transform:uppercase; letter-spacing:.1em; color:#000; border-bottom:2px solid #000; font-weight:bold; }
  table.items th.num, table.items td.num { text-align:right; white-space:nowrap; }
  table.items td { padding:12px 9px; border-bottom:1px solid #ddd; vertical-align:top; color:#000; }
  table.items .title { font-weight:bold; font-size:11.5px; color:#000; margin-bottom:5px; }
  table.items .det { color:#000; font-size:10px; line-height:1.6; }
  .summary { width:100%; margin-top:20px; }
  .summary td { vertical-align:top; }
  table.vatsum { font-size:10px; border:1px solid #ccc; }
  table.vatsum th, table.vatsum td { padding:5px 10px; border-bottom:1px solid #eee; text-align:right; color:#000; }
  table.vatsum th { background:#f4f4f4; font-weight:bold; text-transform:uppercase; font-size:8.5px; letter-spacing:.08em; }
  table.vatsum th:first-child, table.vatsum td:first-child { text-align:left; }
  .totals { width:260px; margin-left:auto; font-size:11.5px; }
  .totals td { padding:5px 2px; color:#000; }
  .totals .k { text-align:left; }
  .totals .v { text-align:right; white-space:nowrap; font-weight:bold; }
  .totals .strong td { border-top:1px solid #999; padding-top:8px; }
  .totals .due td { font-weight:bold; font-size:15px; border-top:3px solid #FBBA2A; padding-top:10px; }
  .paysum { margin-top:30px; font-size:10.5px; color:#000; border-top:1px solid #ddd; padding-top:12px; }
  .paysum .h { font-weight:bold; margin-bottom:3px; }
  .foot { margin-top:26px; font-size:9.5px; color:#000; border-top:1px solid #ddd; padding-top:10px; }
  .foot b { font-weight:bold; }
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
        <table class="metatab">
          <tr><td class="k">Invoice number</td><td class="v">{{ $invoiceNumber }}</td></tr>
          <tr><td class="k">Issue date</td><td class="v">{{ $issueDate->format('d/m/Y') }}</td></tr>
          @if($balanceDue > 0)<tr><td class="k">Payment due</td><td class="v">{{ $paymentDue->format('d/m/Y') }}</td></tr>@endif
        </table>
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
        @if($company['vat_number'])<div>VAT Registration No: {{ $company['vat_number'] }}</div>@endif
        @if($company['address'])<div>{!! nl2br(e($company['address'])) !!}</div>@endif
        @php $contact = trim(implode(' · ', array_filter([$company['phone'] ?? '', $company['email'] ?? '']))); @endphp
        @if($contact)<div>{{ $contact }}</div>@endif
      </td>
      <td>
        <div class="plabel">Bill to</div>
        <div class="pname">{{ $billedTo }}</div>
        @if(($attn ?? null) && $attn !== $billedTo)<div>Attn: {{ $attn }}</div>@endif
        @if($customerPhone ?? null)<div>{{ $customerPhone }}</div>@endif
        @if($customerEmail)<div>{{ $customerEmail }}</div>@endif
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
            <div class="det">{!! nl2br(e($line['detail'])) !!}</div>
          </td>
          <td class="num">£{{ number_format($line['net'], 2) }}</td>
          <td class="num">£{{ number_format($line['vat'], 2) }}</td>
          <td class="num">£{{ number_format($line['total'], 2) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <table class="summary">
    <tr>
      <td style="width:52%">
        @if($isVat ?? false)
          <table class="vatsum">
            <thead><tr><th>VAT rate</th><th>Net</th><th>VAT</th><th>Gross</th></tr></thead>
            <tbody>
              <tr><td>{{ $ratePercent }}%</td><td>£{{ number_format($netTotal, 2) }}</td><td>£{{ number_format($vatTotal, 2) }}</td><td>£{{ number_format($grossTotal, 2) }}</td></tr>
            </tbody>
          </table>
        @endif
      </td>
      <td>
        <table class="totals">
          <tr><td class="k">Subtotal (net)</td><td class="v">£{{ number_format($netTotal, 2) }}</td></tr>
          @if($isVat ?? false)<tr><td class="k">VAT ({{ $ratePercent }}%)</td><td class="v">£{{ number_format($vatTotal, 2) }}</td></tr>@endif
          <tr class="strong"><td class="k">Total{{ ($isVat ?? false) ? ' incl. VAT' : '' }}</td><td class="v">£{{ number_format($grossTotal, 2) }}</td></tr>
          @if($paymentsReceived > 0)<tr><td class="k">Paid</td><td class="v">−£{{ number_format($paymentsReceived, 2) }}</td></tr>@endif
          <tr class="due"><td class="k">{{ $balanceDue > 0 ? 'Amount due' : 'Paid in full' }}</td><td class="v">£{{ number_format(max(0, $balanceDue), 2) }}</td></tr>
        </table>
      </td>
    </tr>
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
