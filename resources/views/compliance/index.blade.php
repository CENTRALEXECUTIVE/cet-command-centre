@extends('layouts.app')
@section('title', 'Fleet & Compliance')

@section('content')
    <h1 class="page-title">Fleet &amp; Compliance</h1>
    <p class="page-sub">MOT, insurance, PHV licences, compliance tests, PHV badges and DBS — with automated WhatsApp reminders.</p>

    <div class="grid grid-3" style="margin-bottom:24px">
        <div class="stat"><div class="n" style="color:#b32020">{{ $expired->count() }}</div><div class="l">Expired</div></div>
        <div class="stat"><div class="n" style="color:#b25e00">{{ $dueSoon->count() }}</div><div class="l">Due Soon</div></div>
        <div class="stat"><div class="n" style="color:#1f7a44">{{ $valid->count() }}</div><div class="l">Valid</div></div>
    </div>

    @foreach(['Expired' => $expired, 'Due Soon' => $dueSoon, 'Valid' => $valid] as $heading => $group)
        <div class="card">
            <h2>{{ $heading }}</h2>
            @if($group->isEmpty())
                <p class="muted mb-0">Nothing here.</p>
            @else
                <table>
                    <thead><tr><th>Subject</th><th>Type</th><th>Item</th><th>Due</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach($group as $item)
                            <tr>
                                <td><strong>{{ $item->subject_label }}</strong></td>
                                <td>{{ ucfirst($item->subject_type) }}</td>
                                <td>{{ strtoupper(str_replace('_', ' ', $item->item_type)) }}</td>
                                <td>{{ $item->due_on->format('d M Y') }}
                                    <span class="muted">({{ $item->due_on->diffForHumans() }})</span></td>
                                <td>
                                    @php $b = ['expired' => 'cancelled', 'due_soon' => 'en_route', 'valid' => 'complete'][$item->status]; @endphp
                                    <span class="badge badge-{{ $b }}">{{ ucfirst(str_replace('_',' ',$item->status)) }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach
@endsection
