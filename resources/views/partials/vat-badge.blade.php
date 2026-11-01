{{-- Which company/account this job is billed and sent from: VAT → Ltd (Square),
     non-VAT → PVT LTD (Stripe). Shown under the reference on payroll. --}}
@php $vatJob = $booking->vatInvoiceRequested(); @endphp
<div style="margin-top:3px">
    <span class="badge" style="font-size:10px;padding:2px 7px;border-radius:999px;{{ $vatJob ? 'background:#FBBA2A;color:#111' : 'background:#e9e9ec;color:#333' }}"
          title="{{ $vatJob ? 'VAT invoice: send from Central Executive Transfers Ltd (Square)' : 'No VAT: send from Central Executive Transfers PVT LTD (Stripe)' }}">
        {{ $vatJob ? 'VAT · Ltd' : 'Non-VAT · PVT' }}
    </span>
</div>
