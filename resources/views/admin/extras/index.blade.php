@extends('layouts.app')
@section('title', 'Extra prices')

@section('content')
    <h1 class="page-title">Extra prices</h1>
    <p class="page-sub">The add-on charges customers pay on top of the fare — meet &amp; greet, child seats, wedding ribbons and so on. Change any price here and it applies to new quotes straight away. Set an extra to <strong>£0</strong> to make it free.</p>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())
        <div class="alert alert-danger">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    <form method="POST" action="{{ route('extras.update') }}">
        @csrf
        @method('PUT')
        <div class="card">
            <div style="display:grid;grid-template-columns:1fr auto;gap:10px 16px;align-items:center;max-width:460px">
                @foreach($rows as $r)
                    <label for="extra-{{ $r['key'] }}" style="font-weight:500">{{ $r['label'] }}</label>
                    <div>
                        <span style="color:var(--muted,#888)">£</span>
                        <input id="extra-{{ $r['key'] }}" type="number" step="0.01" min="0" required
                               name="extras[{{ $r['key'] }}]"
                               value="{{ old('extras.'.$r['key'], number_format((float) $r['amount'], 2, '.', '')) }}"
                               style="width:110px;padding:7px 9px;border:1px solid var(--line);border-radius:8px">
                    </div>
                @endforeach
            </div>
            <button class="btn btn-primary" style="margin-top:16px;padding:9px 20px">Save prices</button>
        </div>
    </form>
@endsection
