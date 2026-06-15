<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@verbatim
<style>
  body { font-family: DejaVu Sans, sans-serif; color: #141414; font-size: 12px; }
  .head { display: flex; justify-content: space-between; border-bottom: 3px solid #FBBA2A; padding-bottom: 12px; }
  .brand { font-size: 18px; font-weight: bold; }
  .brand .gold { color: #FBBA2A; }
  h1 { font-size: 20px; margin: 16px 0 4px; }
  table { width: 100%; border-collapse: collapse; margin-top: 16px; }
  th { text-align: left; border-bottom: 2px solid #e6e6e6; padding: 6px; font-size: 11px; color: #666; text-transform: uppercase; }
  td { padding: 6px; border-bottom: 1px solid #eee; }
  .right { text-align: right; }
  .totals { width: 240px; margin-left: auto; margin-top: 12px; }
  .muted { color: #666; }
  .foot { margin-top: 30px; font-size: 10px; color: #666; border-top: 1px solid #e6e6e6; padding-top: 8px; }
</style>
@endverbatim
</head>
<body>
  <div class="head">
    <div>
      <div class="brand">CENTRAL <span class="gold">EXECUTIVE</span> TRANSFERS</div>
      <div class="muted">{{ config('cet.company.name') }} · Company No. {{ config('cet.company.number') }}</div>
      <div class="muted">Operator Licence {{ config('cet.company.operator_licence') }}</div>
    </div>
    <div class="right">
      <h1>INVOICE</h1>
      <div>{{ $invoice->invoice_number }}</div>
      <div class="muted">Issued {{ $invoice->issued_at?->format('d M Y') }}</div>
      <div class="muted">Due {{ $invoice->due_at?->format('d M Y') }}</div>
    </div>
  </div>

  <p><strong>Billed to:</strong> {{ $invoice->corporateAccount?->name }}<br>
    <span class="muted">Period {{ $invoice->period_start?->format('d M Y') }} – {{ $invoice->period_end?->format('d M Y') }}</span></p>

  <table>
    <thead><tr><th>Description</th><th>Cost code</th><th class="right">Amount</th></tr></thead>
    <tbody>
      @foreach($invoice->items as $item)
        <tr>
          <td>{{ $item->description }}</td>
          <td>{{ $item->cost_code ?? '—' }}</td>
          <td class="right">£{{ number_format($item->amount, 2) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <table class="totals">
    <tr><td>Subtotal</td><td class="right">£{{ number_format($invoice->subtotal, 2) }}</td></tr>
    <tr><td>VAT ({{ (int) (config('cet.vat_rate') * 100) }}%)</td><td class="right">£{{ number_format($invoice->vat_amount, 2) }}</td></tr>
    <tr><td><strong>Total due</strong></td><td class="right"><strong>£{{ number_format($invoice->total, 2) }}</strong></td></tr>
  </table>

  <div class="foot">
    {{ config('cet.company.name') }} · {{ config('cet.company.website') }}
    @if(config('cet.ico_registration_number')) · ICO {{ config('cet.ico_registration_number') }} @endif
  </div>
</body>
</html>
