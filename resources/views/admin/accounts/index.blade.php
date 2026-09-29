@extends('layouts.app')
@section('title', 'Business accounts')

@section('content')
    <h1 class="page-title">Business accounts</h1>
    <p class="page-sub">Monthly-invoice company accounts — find by name or account number, see contacts and bookings.</p>

    <form method="GET" action="{{ route('accounts.index') }}" class="toolbar" style="margin-bottom:16px">
        <input type="search" name="q" value="{{ $term }}" placeholder="Search company, account no. or VAT…" style="max-width:340px">
        <button class="btn btn-primary" style="padding:9px 16px">Search</button>
        @if($term)
            <a href="{{ route('accounts.index') }}" class="btn btn-ghost" style="padding:9px 16px">Clear</a>
        @endif
    </form>

    <div class="card">
        @if($accounts->isEmpty())
            <p class="muted mb-0">No business accounts found{{ $term ? ' for “'.$term.'”' : '' }}.</p>
        @else
            <table>
                <thead>
                    <tr><th>Account&nbsp;no.</th><th>Company</th><th>Contacts</th><th>Bookings</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach($accounts as $a)
                        <tr>
                            <td><strong>{{ $a->account_code }}</strong></td>
                            <td><a href="{{ route('accounts.show', $a) }}">{{ $a->name }}</a></td>
                            <td>{{ $a->contacts_count }}</td>
                            <td>{{ $a->bookings_count }}</td>
                            <td>
                                @if($a->is_active)
                                    <span class="badge" style="background:#e7f6ec;color:#1f7a44">Active</span>
                                @else
                                    <span class="badge" style="background:#fbeaea;color:#b32020">Pending</span>
                                @endif
                            </td>
                            <td class="right"><a href="{{ route('accounts.show', $a) }}" style="font-size:13px">View →</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="margin-top:16px">{{ $accounts->links() }}</div>
        @endif
    </div>
@endsection
