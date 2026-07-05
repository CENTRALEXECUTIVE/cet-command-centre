@extends('layouts.app')
@section('title', 'Add Driver')

@section('content')
    <p class="page-sub"><a href="{{ route('driver-documents.index') }}">← Driver documents</a></p>
    <h1 class="page-title">Add Driver</h1>
    <p class="page-sub">Creates their login and profile. You'll add documents next.</p>

    @if($errors->any())
        <div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08);margin-bottom:16px">
            <ul style="margin:0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('drivers.store') }}">
        @csrf
        <div class="card">
            <h2>Driver login</h2>
            <div class="grid grid-2">
                <label>Full name
                    <input type="text" name="name" value="{{ old('name') }}" required>
                </label>
                <label>Email (their login)
                    <input type="email" name="email" value="{{ old('email') }}" required>
                </label>
                <label>Phone
                    <input type="text" name="phone" value="{{ old('phone') }}">
                </label>
                <label>Password
                    <input type="text" name="password" placeholder="Leave blank to auto-generate">
                </label>
                <label>Callsign / display name
                    <input type="text" name="callsign" value="{{ old('callsign') }}" placeholder="e.g. Abdi (shown to customers)">
                </label>
            </div>
            <label style="display:flex;gap:8px;align-items:center;margin-top:8px">
                <input type="checkbox" name="is_third_party" value="1" style="width:auto" {{ old('is_third_party') ? 'checked' : '' }}>
                Third-party driver (does not join the ABDI/MAJ rotation)
            </label>
        </div>

        <div class="card">
            <h2>Vehicle <span class="muted" style="font-weight:400;font-size:14px">(optional — you can add it later)</span></h2>
            <div class="grid grid-2">
                <label>Registration
                    <input type="text" name="registration" value="{{ old('registration') }}" style="text-transform:uppercase">
                </label>
                <label>Type
                    <select name="vehicle_type_id">
                        @foreach($vehicleTypes as $vt)
                            <option value="{{ $vt->id }}" @selected(old('vehicle_type_id') == $vt->id)>{{ $vt->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Make
                    <input type="text" name="make" value="{{ old('make') }}">
                </label>
                <label>Model
                    <input type="text" name="model" value="{{ old('model') }}">
                </label>
                <label>Colour
                    <input type="text" name="colour" value="{{ old('colour') }}">
                </label>
                <label>Year
                    <input type="number" name="year" value="{{ old('year') }}" min="1990" max="2100">
                </label>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Create driver &amp; add documents →</button>
    </form>
@endsection
