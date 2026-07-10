@extends('layouts.app')
@section('title', 'Fleet & Compliance')

@section('content')
    <div class="form-hero">
        <div class="form-hero-glow"></div>
        <div class="fh-eyebrow">Fleet &amp; admin · compliance</div>
        <div class="fh-title">Fleet &amp; Compliance</div>
        <div class="fh-sub">MOT, insurance, PHV licences, compliance tests, PHV badges and DBS — with automated WhatsApp reminders.</div>
    </div>

    <div class="deck" style="grid-template-columns:repeat(3,1fr);margin-bottom:18px">
        <div class="kpi warn" style="border-color:rgba(179,32,32,.4)"><div class="kpi-ico">⛔</div><div class="kpi-n" style="color:#b32020">{{ $expired->count() }}</div><div class="kpi-l">Expired</div></div>
        <div class="kpi warn"><div class="kpi-ico">⏳</div><div class="kpi-n">{{ $dueSoon->count() }}</div><div class="kpi-l">Due soon</div></div>
        <div class="kpi ok"><div class="kpi-ico">✅</div><div class="kpi-n">{{ $valid->count() }}</div><div class="kpi-l">Valid</div></div>
    </div>

    @foreach(['Expired' => $expired, 'Due Soon' => $dueSoon, 'Valid' => $valid] as $heading => $group)
        <div class="card">
            <h2>{{ $heading }}</h2>
            @if($group->isEmpty())
                <p class="muted mb-0">Nothing here.</p>
            @else
                <div class="table-scroll">
                <table class="table-modern">
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
                </div>
            @endif
        </div>
    @endforeach
@endsection
