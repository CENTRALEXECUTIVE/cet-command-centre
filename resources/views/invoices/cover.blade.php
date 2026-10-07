@extends('layouts.app')
@section('title', 'Cover invoices')

@section('content')
    <h1 class="page-title">Cover-job invoices</h1>
    <p class="page-sub">Jobs you've covered for other operators, grouped by company. Tick the ones to bill and send a <strong>single combined invoice</strong> with one total — no need for one invoice per job.</p>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    @forelse($groups as $operator => $jobs)
        @php $total = $jobs->sum(fn($b) => (float) ($b->coverForAmount() ?? 0)); @endphp
        <div class="card" style="margin-bottom:16px">
            <form method="POST" action="{{ route('cover-invoices.pdf') }}" target="_blank">
                @csrf
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                    <h2 style="margin:0;font-size:17px">🤝 {{ $operator }} <span class="muted" style="font-weight:400;font-size:14px">· {{ $jobs->count() }} job{{ $jobs->count() === 1 ? '' : 's' }} · £{{ number_format($total, 2) }}</span></h2>
                </div>

                <table style="width:100%;border-collapse:collapse;margin-top:10px;font-size:14px">
                    <thead>
                        <tr style="text-align:left;border-bottom:2px solid var(--line)">
                            <th style="padding:6px 8px;width:28px"><input type="checkbox" checked onclick="this.closest('form').querySelectorAll('.jobcb').forEach(c=>c.checked=this.checked)"></th>
                            <th style="padding:6px 8px">Date</th>
                            <th style="padding:6px 8px">Journey</th>
                            <th style="padding:6px 8px">Ref</th>
                            <th style="padding:6px 8px;text-align:right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($jobs as $b)
                            <tr style="border-bottom:1px solid var(--line)">
                                <td style="padding:6px 8px"><input type="checkbox" class="jobcb" name="ids[]" value="{{ $b->id }}" checked></td>
                                <td style="padding:6px 8px;white-space:nowrap">{{ $b->pickup_at?->format('d/m/Y H:i') }}</td>
                                <td style="padding:6px 8px"><a href="{{ route('bookings.show', $b) }}">{{ \Illuminate\Support\Str::limit($b->displayPickupAddress().' → '.$b->displayDropoffAddress(), 48) }}</a></td>
                                <td style="padding:6px 8px">{{ $b->external_reference ?: $b->reference }}</td>
                                <td style="padding:6px 8px;text-align:right">£{{ number_format((float) ($b->coverForAmount() ?? 0), 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="toolbar" style="margin-top:12px">
                    <button type="submit" class="btn btn-primary" style="padding:8px 14px">👁 View combined invoice</button>
                    <button type="submit" class="btn btn-ghost" style="padding:8px 14px" formaction="{{ route('cover-invoices.pdf') }}" name="download" value="1">⬇ Download</button>
                    <button type="submit" class="btn btn-dark" style="padding:8px 14px" target="_self"
                            formaction="{{ route('cover-invoices.email') }}"
                            onclick="return confirm('Email the combined invoice to {{ $jobs->first()->coverFor()['email'] ?: $operator }}?')">✉ Email to {{ $operator }}</button>
                </div>
                @unless($jobs->first()->coverFor()['email'] ?? null)
                    <p class="hint" style="margin:8px 0 0;color:#8a6d00">No email saved for {{ $operator }} — add one on any of their bookings to enable emailing.</p>
                @endunless
            </form>
        </div>
    @empty
        <div class="card"><p class="muted" style="margin:0">No cover jobs yet. Mark a booking as a cover job (on the booking page) and it'll appear here, grouped by operator.</p></div>
    @endforelse
@endsection
