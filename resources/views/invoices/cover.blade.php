@extends('layouts.app')
@section('title', 'Cover invoices')

@section('content')
    <h1 class="page-title">Cover-job invoices</h1>
    <p class="page-sub">Jobs you've covered for other operators, grouped by company. <strong>Search</strong> by reference, passenger or operator, <strong>tick</strong> the ones to bill, and send a <strong>single combined invoice</strong> with one total — no need for one invoice per job.</p>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

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
                                <td style="padding:6px 8px">{{ $b->displayName() }}</td>
                                <td style="padding:6px 8px"><a href="{{ route('bookings.show', $b) }}">{{ \Illuminate\Support\Str::limit($b->displayPickupAddress().' → '.$b->displayDropoffAddress(), 40) }}</a></td>
                                <td style="padding:6px 8px;white-space:nowrap">{{ $ref }}</td>
                                <td style="padding:6px 8px;text-align:right">£{{ number_format($amt, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="toolbar" style="margin-top:12px">
                    <button type="submit" class="btn btn-primary" style="padding:8px 14px">👁 View combined invoice</button>
                    <button type="submit" class="btn btn-ghost" style="padding:8px 14px" formaction="{{ route('cover-invoices.pdf') }}" name="download" value="1">⬇ Download</button>
                    <button type="button" class="btn btn-light paylink-btn" style="padding:8px 14px"
                            data-url="{{ route('cover-invoices.payment-link') }}"
                            data-total="{{ number_format($total, 2) }}">💳 Create payment link</button>
                    <button type="submit" class="btn btn-dark" style="padding:8px 14px" formtarget="_self"
                            formaction="{{ route('cover-invoices.email') }}"
                            onclick="return confirm('Email the combined invoice to {{ $jobs->first()->coverFor()['email'] ?: $operator }}?')">✉ Email to {{ $operator }}</button>
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
                    if (!confirm('Create a card payment link for the ' + ids.length + ' ticked job(s)? It goes onto the invoice.')) return;

                    var original = btn.textContent;
                    btn.disabled = true; btn.textContent = 'Creating…';

                    var body = new FormData();
                    ids.forEach(function (id) { body.append('ids[]', id); });
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
