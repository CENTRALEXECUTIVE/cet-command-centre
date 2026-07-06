@extends('layouts.app')
@section('title', 'Drivers directory')

@section('content')
    <h1 class="page-title">Drivers directory</h1>
    <p class="page-sub">The roster you pick from when preparing a reminder — your own and third-party/cover drivers.</p>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif

    <div class="card">
        <h2>Add a driver</h2>
        <form method="POST" action="{{ route('cover-drivers.store') }}">
            @csrf
            <div class="grid grid-2">
                <div class="field"><label for="name">Driver name <span class="req">*</span></label><input id="name" name="name" value="{{ old('name') }}" placeholder="e.g. Kash" required></div>
                <div class="field"><label for="phone">Contact number</label><input id="phone" name="phone" value="{{ old('phone') }}" placeholder="07…"></div>
                <div class="field"><label for="vehicle_reg">Vehicle reg</label><input id="vehicle_reg" name="vehicle_reg" value="{{ old('vehicle_reg') }}" style="text-transform:uppercase" placeholder="AM64 FAR"></div>
                <div class="field"><label for="vehicle">Make &amp; model</label><input id="vehicle" name="vehicle" value="{{ old('vehicle') }}" placeholder="Black Mercedes V Class"></div>
            </div>
            <button class="btn btn-primary">Add driver</button>
        </form>
    </div>

    <div class="card">
        <h2>Drivers ({{ $drivers->where('is_active', true)->count() }})</h2>
        @if($drivers->isEmpty())
            <p class="muted mb-0">No drivers yet — add one above.</p>
        @else
            @foreach($drivers as $d)
                <div style="border-bottom:1px solid rgba(128,128,128,.12);padding:10px 0;{{ $d->is_active ? '' : 'opacity:.55' }}">
                    <form method="POST" action="{{ route('cover-drivers.update', $d) }}">
                        @csrf @method('PUT')
                        <div class="grid grid-2" style="gap:8px">
                            <input name="name" value="{{ $d->name }}" required>
                            <input name="phone" value="{{ $d->phone }}" placeholder="Contact">
                            <input name="vehicle_reg" value="{{ $d->vehicle_reg }}" style="text-transform:uppercase" placeholder="Reg">
                            <input name="vehicle" value="{{ $d->vehicle }}" placeholder="Make & model">
                        </div>
                        <div style="display:flex;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap">
                            <label class="checkbox-row" style="margin:0"><input type="checkbox" name="is_active" value="1" {{ $d->is_active ? 'checked' : '' }} style="width:auto"> Active</label>
                            <button class="btn btn-light" style="padding:5px 12px;font-size:12px">Save</button>
                            <span style="flex:1"></span>
                            <button type="submit" form="del-{{ $d->id }}" class="btn btn-ghost" style="padding:5px 12px;font-size:12px;color:#b32020">Remove</button>
                        </div>
                    </form>
                    <form id="del-{{ $d->id }}" method="POST" action="{{ route('cover-drivers.destroy', $d) }}" onsubmit="return confirm('Remove {{ $d->name }}?')">@csrf @method('DELETE')</form>
                </div>
            @endforeach
        @endif
    </div>
@endsection
