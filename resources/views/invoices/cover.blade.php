@extends('layouts.app')
@section('title', 'Cover invoices')

@section('content')
    <style>
        .bill-as { margin: 14px 0; border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px; }
        .bill-as-label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); margin-bottom: 8px; }
        .bill-opts { display: flex; flex-direction: column; gap: 8px; }
        .bill-opt { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px; cursor: pointer; font-size: 14px; }
        .bill-opt input { width: auto; margin: 0; flex: none; }
        .bill-opt-main { font-weight: 600; }
        .bill-opt-tag { font-size: 12px; color: var(--muted); margin-left: auto; }
        .bill-opt:has(input:checked) { border-color: var(--gold); background: rgba(251,186,42,.10); }
        html[data-theme="dark"] .bill-as { border-color: rgba(255,255,255,.10); }
        html[data-theme="dark"] .bill-opt { border-color: rgba(255,255,255,.12); }
        html[data-theme="dark"] .bill-opt-main { color: #f2f5fa; }
        html[data-theme="dark"] .bill-as-label, html[data-theme="dark"] .bill-opt-tag { color: #9aa3b2; }
        html[data-theme="dark"] .bill-opt:has(input:checked) { background: rgba(251,186,42,.14); }
    </style>
    <h1 class="page-title">Cover-job invoices</h1>
    <p class="page-sub">Jobs you've covered for other operators, grouped by company. <strong>Search</strong> by reference, passenger or operator, <strong>tick</strong> the ones to bill, and send a <strong>single combined invoice</strong> with one total — no need for one invoice per job. Once an operator has paid, <strong>Mark paid</strong> so the jobs drop off and can't be billed again.</p>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    <div style="margin:10px 0 16px">
        @if($showPaid)
            <a href="{{ route('cover-invoices.index') }}" class="btn btn-ghost" style="padding:7px 12px;font-size:13px">← Back to unpaid</a>
            <span class="muted" style="font-size:13px;margin-left:6px">Showing all cover jobs, including settled ones.</span>
        @else
            <a href="{{ route('cover-invoices.index', ['show_paid' => 1]) }}" class="btn btn-ghost" style="padding:7px 12px;font-size:13px">✓ Show settled ({{ $paidCount }})</a>
            <span class="muted" style="font-size:13px;margin-left:6px">Paid cover jobs are hidden so they aren't billed twice.</span>
        @endif
    </div>

    @if($groups->isNotEmpty())
        <div class="card" style="margin-bottom:16px;padding:12px 14px">
            <input id="cover-search" type="search" placeholder="🔍 Search by reference, passenger name, operator or address…"
                   autocomplete="off" style="width:100%;padding:11px 14px;border:1px solid var(--line);border-radius:10px;font-size:15px">
            <p id="cover-search-none" class="hint" style="margin:8px 0 0;display:none">No cover jobs match that search.</p>
        </div>
    @endif

    @forelse($groups as $operator => $jobs)
        @php $total = $jobs->sum(fn($b) => (float) ($b->coverForAmount() ?? 0)); @endphp
        <div class="card cover-group" style="margin-bottom:16px" data-op="{{ \Illuminate\Support\Str::lower($operator) }}">
            <form method="POST" action="{{ route('cover-invoices.pdf') }}" target="_blank">
                @csrf
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                    <h2 style="margin:0;font-size:17px">🤝 {{ $operator }} <span class="muted" style="font-weight:400;font-size:14px">· <span class="grp-count">{{ $jobs->count() }}</span> job(s) · £<span class="grp-total">{{ number_format($total, 2) }}</span></span></h2>
                </div>

                <table style="width:100%;border-collapse:collapse;margin-top:10px;font-size:14px">
                    <thead>
                        <tr style="text-align:left;border-bottom:2px solid var(--line)">
                            <th style="padding:6px 8px;width:28px"><input type="checkbox" checked title="Select all shown" onclick="this.closest('form').querySelectorAll('tr.jobrow:not([hidden]) .jobcb').forEach(c=>c.checked=this.checked)"></th>
                            <th style="padding:6px 8px">Date</th>
                            <th style="padding:6px 8px">Passenger</th>
                            <th style="padding:6px 8px">Journey</th>
                            <th style="padding:6px 8px">Ref</th>
                            <th style="padding:6px 8px;text-align:right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($jobs as $b)
                            @php $ref = $b->external_reference ?: $b->reference; $amt = (float) ($b->coverForAmount() ?? 0); @endphp
                            <tr class="jobrow" style="border-bottom:1px solid var(--line)"
                                data-amt="{{ $amt }}"
                                data-search="{{ \Illuminate\Support\Str::lower(trim($b->displayName().' '.$ref.' '.$b->reference.' '.$operator.' '.$b->displayPickupAddress().' '.$b->displayDropoffAddress().' '.$b->pickup_at?->format('d/m/Y'))) }}">
                                <td style="padding:6px 8px"><input type="checkbox" class="jobcb" name="ids[]" value="{{ $b->id }}" checked></td>
                                <td style="padding:6px 8px;white-space:nowrap">{{ $b->pickup_at?->format('d/m/Y H:i') }}</td>
                                <td style="padding:6px 8px">{{ $b->displayName() }}@if($b->isCoverPaid())<span class="badge" style="background:#1f7a44;color:#fff;font-size:10px;padding:2px 7px;border-radius:999px;margin-left:6px">✓ PAID</span>@endif</td>
                                <td style="padding:6px 8px"><a href="{{ route('bookings.show', $b) }}">{{ \Illuminate\Support\Str::limit($b->displayPickupAddress().' → '.$b->displayDropoffAddress(), 40) }}</a></td>
                                <td style="padding:6px 8px;white-space:nowrap">{{ $ref }}</td>
                                <td style="padding:6px 8px;text-align:right">£{{ number_format($amt, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @php
                    $anchorLink = $jobs->first()->meta['cover_payment_link'] ?? null;
                    $defaultProvider = (is_array($anchorLink) && ($anchorLink['provider'] ?? null) === 'stripe') ? 'stripe' : 'square';
                @endphp
                {{-- Which company bills this invoice — drives the business name on the
                     invoice AND which card provider the payment link uses. VAT →
                     Central Executive Transfers Ltd (Square); non-VAT → the sister
                     company PVT LTD (Stripe). --}}
                <div class="bill-as">
                    <span class="bill-as-label">Bill this invoice as</span>
                    <div class="bill-opts">
                        <label class="bill-opt">
                            <input type="radio" name="provider" value="square" @checked($defaultProvider==='square')>
                            <span class="bill-opt-main">Central Executive Transfers Ltd</span>
                            <span class="bill-opt-tag">VAT · Square</span>
                        </label>
                        <label class="bill-opt">
                            <input type="radio" name="provider" value="stripe" @checked($defaultProvider==='stripe')>
                            <span class="bill-opt-main">Central Executive Transfers PVT LTD</span>
                            <span class="bill-opt-tag">non-VAT · Stripe</span>
                        </label>
                    </div>
                </div>

                <div class="toolbar" style="margin-top:12px">
                    <button type="submit" class="btn btn-primary" style="padding:8px 14px">👁 View combined invoice</button>
                    <button type="submit" class="btn btn-ghost" style="padding:8px 14px" formaction="{{ route('cover-invoices.pdf') }}" name="download" value="1">⬇ Download</button>
                    <button type="button" class="btn btn-light paylink-btn" style="padding:8px 14px"
                            data-url="{{ route('cover-invoices.payment-link') }}"
                            data-total="{{ number_format($total, 2) }}">💳 Create payment link</button>
                    <button type="submit" class="btn btn-dark" style="padding:8px 14px" formtarget="_self"
                            formaction="{{ route('cover-invoices.email') }}"
                            onclick="return confirm('Email the combined invoice to {{ $jobs->first()->coverFor()['email'] ?: $operator }}?')">✉ Email to {{ $operator }}</button>
                    <button type="submit" class="btn btn-success" style="padding:8px 14px;background:#1f7a44;color:#fff;border-color:#1f7a44" formtarget="_self"
                            formaction="{{ route('cover-invoices.mark-paid') }}"
                            onclick="return confirm('Mark the ticked job(s) as PAID? They drop off this list so they can\'t be billed again. (Use ‘Show settled’ to reopen if needed.)')">✅ Mark paid</button>
                    @if($showPaid)
                        <button type="submit" class="btn btn-ghost" style="padding:8px 14px" formtarget="_self"
                                formaction="{{ route('cover-invoices.mark-paid') }}" name="paid" value="0"
                                onclick="return confirm('Reopen the ticked job(s) so they can be invoiced again?')">↩ Reopen</button>
                    @endif
                </div>
                @php $existingLink = $jobs->first()->meta['cover_payment_link'] ?? null; @endphp
                <div class="paylink-panel" style="margin-top:10px;background:#f3faf3;border:1px solid #cfe8cf;border-radius:10px;padding:10px 12px;{{ (is_array($existingLink) && ($existingLink['url'] ?? null)) ? '' : 'display:none' }}">
                    <strong style="font-size:13px">💳 Payment link ready</strong>
                    <span class="paylink-meta muted" style="font-size:12px">@if(is_array($existingLink) && ($existingLink['url'] ?? null))· {{ $existingLink['reference'] ?? '' }} · £{{ number_format((float) ($existingLink['amount'] ?? 0), 2) }}@endif</span>
                    <div style="display:flex;gap:8px;align-items:center;margin-top:6px;flex-wrap:wrap">
                        <input type="text" class="paylink-url" value="{{ (is_array($existingLink) ? ($existingLink['url'] ?? '') : '') }}" readonly onclick="this.select()" style="flex:1;min-width:200px;font-size:12px;padding:6px 8px;border:1px solid var(--line);border-radius:8px">
                        <a class="paylink-open btn btn-ghost" href="{{ (is_array($existingLink) ? ($existingLink['url'] ?? '#') : '#') }}" target="_blank" rel="noopener" style="padding:6px 12px;font-size:12px">Open</a>
                    </div>
                    <p class="hint" style="margin:6px 0 0">It's on the View/Download/Email invoice. Re-tick and press Create again if the jobs or amount change.</p>
                </div>
                <p class="paylink-error" style="margin:8px 0 0;color:#b32020;font-size:13px;display:none"></p>
                @unless($jobs->first()->coverFor()['email'] ?? null)
                    <p class="hint" style="margin:8px 0 0;color:#8a6d00">No email saved for {{ $operator }} — add one on any of their bookings to enable emailing.</p>
                @endunless
            </form>
        </div>
    @empty
        <div class="card"><p class="muted" style="margin:0">No cover jobs yet. Mark a booking as a cover job (on the booking page) and it'll appear here, grouped by operator.</p></div>
    @endforelse

    @if($groups->isNotEmpty())
        @verbatim
        <script>
        (function () {
            var box = document.getElementById('cover-search');
            var none = document.getElementById('cover-search-none');
            if (!box) return;
            function money(n) { return (Math.round(n * 100) / 100).toFixed(2); }
            function apply() {
                var q = box.value.trim().toLowerCase();
                var anyShown = false;
                document.querySelectorAll('.cover-group').forEach(function (group) {
                    var shown = 0, total = 0;
                    group.querySelectorAll('tr.jobrow').forEach(function (row) {
                        var hit = !q || (row.getAttribute('data-search') || '').indexOf(q) !== -1;
                        row.hidden = !hit;
                        if (hit) { shown++; total += parseFloat(row.getAttribute('data-amt') || '0'); }
                    });
                    group.hidden = shown === 0;
                    if (shown > 0) anyShown = true;
                    var c = group.querySelector('.grp-count'); if (c) c.textContent = shown;
                    var t = group.querySelector('.grp-total'); if (t) t.textContent = money(total);
                });
                if (none) none.style.display = anyShown ? 'none' : '';
            }
            box.addEventListener('input', apply);
        })();
        </script>

        <script>
        (function () {
            document.querySelectorAll('.paylink-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var form = btn.closest('form');
                    if (!form) return;
                    var group = btn.closest('.cover-group');
                    var panel = group.querySelector('.paylink-panel');
                    var errEl = group.querySelector('.paylink-error');
                    var ids = Array.prototype.map.call(
                        form.querySelectorAll('.jobcb:checked'), function (c) { return c.value; });

                    errEl.style.display = 'none';
                    if (!ids.length) { errEl.textContent = 'Tick at least one job first.'; errEl.style.display = ''; return; }
                    var providerEl = form.querySelector('input[name=provider]:checked');
                    var provider = providerEl ? providerEl.value : 'square';
                    var providerName = provider === 'stripe' ? 'Stripe (non-VAT / PVT LTD)' : 'Square (VAT)';
                    if (!confirm('Create a ' + providerName + ' payment link for the ' + ids.length + ' ticked job(s)? It goes onto the invoice.')) return;

                    var original = btn.textContent;
                    btn.disabled = true; btn.textContent = 'Creating…';

                    var body = new FormData();
                    ids.forEach(function (id) { body.append('ids[]', id); });
                    body.append('provider', provider);
                    var token = form.querySelector('[name=_token]');
                    fetch(btn.dataset.url, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json',
                                   'X-CSRF-TOKEN': token ? token.value : '' },
                        body: body
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        btn.disabled = false; btn.textContent = original;
                        if (!d || !d.ok) { errEl.textContent = (d && d.error) || 'Could not create the link.'; errEl.style.display = ''; return; }
                        panel.querySelector('.paylink-meta').textContent = '· ' + d.reference + ' · £' + d.amount;
                        panel.querySelector('.paylink-url').value = d.url;
                        panel.querySelector('.paylink-open').href = d.url;
                        panel.style.display = '';
                        panel.scrollIntoView({ block: 'nearest' });
                    })
                    .catch(function () {
                        btn.disabled = false; btn.textContent = original;
                        errEl.textContent = 'Network error creating the link — try again.'; errEl.style.display = '';
                    });
                });
            });
        })();
        </script>
        @endverbatim
    @endif
@endsection
