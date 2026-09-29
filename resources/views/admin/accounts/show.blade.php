@extends('layouts.app')
@section('title', $account->name)

@section('content')
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
        <div>
            <h1 class="page-title" style="margin-bottom:2px">{{ $account->name }}</h1>
            <p class="page-sub" style="margin-top:0">
                Account&nbsp;no. <strong>{{ $account->account_code }}</strong>
                @if($account->is_active)
                    · <span class="badge" style="background:#e7f6ec;color:#1f7a44">Active</span>
                @else
                    · <span class="badge" style="background:#fbeaea;color:#b32020">Pending approval</span>
                @endif
            </p>
        </div>
        <a href="{{ route('accounts.index') }}" class="btn btn-ghost" style="padding:8px 14px">← All accounts</a>
    </div>

    <div class="card" style="margin-bottom:14px">
        <table>
            <tbody>
                <tr><th style="text-align:left;width:180px">Company number</th><td>{{ $account->company_number ?: '—' }}</td></tr>
                <tr><th style="text-align:left">VAT number</th><td>{{ $account->vat_number ?: '—' }}</td></tr>
                <tr><th style="text-align:left">Billing email</th><td>{{ $account->billing_email ?: '—' }}</td></tr>
                <tr><th style="text-align:left">Phone</th><td>{{ $account->phone ?: '—' }}</td></tr>
                <tr><th style="text-align:left">Billing address</th><td>{!! $account->billing_address ? nl2br(e($account->billing_address)) : '—' !!}</td></tr>
                <tr><th style="text-align:left">Payment terms</th><td>{{ $account->payment_terms_days }} days · invoiced monthly</td></tr>
                <tr><th style="text-align:left">Cost code required</th><td>{{ $account->cost_code_required ? 'Yes' : 'No' }}</td></tr>
                <tr><th style="text-align:left">Lifetime value</th><td>£{{ number_format($lifetimeValue, 2) }}</td></tr>
            </tbody>
        </table>
        @if($account->notes)
            <p class="muted" style="margin:12px 0 0;font-size:13px">{!! nl2br(e($account->notes)) !!}</p>
        @endif
        <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
            <a href="{{ route('reports.business', $account) }}" class="btn btn-ghost" style="padding:8px 14px">📊 Account report</a>
            <a href="{{ route('invoices.index') }}" class="btn btn-ghost" style="padding:8px 14px">🧾 Invoices</a>
        </div>
    </div>

    <div class="card" style="margin-bottom:14px">
        <h2 style="margin:0 0 10px;font-size:17px">Authorised contacts</h2>
        @if($account->contacts->isEmpty())
            <p class="muted mb-0">No contacts on this account.</p>
        @else
            <table>
                <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th></tr></thead>
                <tbody>
                    @foreach($account->contacts->sortByDesc('is_primary') as $c)
                        <tr>
                            <td>{{ $c->name }}@if($c->is_primary)<span class="badge" style="background:#FBBA2A;color:#0b0b0b;margin-left:6px">Primary</span>@endif</td>
                            <td class="muted" style="font-size:13px">{{ $c->email ?: '—' }}</td>
                            <td class="muted" style="font-size:13px">{{ $c->phone ?: '—' }}</td>
                            <td class="muted" style="font-size:13px">{{ $c->job_title ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card">
        <h2 style="margin:0 0 10px;font-size:17px">Recent bookings <span class="muted" style="font-weight:400;font-size:13px">({{ $bookings->count() }})</span></h2>
        @if($bookings->isEmpty())
            <p class="muted mb-0">No bookings on this account yet.</p>
        @else
            <table>
                <thead><tr><th>Date</th><th>Journey</th><th>Booker</th><th>Payment</th><th>Fare</th><th></th></tr></thead>
                <tbody>
                    @foreach($bookings as $b)
                        <tr>
                            <td class="muted" style="font-size:13px">{{ $b->pickup_at?->format('d M Y · H:i') ?? '—' }}</td>
                            <td style="font-size:13px">{{ \Illuminate\Support\Str::limit($b->pickup_address, 22) }} → {{ \Illuminate\Support\Str::limit($b->destination_address, 22) }}</td>
                            <td class="muted" style="font-size:13px">{{ $b->customer?->name ?? '—' }}</td>
                            <td style="font-size:13px">{{ $b->payment_method?->label() ?? '—' }}</td>
                            <td style="font-size:13px">{{ $b->quoted_price ? '£'.number_format((float) $b->quoted_price, 2) : '—' }}</td>
                            <td class="right"><a href="{{ route('bookings.show', $b) }}" style="font-size:13px">View →</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
