@extends('layouts.app')
@section('title', 'Customers')

@section('content')
    <h1 class="page-title">Customers</h1>
    <p class="page-sub">Everyone who's travelled with CET — search, history and quick re-book.</p>

    <form method="GET" action="{{ route('customers.index') }}" class="toolbar" style="margin-bottom:16px">
        <input type="search" name="q" value="{{ $term }}" placeholder="Search name, phone or email…" style="max-width:340px">
        <button class="btn btn-primary" style="padding:9px 16px">Search</button>
        @if($term)
            <a href="{{ route('customers.index') }}" class="btn btn-ghost" style="padding:9px 16px">Clear</a>
        @endif
    </form>

    <div class="card">
        @if($customers->isEmpty())
            <p class="muted mb-0">No customers found{{ $term ? ' for “'.$term.'”' : '' }}.</p>
        @else
            <table>
                <thead>
                    <tr><th>Name</th><th>Contact</th><th>Trips</th><th>Last trip</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach($customers as $c)
                        <tr>
                            <td>
                                <a href="{{ route('customers.show', $c) }}">{{ $c->name }}</a>
                                @if($c->is_vip)<span class="badge" style="background:#FBBA2A;color:#0b0b0b;margin-left:6px">VIP</span>@endif
                            </td>
                            <td class="muted" style="font-size:13px">{{ $c->phone ?? $c->email ?? '—' }}</td>
                            <td>{{ $c->bookings_count }}</td>
                            <td class="muted" style="font-size:13px">{{ $c->bookings_max_pickup_at ? \Illuminate\Support\Carbon::parse($c->bookings_max_pickup_at)->format('d M Y') : '—' }}</td>
                            <td class="right"><a href="{{ route('customers.show', $c) }}" style="font-size:13px">View →</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="margin-top:16px">{{ $customers->links() }}</div>
        @endif
    </div>
@endsection
