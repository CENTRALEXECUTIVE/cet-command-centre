<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@verbatim
<style>
  /* Professional invoice. Uses the PDF core fonts dompdf always has — Helvetica
     for the body (clean, corporate) and Times for the masthead/headings (a
     letterhead gravitas) — so nothing depends on an embedded font that could
     silently fall back. All text is near-black for readability; no bright accent
     bars — restraint reads as premium. */
  @page { margin: 46px 48px; }
  * { font-family: Helvetica, Arial, sans-serif; }
  body { color:#111; font-size:10.5px; margin:0; line-height:1.55; }
  table { border-collapse:collapse; }
  .serif { font-family: "Times New Roman", Times, serif; }

  .top { width:100%; }
  .top td { vertical-align:top; }
  .logo { width:70px; height:70px; }
  .company { margin-top:10px; font-size:15px; font-weight:bold; color:#111; letter-spacing:.2px; }
  .company .reg { font-family:Helvetica, Arial, sans-serif; font-size:9px; font-weight:normal; color:#444; margin-top:3px; letter-spacing:.2px; }

  .doc { text-align:right; }
  .doc .kind { font-size:30px; font-weight:normal; letter-spacing:7px; color:#111; }
  .metatab { font-size:10px; margin-top:14px; margin-left:auto; }
  .metatab td { padding:2px 0; color:#111; }
  .metatab .k { text-align:right; padding-right:14px; color:#555; text-transform:uppercase; font-size:8.5px; letter-spacing:.1em; }
  .metatab .v { text-align:right; font-weight:bold; }

  .rule { border-bottom:2px solid #111; margin:18px 0 2px; }
  .rule2 { border-bottom:1px solid #111; margin:0 0 24px; }

  .parties { width:100%; }
  .parties td { vertical-align:top; width:50%; padding-right:20px; }
  .plabel { font-size:8.5px; text-transform:uppercase; letter-spacing:.16em; color:#555; font-weight:bold; margin-bottom:6px; }
  .pname { font-weight:bold; font-size:12.5px; color:#111; margin-bottom:1px; }
  .parties div { color:#111; }

  table.items { width:100%; margin-top:30px; font-size:10.5px; }
  table.items th { text-align:left; padding:0 10px 9px; font-size:8.5px; text-transform:uppercase; letter-spacing:.12em; color:#555; border-bottom:1.5px solid #111; font-weight:bold; }
  table.items th.num, table.items td.num { text-align:right; white-space:nowrap; }
  table.items td { padding:13px 10px; border-bottom:1px solid #e3e3e3; vertical-align:top; color:#111; }
  table.items .title { font-weight:bold; font-size:11.5px; color:#111; margin-bottom:5px; }
  table.items .det { color:#333; font-size:9.5px; line-height:1.7; }

  .summary { width:100%; margin-top:22px; }
  .summary td { vertical-align:top; }
  table.vatsum { font-size:9.5px; border:1px solid #d7d7d7; }
  table.vatsum th, table.vatsum td { padding:6px 11px; border-bottom:1px solid #ececec; text-align:right; color:#111; }
  table.vatsum th { background:#f6f6f4; font-weight:bold; text-transform:uppercase; font-size:8px; letter-spacing:.09em; color:#555; }
  table.vatsum th:first-child, table.vatsum td:first-child { text-align:left; }

  .totals { width:270px; margin-left:auto; font-size:11px; }
  .totals td { padding:6px 2px; color:#111; }
  .totals .k { text-align:left; color:#444; }
  .totals .v { text-align:right; white-space:nowrap; font-weight:bold; }
  .totals .strong td { border-top:1px solid #cfcfcf; padding-top:9px; }
  .totals .due td { font-weight:bold; font-size:14px; border-top:2px solid #111; padding-top:11px; }

  .paysum { margin-top:30px; font-size:10px; color:#111; border-top:1px solid #e3e3e3; padding-top:13px; }
  .paysum .h { font-weight:bold; margin-bottom:3px; letter-spacing:.3px; }
  .foot { margin-top:30px; font-size:9px; color:#555; border-top:1px solid #e3e3e3; padding-top:11px; letter-spacing:.2px; }
  .foot b { font-weight:bold; color:#111; }
</style>
@endverbatim
</head>
<body>
  @php $kind = ($isVat ?? false) ? 'VAT INVOICE' : 'INVOICE'; @endphp
  <table class="top">
    <tr>
      <td>
        @if($logo ?? null)<img src="{{ $logo }}" class="logo" alt="">@endif
        <div class="company serif">{{ $company['name'] ?: 'Central Executive Transfers Ltd' }}
          <div class="reg">
            @if($company['number'])Company No. {{ $company['number'] }}@endif
            @if($company['vat_number']) &nbsp;·&nbsp; VAT {{ $company['vat_number'] }}@endif
          </div>
        </div>
      </td>
      <td class="doc">
        <div class="kind serif">{{ $kind }}</div>
        <table class="metatab">
          <tr><td class="k">Invoice No.</td><td class="v">{{ $invoiceNumber }}</td></tr>
          <tr><td class="k">Issue date</td><td class="v">{{ $issueDate->format('d/m/Y') }}</td></tr>
          @if($balanceDue > 0)<tr><td class="k">Payment due</td><td class="v">{{ $paymentDue->format('d/m/Y') }}</td></tr>@endif
        </table>
      </td>
    </tr>
  </table>
  <div class="rule"></div>
  <div class="rule2"></div>

  <table class="parties">
    <tr>
      <td>
        <div class="plabel">From</div>
        <div class="pname">{{ $company['name'] ?: 'Central Executive Transfers Ltd' }}</div>
        @if($company['vat_number'])<div>VAT Reg. No. {{ $company['vat_number'] }}</div>@endif
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
