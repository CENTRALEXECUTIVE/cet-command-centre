@extends('layouts.app')
@section('title', 'AI phone calls')

@section('content')
    <h1 class="page-title">AI phone calls</h1>
    @if(auth()->user()->isSuperAdmin())<p style="float:right;margin:0"><a href="{{ route('phone-calls.setup') }}">Voice setup</a></p>@endif
    <p class="page-sub">Every call the phone agent answered. Booking requests wait here until you confirm them; nothing is booked or sent to the caller automatically.</p>
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    <div class="card">
        <p class="mb-0"><strong>{{ $open }}</strong> {{ $open === 1 ? 'call needs' : 'calls need' }} you.</p>
    </div>

    <div class="card" style="margin-top:16px;overflow-x:auto">
        <table class="table">
            <thead><tr><th>When</th><th>Caller</th><th>Outcome</th><th>Summary</th><th></th></tr></thead>
            <tbody>
            @forelse($calls as $c)
                <tr @if($c->needs_action && ! $c->reviewed_at) style="font-weight:600" @endif>
                    <td style="white-space:nowrap;font-variant-numeric:tabular-nums">{{ $c->created_at->timezone(config('app.timezone'))->format('D j M, H:i') }}</td>
                    <td style="white-space:nowrap">{{ $c->from_number ?: 'Withheld' }}</td>
                    <td>
                        @if($c->booking_fields) <span class="badge badge-warning">Booking request</span>
                        @elseif($c->message) <span class="badge badge-warning">Message</span>
                        @elseif($c->status === 'transferred') <span class="badge">Put through</span>
                        @elseif($c->status === 'active') <span class="badge">On the line</span>
                        @else <span class="badge">Enquiry</span> @endif
                        @if($c->reviewed_at) <span class="muted" style="font-size:12px">dealt with</span> @endif
                    </td>
                    <td>{{ \Illuminate\Support\Str::limit($c->summary ?? '', 110) }}</td>
                    <td><a href="{{ route('phone-calls.show', $c) }}">Open</a></td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No calls yet. Once the Twilio number points at the agent, calls appear here.</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $calls->links() }}
    </div>
@endsection
