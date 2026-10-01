@extends('layouts.app')
@section('title', 'Pricing zones')

@section('content')
    <div class="list-head" style="align-items:center">
        <div class="form-hero" style="flex:1;margin-bottom:0">
            <div class="form-hero-glow"></div>
            <div class="fh-eyebrow">Fleet &amp; admin · pricing</div>
            <div class="fh-title">Pricing zones</div>
            <div class="fh-sub">Pickup areas and the postcodes they cover. Carve out dearer “deep” areas here, then set their fares on <a href="{{ route('pricing.index') }}">Fixed prices</a>.</div>
        </div>
    </div>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08)"><ul style="margin:0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    {{-- Add a zone --}}
    <div class="card">
        <h2 style="margin:0 0 10px">Add a zone</h2>
        <form method="POST" action="{{ route('zones.store') }}">
            @csrf
            <div class="grid grid-2" style="gap:12px">
                <div class="field"><label>Zone name</label><input name="name" value="{{ old('name') }}" placeholder="e.g. Barnsley Deep" required></div>
                <div class="field"><label>Postcodes (outcodes)</label><input name="postcode_prefixes" value="{{ old('postcode_prefixes') }}" placeholder="e.g. S71, S72" style="text-transform:uppercase"></div>
            </div>
            <p class="hint" style="margin:2px 0 10px">Comma- or space-separated outcodes (the first part of a postcode). A pickup matching a more specific zone uses that zone's fares.</p>
            <button class="btn btn-primary" style="padding:9px 18px">Add zone</button>
        </form>
    </div>

    {{-- Existing zones --}}
    @foreach($zones as $zone)
        <div class="card" style="{{ $zone->is_active ? '' : 'opacity:.6' }}">
            <form method="POST" action="{{ route('zones.update', $zone) }}">
                @csrf @method('PUT')
                <div class="grid grid-2" style="gap:12px">
                    <div class="field"><label>Zone name</label><input name="name" value="{{ old('name', $zone->name) }}" required></div>
                    <div class="field"><label>Postcodes (outcodes)</label>
                        <input name="postcode_prefixes" value="{{ old('postcode_prefixes', implode(', ', $zone->postcode_prefixes ?? [])) }}" style="text-transform:uppercase" placeholder="none — picked on the booking form">
                    </div>
                </div>
                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:4px">
                    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" value="1" style="width:auto" {{ $zone->is_active ? 'checked' : '' }}> Active</label>
                    <span class="muted" style="font-size:12px">{{ count($zone->postcode_prefixes ?? []) }} postcode(s)</span>
                    <button class="btn btn-primary" style="padding:8px 16px;font-size:14px;margin-left:auto">Save</button>
                </div>
            </form>
        </div>
    @endforeach
@endsection
