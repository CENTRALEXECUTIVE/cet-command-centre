@extends('layouts.app')
@section('title', 'Driver Documents')

@section('content')
    <p class="page-sub"><a href="{{ route('driver-documents.index') }}">← All drivers</a></p>
    <h1 class="page-title">{{ $driver->name }} — Documents
        <a href="{{ route('drivers.edit', $driver) }}" class="btn btn-light" style="font-size:13px;padding:4px 12px;vertical-align:middle">Edit driver</a>
    </h1>
    @if($vehicle)
        <p class="page-sub">{{ $vehicle->make }} {{ $vehicle->model }} · {{ $vehicle->registration }} · {{ $vehicle->colour }} · {{ $vehicle->year }}</p>
    @endif

    @if(session('status'))
        <div class="card" style="border-left:4px solid #1f7a44;background:rgba(31,122,68,.08);margin-bottom:16px">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08);margin-bottom:16px">{{ $errors->first() }}</div>
    @endif

    @php
        $meta = [
            'valid'    => ['icon' => '✓', 'colour' => '#1f7a44', 'text' => 'Valid'],
            'expiring' => ['icon' => '!', 'colour' => '#b8860b', 'text' => 'Expiring soon'],
            'expired'  => ['icon' => '✕', 'colour' => '#b32020', 'text' => 'Expired'],
            'missing'  => ['icon' => '↑', 'colour' => '#888', 'text' => 'Not uploaded'],
            'pending'  => ['icon' => '⧗', 'colour' => '#2a6bb0', 'text' => 'Awaiting review'],
            'rejected' => ['icon' => '✕', 'colour' => '#b32020', 'text' => 'Rejected'],
        ];
    @endphp

    <div class="grid grid-2">
        @foreach($rows as $row)
            @php $m = $meta[$row['status']]; $doc = $row['document']; @endphp
            <div class="card" style="border-left:4px solid {{ $m['colour'] }}">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
                    <div>
                        <div style="font-weight:700">{{ $row['label'] }}</div>
                        <div style="color:{{ $m['colour'] }};font-weight:600;font-size:14px">
                            {{ $m['text'] }}@if($row['days'] !== null && in_array($row['status'], ['valid','expiring']))
                                · {{ $row['days'] }} days left
                            @elseif($row['status'] === 'expired' && $row['days'] !== null)
                                · {{ abs($row['days']) }} days ago
                            @endif
                        </div>
                        @if($row['expiry'])<div class="muted" style="font-size:12px">Expires {{ $row['expiry']->format('d M Y') }}</div>@endif
                    </div>
                    <div style="width:34px;height:34px;flex:none;border-radius:50%;border:2px solid {{ $m['colour'] }};color:{{ $m['colour'] }};display:flex;align-items:center;justify-content:center;font-weight:800">{{ $m['icon'] }}</div>
                </div>

                @if($doc)
                    <div style="margin-top:8px;font-size:13px">
                        <a href="{{ route('driver.documents.file', $doc) }}">View file</a>
                        <span class="muted">· {{ $doc->file_name }}</span>
                        @if($doc->review_notes)<div class="muted" style="font-size:12px;margin-top:2px">Note: {{ $doc->review_notes }}</div>@endif
                    </div>

                    @if($doc->status === 'pending')
                        <form method="POST" action="{{ route('driver-documents.review', $doc) }}" style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            @csrf
                            <input type="text" name="notes" placeholder="Note (optional)" style="flex:1;min-width:140px">
                            <button class="btn btn-dark" name="decision" value="approve" style="padding:7px 14px;background:#1f7a44;border-color:#1f7a44">Approve</button>
                            <button class="btn" name="decision" value="reject" style="padding:7px 14px;background:#b32020;color:#fff;border-color:#b32020">Reject</button>
                        </form>
                    @endif
                @endif

                <details style="margin-top:10px">
                    <summary style="cursor:pointer;font-size:13px;color:var(--gold,#b8860b)">{{ $row['hasFile'] ? 'Replace' : 'Upload' }} on driver's behalf</summary>
                    <form method="POST" action="{{ route('driver-documents.store', $driver) }}" enctype="multipart/form-data" style="margin-top:8px;display:flex;flex-direction:column;gap:8px">
                        @csrf
                        <input type="hidden" name="type" value="{{ $row['type'] }}">
                        <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.heic" required>
                        <label style="font-size:12px" class="muted">Expiry date
                            <input type="date" name="expiry_date" value="{{ optional($row['expiry'])->toDateString() }}" style="width:auto">
                        </label>
                        <button class="btn btn-dark" type="submit" style="align-self:flex-start;padding:8px 16px">Upload &amp; approve</button>
                    </form>
                </details>
            </div>
        @endforeach
    </div>
@endsection
