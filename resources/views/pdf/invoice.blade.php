<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@verbatim
<style>
  * { font-family: DejaVu Sans, sans-serif; }
  body { color:#141414; font-size:12px; margin:0; }
  table { border-collapse:collapse; }
  .sheet { padding:2px 0; }
  .top { width:100%; }
  .top td { vertical-align:top; }
  .brand { font-size:19px; font-weight:bold; letter-spacing:.5px; }
  .brand .gold { color:#E9A413; }
  .from { color:#555; font-size:10.5px; line-height:1.5; margin-top:6px; }
  .title { text-align:right; }
  .title h1 { font-size:24px; margin:0 0 6px; letter-spacing:2px; color:#0b0b0b; }
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
  table.items tr:nth-child(even) td { background:#faf9f6; }
  .totals { width:270px; margin-left:auto; margin-top:14px; font-size:12px; }
  .totals td { padding:5px 8px; }
  .totals .k { color:#555; text-align:right; }
  .totals .v { text-align:right; width:110px; }
  .totals .grand td { border-top:2px solid #0b0b0b; font-weight:bold; font-size:14px; padding-top:8px; }
  .pay { margin-top:24px; border:1px solid #e6e6e6; border-radius:6px; padding:12px 14px; font-size:11px; }
  .pay h3 { margin:0 0 6px; font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:#555; }
  .pay .bank td { padding:2px 14px 2px 0; }
  .note { margin-top:14px; font-size:11px; color:#333; }
  .foot { margin-top:26px; font-size:9.5px; color:#777; border-top:1px solid #e6e6e6; padding-top:8px; line-height:1.5; }
</style>
@endverbatim
</head>
<body>
<div class="sheet">
  <table class="top">
    <tr>
      <td>
        <div class="brand">CENTRAL <span class="gold">EXECUTIVE</span> TRANSFERS</div>
        <div class="from">
          {{ $company['name'] }}@if($company['number']) · Company No. {{ $company['number'] }}@endif<br>
          @if($company['address']){!! nl2br(e($company['address'])) !!}<br>@endif
          @if($company['vat_number'])VAT Reg: {{ $company['vat_number'] }}@endif
          @if($company['operator_licence']) · Operator Licence {{ $company['operator_licence'] }}@endif<br>
          @php $contact = trim(implode(' · ', array_filter([$company['phone'] ?? '', $company['email'] ?? '']))); @endphp
          {{ $contact }}
        </div>
      </td>
      <td class="title">
        <h1>INVOICE</h1>
        <table class="metatab" style="margin-left:auto">
          <tr><td class="k">Invoice number</td><td class="v">{{ $invoice->invoice_number }}</td></tr>
          <tr><td class="k">Issue date</td><td class="v">{{ $invoice->issued_at?->format('d M Y') }}</td></tr>
          <tr><td class="k">Payment due</td><td class="v">{{ $invoice->due_at?->format('d M Y') }}</td></tr>
          @if($invoice->corporateAccount?->account_code)<tr><td class="k">Account no.</td><td class="v">{{ $invoice->corporateAccount->account_code }}</td></tr>@endif
        </table>
      </td>
    </tr>
  </table>
  <div class="rule"></div>

  <table class="parties">
    <tr>
      <td>
        <div class="lbl">Billed to</div>
        <div class="name">{{ $invoice->corporateAccount?->name }}</div>
        @if($invoice->corporateAccount?->billing_address)<div class="muted">{!! nl2br(e($invoice->corporateAccount->billing_address)) !!}</div>@endif
        @if($invoice->corporateAccount?->vat_number)<div class="muted">VAT Reg: {{ $invoice->corporateAccount->vat_number }}</div>@endif
      </td>
      <td>
        <div class="lbl">Billing period</div>
        <div class="muted">{{ $invoice->period_start?->format('d M Y') }} – {{ $invoice->period_end?->format('d M Y') }}</div>
        <div class="lbl" style="margin-top:10px">Terms</div>
        <div class="muted">{{ $invoice->corporateAccount?->payment_terms_days ?? 30 }} days from invoice date</div>
      </td>
    </tr>
  </table>

  <table class="items">
    <thead>
      <tr><th style="width:16%">Date</th><th>Journey</th><th style="width:14%">Cost code</th><th class="num" style="width:14%">Amount (net)</th></tr>
    </thead>
    <tbody>
      @foreach($invoice->items as $item)
        <tr>
          <td>{{ optional($item->booking)->pickup_at?->format('d/m/Y H:i') ?? '—' }}</td>
          <td>{{ $item->description }}</td>
          <td>{{ $item->cost_code ?: '—' }}</td>
          <td class="num">£{{ number_format($item->amount, 2) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <table class="totals">
    <tr><td class="k">Subtotal (net)</td><td class="v">£{{ number_format($invoice->subtotal, 2) }}</td></tr>
    <tr><td class="k">VAT ({{ (int) round((float) config('cet.vat_rate') * 100) }}%)</td><td class="v">£{{ number_format($invoice->vat_amount, 2) }}</td></tr>
    <tr class="grand"><td class="k">Total due</td><td class="v">£{{ number_format($invoice->total, 2) }}</td></tr>
  </table>

  <table class="pay">
    <tr><td>
      <h3>How to pay</h3>
      @if(!empty($bank))
        Please pay by bank transfer, quoting <strong>{{ $invoice->invoice_number }}</strong> as the reference:
        <table class="bank" style="margin-top:6px">
          @if(!empty($bank['name']))<tr><td>Account name</td><td><strong>{{ $bank['name'] }}</strong></td></tr>@endif
          <tr><td>Sort code</td><td><strong>{{ $bank['sort_code'] }}</strong></td></tr>
          <tr><td>Account number</td><td><strong>{{ $bank['account_number'] }}</strong></td></tr>
        </table>
      @else
        Please pay by bank transfer, quoting <strong>{{ $invoice->invoice_number }}</strong> as the reference. Bank details are provided separately by our office.
      @endif
    </td></tr>
  </table>

  @if($footerNote)<div class="note">{!! nl2br(e($footerNote)) !!}</div>@endif

  <div class="foot">
    {{ $company['name'] }}@if($company['number']) · Company No. {{ $company['number'] }}@endif
    @if($company['vat_number']) · VAT {{ $company['vat_number'] }}@endif
    @if($company['operator_licence']) · Operator Licence {{ $company['operator_licence'] }}@endif
    @if(config('cet.ico_registration_number')) · ICO {{ config('cet.ico_registration_number') }}@endif
    · {{ $company['website'] }}
  </div>
</div>
</body>
</html>
