@extends('layouts.app')
@section('title', 'Enquiry')

@section('content')
    <p class="page-sub"><a href="{{ route('enquiries.index') }}">← Enquiries</a></p>
    <h1 class="page-title">{{ $enquiry->from_name ?? $enquiry->from_email ?? 'Enquiry' }}
        <span class="badge badge-{{ $enquiry->status === 'replied' ? 'complete' : 'pending' }}">{{ ucfirst($enquiry->status) }}</span>
    </h1>
    <p class="page-sub">{{ $enquiry->from_email }} · {{ $enquiry->created_at->format('D d M Y, H:i') }}</p>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif

    @php $ex = $enquiry->extracted ?? []; @endphp

    <div class="grid grid-2">
        {{-- Original email --}}
        <div class="card">
            <h2>Original email</h2>
            <p class="muted" style="margin-top:-8px;font-size:13px"><strong>{{ $enquiry->subject }}</strong></p>
            <div style="white-space:pre-wrap;font-size:13px;color:#444;max-height:340px;overflow:auto;background:rgba(128,128,128,.06);border-radius:8px;padding:12px">{{ $enquiry->body }}</div>
        </div>

        {{-- Extracted journey + quote --}}
        <div class="card">
            <h2>What the AI read</h2>
            <table>
                <tr><th>Customer</th><td>{{ $ex['customer_name'] ?? $enquiry->from_name ?? '—' }}</td></tr>
                <tr><th>From</th><td>{{ $ex['pickup'] ?? '—' }}</td></tr>
                <tr><th>To</th><td>{{ $ex['destination'] ?? '—' }}</td></tr>
                <tr><th>When</th><td>{{ $ex['pickup_datetime'] ?? '—' }}</td></tr>
                <tr><th>Passengers</th><td>{{ $ex['passengers'] ?? '—' }}</td></tr>
                <tr><th>Vehicle</th><td>{{ $ex['vehicle'] ?? '—' }}</td></tr>
                <tr><th>Quote basis</th><td>{{ $enquiry->quote_basis ?? '—' }}</td></tr>
            </table>
            <a href="{{ route('bookings.create', array_filter(['customer' => $enquiry->customer_id])) }}" class="btn btn-dark" style="margin-top:10px;padding:8px 14px">Convert to booking →</a>
        </div>
    </div>

    {{-- Draft reply (edit + send) --}}
    <div class="card">
        <h2>Your reply <span class="muted" style="font-weight:400;font-size:13px">— check it, edit if needed, then send</span></h2>
        <form method="POST" action="{{ route('enquiries.update', $enquiry) }}">
            @csrf @method('PUT')
            <div class="grid grid-2">
                <div class="field">
                    <label for="quote_amount">Quote (£)</label>
                    <input id="quote_amount" type="number" step="0.01" min="0" name="quote_amount" value="{{ old('quote_amount', $enquiry->quote_amount) }}">
                </div>
            </div>
            <div class="field">
                <label for="draft_reply">Draft reply</label>
                <textarea id="draft_reply" name="draft_reply" style="min-height:280px;font-size:14px" required>{{ old('draft_reply', $enquiry->draft_reply) }}</textarea>
            </div>
            <button class="btn btn-light" style="padding:8px 16px">Save draft</button>
        </form>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;border-top:1px solid var(--line);padding-top:14px">
            @if($canGraphSend)
                <form method="POST" action="{{ route('enquiries.send', $enquiry) }}">@csrf
                    <button class="btn btn-primary" style="padding:9px 16px">✉ Send from Outlook</button>
                </form>
            @endif
            @if($enquiry->mailtoLink())
                <a href="{{ $enquiry->mailtoLink() }}" class="btn btn-dark" style="padding:9px 16px">Open in Outlook</a>
            @endif
            <button type="button" class="btn btn-ghost copy-reply" style="padding:9px 16px">⧉ Copy reply</button>
            <form method="POST" action="{{ route('enquiries.send', $enquiry) }}">@csrf
                <input type="hidden" name="manual" value="1">
                <button class="btn btn-ghost" style="padding:9px 16px">✓ Mark replied</button>
            </form>
            <form method="POST" action="{{ route('enquiries.dismiss', $enquiry) }}" onsubmit="return confirm('Dismiss this enquiry?')">@csrf
                <button class="btn btn-ghost" style="padding:9px 16px;color:#b32020">Dismiss</button>
            </form>
        </div>
        <p class="hint">“Send from Outlook” replies straight from your bookings mailbox. Or use “Open in Outlook” / Copy to send it yourself, then Mark replied.</p>
    </div>

    @verbatim
    <script>
        document.querySelector('.copy-reply')?.addEventListener('click', function () {
            var t = document.getElementById('draft_reply').value;
            navigator.clipboard.writeText(t).then(function () {
                var b = document.querySelector('.copy-reply'); var o = b.textContent;
                b.textContent = '✓ Copied'; setTimeout(function () { b.textContent = o; }, 1500);
            });
        });
    </script>
    @endverbatim
@endsection
