@extends('layouts.app')
@section('title', 'GDPR — Right to Erasure')

@section('content')
    <h1 class="page-title">Right to Erasure</h1>
    <p class="page-sub">Delete all personal data for a customer on request (UK GDPR Article 17).
        Bookings are kept for accounting but stripped of personal data.</p>

    @if($errors->any())
        <div class="alert alert-error">{{ $errors->first() }}</div>
    @endif

    <div class="card">
        <h2>Raise an erasure request</h2>
        <form method="POST" action="{{ route('gdpr.erasure.store') }}">
            @csrf
            <div class="grid grid-2">
                <div class="field">
                    <label for="identifier">Customer email or phone <span class="req">*</span></label>
                    <input id="identifier" name="identifier" value="{{ old('identifier') }}" required>
                </div>
                <div class="field">
                    <label for="reason">Reason / reference</label>
                    <input id="reason" name="reason" value="{{ old('reason') }}" placeholder="e.g. customer email 14/06">
                </div>
            </div>
            <button type="submit" class="btn btn-dark">Raise request</button>
        </form>
        <p class="hint" style="margin-top:10px">Verify the requester's identity before processing — erasure cannot be undone.</p>
    </div>

    <div class="card">
        <h2>Requests</h2>
        @if($requests->isEmpty())
            <p class="muted mb-0">No erasure requests.</p>
        @else
            <table>
                <thead><tr><th>Customer</th><th>Requested</th><th>Reason</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @foreach($requests as $req)
                        <tr>
                            <td>{{ $req->customer?->name ?? '—' }}</td>
                            <td>{{ $req->requested_at?->format('d M Y H:i') }}<br><span class="muted">{{ $req->requested_by_email }}</span></td>
                            <td>{{ $req->reason ?? '—' }}</td>
                            <td><span class="badge badge-{{ $req->status === 'completed' ? 'complete' : ($req->status === 'rejected' ? 'cancelled' : 'pending') }}">{{ ucfirst($req->status) }}</span></td>
                            <td>
                                @if($req->status === 'pending' && $req->customer)
                                    <form method="POST" action="{{ route('gdpr.erasure.process', $req) }}"
                                          onsubmit="return confirm('Permanently erase all personal data for this customer? This cannot be undone.');">
                                        @csrf
                                        <button type="submit" class="btn-xs" style="background:#b32020">Erase now</button>
                                    </form>
                                @elseif($req->status === 'completed')
                                    <span class="muted" style="font-size:12px">{{ $req->processed_at?->format('d M H:i') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="margin-top:16px">{{ $requests->links() }}</div>
        @endif
    </div>
@endsection
