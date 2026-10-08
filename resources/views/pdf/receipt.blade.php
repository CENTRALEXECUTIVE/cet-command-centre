<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@php $fontDir = $fontDir ?? base_path('resources/fonts'); @endphp
<style>
  /* Inter (the brand font), embedded as TTF so the PDF looks the same everywhere.
     Modern, clean, lots of air — a light-weight wordmark, tight uppercase micro-
     labels, hairline dividers and a soft grey totals panel. No bright accent bars.
     Primary text stays near-black for readability. */
  @font-face { font-family:'Inter'; font-weight:300; font-style:normal; src:url('{{ $fontDir }}/Inter-Light.ttf') format('truetype'); }
  @font-face { font-family:'Inter'; font-weight:400; font-style:normal; src:url('{{ $fontDir }}/Inter-Regular.ttf') format('truetype'); }
  @font-face { font-family:'Inter'; font-weight:600; font-style:normal; src:url('{{ $fontDir }}/Inter-SemiBold.ttf') format('truetype'); }
  @font-face { font-family:'Inter'; font-weight:700; font-style:normal; src:url('{{ $fontDir }}/Inter-Bold.ttf') format('truetype'); }
  @page { margin: 48px 50px; }
  * { font-family:'Inter', Helvetica, Arial, sans-serif; }
  body { color:#161616; font-size:10px; margin:0; line-height:1.6; font-weight:400; }
  table { border-collapse:collapse; }

  .top { width:100%; }
  .top td { vertical-align:top; }
  .logo { width:66px; height:66px; }
  .company { margin-top:12px; font-size:14px; font-weight:700; color:#111; letter-spacing:-.1px; }
  .company .reg { font-size:8.5px; font-weight:400; color:#707070; margin-top:4px; letter-spacing:.2px; }

  .doc { text-align:right; }
  .doc .kind { font-size:34px; font-weight:300; letter-spacing:8px; color:#111; text-transform:uppercase; }
  .metatab { font-size:9.5px; margin-top:16px; margin-left:auto; }
  .metatab td { padding:2.5px 0; }
  .metatab .k { text-align:right; padding-right:16px; color:#8a8a8a; text-transform:uppercase; font-size:7.5px; font-weight:600; letter-spacing:.12em; }
  .metatab .v { text-align:right; font-weight:600; color:#161616; }

  .hr { border-bottom:1px solid #e6e6e6; margin:22px 0 26px; }

  .parties { width:100%; }
  .parties td { vertical-align:top; width:50%; padding-right:22px; }
  .plabel { font-size:7.5px; text-transform:uppercase; letter-spacing:.16em; color:#9a9a9a; font-weight:600; margin-bottom:7px; }
  .pname { font-weight:600; font-size:12px; color:#111; margin-bottom:2px; }
  .parties div { color:#333; }

  table.items { width:100%; margin-top:34px; font-size:10px; }
  table.items th { text-align:left; padding:0 11px 10px; font-size:7.5px; text-transform:uppercase; letter-spacing:.14em; color:#9a9a9a; border-bottom:1px solid #161616; font-weight:600; }
  table.items th.num, table.items td.num { text-align:right; white-space:nowrap; }
  table.items td { padding:14px 11px; border-bottom:1px solid #eeeeee; vertical-align:top; color:#333; }
  table.items .title { font-weight:600; font-size:11px; color:#111; margin-bottom:5px; }
  table.items .det { color:#555; font-size:9px; line-height:1.75; }
  table.items .num { color:#161616; font-weight:400; }

  .summary { width:100%; margin-top:24px; }
  .summary td { vertical-align:top; }
  table.vatsum { font-size:9px; width:100%; }
  table.vatsum th, table.vatsum td { padding:6px 0; border-bottom:1px solid #f0f0f0; text-align:right; color:#333; }
  table.vatsum th { color:#9a9a9a; font-weight:600; text-transform:uppercase; font-size:7px; letter-spacing:.1em; border-bottom:1px solid #d9d9d9; }
  table.vatsum th:first-child, table.vatsum td:first-child { text-align:left; }

  .totalbox { background:#f6f6f4; border-radius:8px; padding:16px 18px; }
  .totals { width:100%; font-size:10.5px; }
  .totals td { padding:5px 0; }
  .totals .k { text-align:left; color:#6a6a6a; }
  .totals .v { text-align:right; white-space:nowrap; font-weight:600; color:#161616; }
  .totals .due td { font-size:13px; font-weight:700; color:#111; border-top:1px solid #dcdcd7; padding-top:11px; }
  .totals .due .k { color:#111; font-weight:700; }

  .paysum { margin-top:30px; font-size:9.5px; color:#333; border-top:1px solid #eee; padding-top:14px; }
  .paysum .h { font-weight:600; color:#111; margin-bottom:3px; letter-spacing:.2px; }
  .foot { margin-top:30px; font-size:8px; color:#8a8a8a; border-top:1px solid #eee; padding-top:12px; letter-spacing:.3px; }
  .foot b { font-weight:600; color:#555; }
</style>
</head>
<body>
  @php $kind = ($isVat ?? false) ? 'VAT Invoice' : 'Invoice'; @endphp
  <table class="top">
    <tr>
      <td>
        @if($logo ?? null)<img src="{{ $logo }}" class="logo" alt="">@endif
        <div class="company">{{ $company['name'] ?: 'Central Executive Transfers Ltd' }}
          <div class="reg">
            @if($company['number'])Company No. {{ $company['number'] }}@endif
            @if($company['vat_number']) &nbsp;·&nbsp; VAT {{ $company['vat_number'] }}@endif
          </div>
        </div>
      </td>
      <td class="doc">
        <div class="kind">{{ $kind }}</div>
        <table class="metatab">
          <tr><td class="k">Invoice No.</td><td class="v">{{ $invoiceNumber }}</td></tr>
          <tr><td class="k">Issue date</td><td class="v">{{ $issueDate->format('d M Y') }}</td></tr>
          @if($balanceDue > 0)<tr><td class="k">Payment due</td><td class="v">{{ $paymentDue->format('d M Y') }}</td></tr>@endif
        </table>
      </td>
    </tr>
  </table>
  <div class="hr"></div>

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
      <td style="width:50%;padding-right:26px">
        @if($isVat ?? false)
          <table class="vatsum">
            <thead><tr><th>VAT rate</th><th>Net</th><th>VAT</th><th>Gross</th></tr></thead>
            <tbody>
              <tr><td>{{ $ratePercent }}%</td><td>£{{ number_format($netTotal, 2) }}</td><td>£{{ number_format($vatTotal, 2) }}</td><td>£{{ number_format($grossTotal, 2) }}</td></tr>
            </tbody>
          </table>
        @endif
      </td>
      <td style="width:50%">
        <div class="totalbox">
          <table class="totals">
            <tr><td class="k">Subtotal (net)</td><td class="v">£{{ number_format($netTotal, 2) }}</td></tr>
            @if($isVat ?? false)<tr><td class="k">VAT ({{ $ratePercent }}%)</td><td class="v">£{{ number_format($vatTotal, 2) }}</td></tr>@endif
            <tr><td class="k">Total{{ ($isVat ?? false) ? ' incl. VAT' : '' }}</td><td class="v">£{{ number_format($grossTotal, 2) }}</td></tr>
            @if($paymentsReceived > 0)<tr><td class="k">Paid</td><td class="v">−£{{ number_format($paymentsReceived, 2) }}</td></tr>@endif
            <tr class="due"><td class="k">{{ $balanceDue > 0 ? 'Amount due' : 'Paid in full' }}</td><td class="v">£{{ number_format(max(0, $balanceDue), 2) }}</td></tr>
          </table>
        </div>
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
