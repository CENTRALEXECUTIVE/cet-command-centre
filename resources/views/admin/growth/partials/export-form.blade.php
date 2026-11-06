{{-- Detailed bookings export for a business review. No names, phones, emails or
     full addresses, so the file can be shared for analysis. --}}
<form method="GET" action="{{ route('marketing.growth.export') }}" class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px">
    <div style="flex:1 1 100%">
        <strong>⬇ Export bookings for review</strong>
        <div class="muted" style="font-size:12px">A detailed CSV of every booking that came in between these dates: channel, dates, lead time, vehicle, airport, area, fare, VAT, payment, account, new or returning. No customer names, phone numbers, emails or full addresses.</div>
    </div>
    <label style="font-size:13px">From
        <input type="date" name="from" class="form-control" value="{{ now(config('app.timezone'))->subMonthsNoOverflow(6)->format('Y-m-d') }}">
    </label>
    <label style="font-size:13px">To
        <input type="date" name="to" class="form-control" value="{{ now(config('app.timezone'))->format('Y-m-d') }}">
    </label>
    <button type="submit" class="btn btn-primary">Export for review</button>
</form>
