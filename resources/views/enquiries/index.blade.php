@extends('layouts.app')
@section('title', 'Enquiries')

@section('content')
    <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
        <div>
            <h1 class="page-title" style="margin-bottom:2px">Email Enquiries</h1>
            <p class="page-sub">Outlook enquiries with an AI-prepared quote and a draft reply. You check, then send.</p>
        </div>
        <form method="POST" action="{{ route('enquiries.refresh') }}">@csrf
            <button class="btn btn-light" style="padding:8px 14px">↻ Check inbox now</button>
        </form>
    </div>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    @unless($graphReady)
        <div class="card" style="border-left:4px solid #FBBA2A;background:rgba(251,186,42,.08)">
            Outlook isn't connected yet — enquiries appear once Microsoft Graph is configured. (The mailbox is read-only; nothing is sent without you.)
        </div>
    @endunless

    <div class="card">
        <h2>To action ({{ $open->count() }})</h2>
        @if($open->isEmpty())
            <p class="muted mb-0">No open enquiries. 🎉</p>
        @else
            <table>
                <thead><tr><th>From</th><th>Journey</th><th>Quote</th><th>When</th><th></th></tr></thead>
                <tbody>
                    @foreach($open as $e)
                        @php $ex = $e->extracted ?? []; @endphp
                        <tr>
                            <td>{{ $e->from_name ?? $e->from_email ?? '—' }}</td>
                            <td style="font-size:13px">{{ \Illuminate\Support\Str::limit($ex['pickup'] ?? '—', 22) }} → {{ \Illuminate\Support\Str::limit($ex['destination'] ?? '—', 22) }}</td>
                            <td>{{ $e->quote_amount !== null ? '£'.number_format($e->quote_amount, 2) : '—' }}</td>
                            <td class="muted" style="font-size:13px">{{ $e->created_at->diffForHumans() }}</td>
                            <td class="right"><a href="{{ route('enquiries.show', $e) }}" class="btn btn-primary" style="padding:4px 12px;font-size:12px">Review →</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if($done->isNotEmpty())
        <div class="card">
            <h2>Recently handled</h2>
            <table>
                <tbody>
                    @foreach($done as $e)
                        <tr>
                            <td>{{ $e->from_name ?? $e->from_email }}</td>
                            <td class="muted" style="font-size:13px">{{ \Illuminate\Support\Str::limit($e->subject, 40) }}</td>
                            <td><span class="badge badge-{{ $e->status === 'replied' ? 'complete' : ($e->status === 'booked' ? 'accepted' : 'cancelled') }}">{{ ucfirst($e->status) }}</span></td>
                            <td class="right"><a href="{{ route('enquiries.show', $e) }}" style="font-size:13px">View →</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
