<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@verbatim
<style>
  * { font-family: DejaVu Sans, sans-serif; }
  body { color:#141414; font-size:12px; margin:0; }
  table { border-collapse:collapse; }
  .top { width:100%; }
  .top td { vertical-align:top; }
  .brand { font-size:19px; font-weight:bold; letter-spacing:.5px; }
  .brand .gold { color:#E9A413; }
  .from { color:#555; font-size:10.5px; line-height:1.5; margin-top:6px; }
  .title { text-align:right; }
  .title h1 { font-size:22px; margin:0 0 6px; letter-spacing:1.5px; color:#0b0b0b; }
  .metatab { font-size:11px; }
  .metatab td { padding:2px 0; }
  .metatab .k { color:#666; padding-right:12px; }
  .metatab .v { text-align:right; font-weight:bold; }
  .rule { height:3px; background:#FBBA2A; margin:14px 0 0; }
  .parties { width:100%; margin-top:20px; }
  .parties td { vertical-align:top; width:50%; }
  .lbl { font-size:10px; text-transform:uppercase; letter-spacing:.08em; color:#888; margin-bottom:4px; }
  .parties .name { font-weight:bold; font-size:13px; }
  .muted { color:#666; font-size:10.5px; line-height:1.5; }
  table.items { width:100%; margin-top:22px; font-size:11px; }
  table.items th { text-align:left; background:#0b0b0b; color:#fff; padding:8px 8px; font-size:10px; text-transform:uppercase; letter-spacing:.04em; }
  table.items th.num, table.items td.num { text-align:right; }
  table.items td { padding:8px 8px; border-bottom:1px solid #eee; vertical-align:top; }
  .det { color:#666; font-size:10px; margin-top:3px; }
  .totals { width:270px; margin-left:auto; margin-top:14px; font-size:12px; }
  .totals td { padding:5px 8px; }
  .totals .k { color:#555; text-align:right; }
  .totals .v { text-align:right; width:110px; }
  .totals .grand td { border-top:2px solid #0b0b0b; font-weight:bold; font-size:14px; padding-top:8px; }
  .pill { display:inline-block; padding:3px 10px; border-radius:20px; font-size:10px; font-weight:bold; }
  .pill.paid { background:#e7f6ec; color:#1f7a44; }
  .pill.due { background:#fdf0d9; color:#9a6b06; }
  .pay { margin-top:22px; border:1px solid #e6e6e6; border-radius:6px; padding:12px 14px; font-size:11px; }
  .pay h3 { margin:0 0 6px; font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:#555; }
  .pay .bank td { padding:2px 14px 2px 0; }
  .note { margin-top:14px; font-size:11px; color:#333; }
  .foot { margin-top:26px; font-size:9.5px; color:#777; border-top:1px solid #e6e6e6; padding-top:8px; line-height:1.5; }
</style>
@endverbatim
</head>
<body>
  <table class="top">
    <tr>
      <td>
        <div class="brand">CENTRAL <span class="gold">EXECUTIVE</span> TRANSFERS</div>
        <div class="from">
          {{ $company['name'] }}@if($company['number']) · Company No. {{ $company['number'] }}@endif<br>
          @if($company['address']){!! nl2br(e($company['address'])) !!}<br>@endif
          @if($vatNumber)VAT Reg: {{ $vatNumber }}@endif
          @if($company['operator_licence']) · Operator Licence {{ $company['operator_licence'] }}@endif<br>
          @php $contact = trim(implode(' · ', array_filter([$company['phone'] ?? '', $company['email'] ?? '']))); @endphp
          {{ $contact }}
        </div>
      </td>
      <td class="title">
        <h1>{{ $isAccount ? 'VAT INVOICE' : ($paid ? 'RECEIPT' : 'BOOKING CONFIRMATION') }}</h1>
        <table class="metatab" style="margin-left:auto">
          <tr><td class="k">Reference</td><td class="v">{{ $reference }}</td></tr>
          <tr><td class="k">Issue date</td><td class="v">{{ $issueDate }}</td></tr>
          <tr><td class="k">Journey date</td><td class="v">{{ $dueDate }}</td></tr>
          @if($isAccount && $account?->account_code)<tr><td class="k">Account no.</td><td class="v">{{ $account->account_code }}</td></tr>@endif
        </table>
      </td>
    </tr>
  </table>
  <div class="rule"></div>

  <table class="parties">
    <tr>
      <td>
        <div class="lbl">Billed to</div>
        @if($isAccount && $account)
          <div class="name">{{ $account->name }}</div>
          @if($account->billing_address)<div class="muted">{!! nl2br(e($account->billing_address)) !!}</div>@endif
          @if($account->vat_number)<div class="muted">VAT Reg: {{ $account->vat_number }}</div>@endif
          <div class="muted">Booked by {{ $customerName }}</div>
        @else
          <div class="name">{{ $customerName }}</div>
          @if($customerPhone)<div class="muted">{{ $customerPhone }}</div>@endif
          @if($customerEmail)<div class="muted">{{ $customerEmail }}</div>@endif
        @endif
      </td>
      <td style="text-align:right">
        @if($isAccount)
          <span class="pill due">ON ACCOUNT — INVOICED MONTHLY</span>
        @elseif($paid)
          <span class="pill paid">PAID</span>
        @else
          <span class="pill due">AMOUNT DUE</span>
        @endif
      </td>
    </tr>
  </table>

  <table class="items">
    <thead>
      <tr><th>Description</th><th class="num" style="width:12%">Net</th><th class="num" style="width:12%">VAT</th><th class="num" style="width:14%">Gross</th></tr>
    </thead>
    <tbody>
      @foreach($lines as $line)
        <tr>
          <td>
            <strong>{{ $line['description'] }}</strong>
            @foreach($line['details'] as $k => $v)
              <div class="det">{{ $k }}: {{ $v }}</div>
            @endforeach
          </td>
          <td class="num">£{{ number_format($line['net'], 2) }}</td>
          <td class="num">£{{ number_format($line['vat'], 2) }}</td>
          <td class="num">£{{ number_format($line['gross'], 2) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <table class="totals">
    <tr><td class="k">Subtotal (net)</td><td class="v">£{{ number_format($net, 2) }}</td></tr>
    <tr><td class="k">VAT ({{ $ratePercent }}%)</td><td class="v">£{{ number_format($vatAmount, 2) }}</td></tr>
    <tr class="grand"><td class="k">{{ $isAccount ? 'Total (on account)' : 'Total' }}</td><td class="v">£{{ number_format($gross, 2) }}</td></tr>
    @unless($isAccount)
      <tr><td class="k">Paid</td><td class="v">£{{ number_format($paidAmount, 2) }}</td></tr>
      <tr><td class="k" style="font-weight:bold">Amount due</td><td class="v" style="font-weight:bold">£{{ number_format($amountDue, 2) }}</td></tr>
    @endunless
  </table>

  @if($isAccount)
    <table class="pay">
      <tr><td>
        <h3>Payment</h3>
        This journey is billed to your account (<strong>{{ $account?->account_code }}</strong>) and settled on your monthly invoice — no payment is due now.
        @if(!empty($bank))<br>Monthly invoices are paid by bank transfer to sort code <strong>{{ $bank['sort_code'] }}</strong>, account <strong>{{ $bank['account_number'] }}</strong>.@endif
      </td></tr>
    </table>
  @elseif(!$paid && !empty($bank))
    <table class="pay">
      <tr><td>
        <h3>How to pay</h3>
        Bank transfer, quoting <strong>{{ $reference }}</strong>:
        sort code <strong>{{ $bank['sort_code'] }}</strong>, account <strong>{{ $bank['account_number'] }}</strong>@if(!empty($bank['name'])) ({{ $bank['name'] }})@endif. Or pay the driver on the day.
      </td></tr>
    </table>
  @endif

  @if($footerNote)<div class="note">{!! nl2br(e($footerNote)) !!}</div>@endif

  <div class="foot">
    Thank you for choosing {{ $company['name'] }}.
    @if($company['number']) Company No. {{ $company['number'] }} ·@endif
    @if($vatNumber) VAT {{ $vatNumber }} ·@endif
    @if($company['operator_licence']) Operator Licence {{ $company['operator_licence'] }} ·@endif
    {{ $company['website'] }}
  </div>
</body>
</html>
