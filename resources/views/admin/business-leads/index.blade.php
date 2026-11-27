@extends('layouts.app')
@section('title', 'Business leads')

@section('content')
    <h1 class="page-title">Business leads</h1>
    <p class="page-sub">Past customers who booked with a company email address and aren't on a business account yet. They already know us — a short personal email with the open-account link (centralexecutivetransfers.co.uk/open-account) works best. Most trips first.</p>

    @if($companies->isEmpty())
        <div class="card">No business leads yet.</div>
    @else
        <div class="card" style="overflow-x:auto;padding:0">
            <table class="table" style="width:100%;min-width:640px">
                <thead><tr><th>Company (email domain)</th><th>People</th><th>Trips</th><th>Spent</th><th>Last trip</th></tr></thead>
                <tbody>
                @foreach($companies as $co)
                    <tr>
                        <td><strong>{{ $co['domain'] }}</strong></td>
                        <td>@foreach($co['people'] as $p)<a href="{{ route('customers.show', $p) }}">{{ $p->name }}</a> <small>{{ $p->email }}</small>@if(! $loop->last)<br>@endif @endforeach</td>
                        <td>{{ $co['trips'] }}</td>
                        <td>£{{ number_format($co['spent'], 0) }}</td>
                        <td>{{ $co['last_trip'] ? \Illuminate\Support\Carbon::parse($co['last_trip'])->format('d M Y') : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
