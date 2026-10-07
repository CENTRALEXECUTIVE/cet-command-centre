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
  .totals { width:270px; margin-left:auto; margin-top:14px; font-size:12px; }
  .totals td { padding:5px 8px; }
  .totals .k { color:#555; text-align:right; }
  .totals .v { text-align:right; width:110px; }
  .totals .grand td { border-top:2px solid #0b0b0b; font-weight:bold; font-size:14px; padding-top:8px; }
  .stamp { margin-top:18px; display:inline-block; border:2px solid #1f7a44; color:#1f7a44; font-weight:bold; letter-spacing:.1em; padding:6px 14px; border-radius:6px; font-size:13px; text-transform:uppercase; }
  .stamp.due { border-color:#b32020; color:#b32020; }
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
        <h1>{{ ($isCover ?? false) ? 'INVOICE' : ($isVat ? 'VAT INVOICE' : 'RECEIPT') }}</h1>
        <table class="metatab" style="margin-left:auto">
          <tr><td class="k">Reference</td><td class="v">{{ $booking->reference }}</td></tr>
          <tr><td class="k">Date issued</td><td class="v">{{ now()->format('d M Y') }}</td></tr>
          <tr><td class="k">Journey date</td><td class="v">{{ $booking->pickup_at?->format('d M Y') ?? '—' }}</td></tr>
        </table>
      </td>
    </tr>
  </table>
  <div class="rule"></div>

  <table class="parties">
    <tr>
      <td>
        <div class="lbl">Billed to</div>
        <div class="name">{{ $billedTo ?? $booking->displayName() }}</div>
        @if($customerEmail)<div class="muted">{{ $customerEmail }}</div>@endif
        @if($isCover ?? false)<div class="muted">Cover journey carried out by Central Executive Transfers for {{ $booking->displayName() }}.</div>@endif
      </td>
      <td>
        <div class="lbl">Vehicle</div>
        <div class="muted">{{ $booking->vehicleType?->name ?? '—' }}</div>
        <div class="lbl" style="margin-top:10px">Payment method</div>
        <div class="muted">{{ ucfirst($booking->payment_method?->value ?? 'n/a') }}</div>
      </td>
    </tr>
  </table>

  <table class="items">
    <thead>
      <tr><th style="width:18%">Date</th><th>Journey</th><th class="num" style="width:16%">Amount</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>{{ $booking->pickup_at?->format('d/m/Y H:i') ?? '—' }}</td>
        <td>
          <strong>{{ $booking->pickup_address }}</strong><br>
          <span class="muted">to {{ $booking->destination_address }}</span>
        </td>
        <td class="num">£{{ number_format((float) ($breakdown['gross'] ?? $gross), 2) }}</td>
      </tr>
    </tbody>
  </table>

  <table class="totals">
    @if($isVat && $breakdown)
      <tr><td class="k">Net</td><td class="v">£{{ number_format($breakdown['net'], 2) }}</td></tr>
      <tr><td class="k">VAT ({{ (int) round(($breakdown['rate'] ?? 0) * 100) }}%)</td><td class="v">£{{ number_format($breakdown['vat'], 2) }}</td></tr>
      <tr class="grand"><td class="k">Total</td><td class="v">£{{ number_format($breakdown['gross'], 2) }}</td></tr>
    @else
      <tr class="grand"><td class="k">Total</td><td class="v">£{{ number_format((float) $gross, 2) }}</td></tr>
    @endif
  </table>

  @if($paid)
    <div class="stamp">Paid — thank you</div>
  @else
    <div class="stamp due">Balance due</div>
  @endif

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
