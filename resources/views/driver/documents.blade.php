@extends('layouts.app')
@section('title', 'Vehicle & Documents')

@section('content')
    <h1 class="page-title">Vehicle &amp; Documents</h1>
    <p class="page-sub">Keep every document valid — upload a clear photo or PDF and enter its expiry date.</p>

    @if(session('status'))
        <div class="card" style="border-left:4px solid #1f7a44;background:rgba(31,122,68,.08);margin-bottom:16px">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08);margin-bottom:16px">{{ $errors->first() }}</div>
    @endif

    @if($vehicle)
        <div class="card" style="margin-bottom:16px">
            <div style="font-size:22px;font-weight:800;text-transform:uppercase">{{ $vehicle->make }} {{ $vehicle->model }}</div>
            <div class="muted" style="text-transform:uppercase;letter-spacing:.5px">
                {{ $vehicle->registration }} &middot; {{ $vehicle->colour }} &middot; {{ $vehicle->year }}
            </div>
        </div>
    @endif

    @php
        $meta = [
            'valid'    => ['icon' => '✓', 'colour' => '#1f7a44'],
            'expiring' => ['icon' => '!', 'colour' => '#b8860b'],
            'expired'  => ['icon' => '✕', 'colour' => '#b32020'],
            'missing'  => ['icon' => '↑', 'colour' => '#888'],
            'pending'  => ['icon' => '⧗', 'colour' => '#2a6bb0'],
            'rejected' => ['icon' => '✕', 'colour' => '#b32020'],
        ];
    @endphp

    <div class="grid grid-2">
        @foreach($rows as $row)
            @php $m = $meta[$row['status']]; @endphp
            <div class="card" style="border-left:4px solid {{ $m['colour'] }}">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
                    <div>
                        <div style="font-weight:700">{{ $row['label'] }}</div>
                        <div style="color:{{ $m['colour'] }};font-weight:600;font-size:14px">
                            @switch($row['status'])
                                @case('missing') Upload required @break
                                @case('pending') Pending review @break
                                @case('rejected') Rejected — re-upload @break
                                @case('expired') Expired {{ abs($row['days']) }} days ago @break
                                @default
                                    @if($row['days'] === 0) Expires today
                                    @else {{ $row['days'] }} Days Left @endif
                            @endswitch
                        </div>
                        @if($row['expiry'])
                            <div class="muted" style="font-size:12px">Expires {{ $row['expiry']->format('d M Y') }}</div>
                        @endif
                    </div>
                    <div style="width:34px;height:34px;flex:none;border-radius:50%;border:2px solid {{ $m['colour'] }};color:{{ $m['colour'] }};display:flex;align-items:center;justify-content:center;font-weight:800">{{ $m['icon'] }}</div>
                </div>

                <div style="margin-top:10px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                    @if($row['document'])
                        <a href="{{ route('driver.documents.file', $row['document']) }}" style="font-size:13px">View current file</a>
                    @endif
                    <details style="flex:1;min-width:220px">
                        <summary style="cursor:pointer;font-size:13px;color:var(--gold,#b8860b)">{{ $row['hasFile'] ? 'Replace' : 'Upload' }} document</summary>
                        <form method="POST" action="{{ route('driver.documents.store') }}" enctype="multipart/form-data" style="margin-top:8px;display:flex;flex-direction:column;gap:8px">
                            @csrf
                            <input type="hidden" name="type" value="{{ $row['type'] }}">
                            <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.heic" required>
                            <label style="font-size:12px" class="muted">Expiry date
                                <input type="date" name="expiry_date" value="{{ optional($row['expiry'])->toDateString() }}" style="width:auto">
                            </label>
                            <button class="btn btn-dark" type="submit" style="align-self:flex-start;padding:8px 16px">Submit</button>
                        </form>
                    </details>
                </div>
            </div>
        @endforeach
    </div>
@endsection
