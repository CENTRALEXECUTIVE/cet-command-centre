@extends('layouts.app')
@section('title', 'Edit Driver')

@section('content')
    <p class="page-sub"><a href="{{ route('driver-documents.show', $driver) }}">← {{ $driver->name }}'s documents</a></p>
    <h1 class="page-title">Edit Driver</h1>

    @if($errors->any())
        <div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08);margin-bottom:16px">
            <ul style="margin:0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('drivers.update', $driver) }}">
        @csrf
        @method('PUT')
        <div class="card">
            <div class="grid grid-2">
                <label>Full name
                    <input type="text" name="name" value="{{ old('name', $driver->name) }}" required>
                </label>
                <label>Email
                    <input type="email" name="email" value="{{ old('email', $driver->email) }}" required>
                </label>
                <label>Phone
                    <input type="text" name="phone" value="{{ old('phone', $driver->phone) }}">
                </label>
                <label>Reset password
                    <input type="text" name="password" placeholder="Leave blank to keep current">
                </label>
            </div>
            <label style="display:flex;gap:8px;align-items:center;margin-top:8px">
                <input type="checkbox" name="is_active" value="1" style="width:auto" {{ old('is_active', $driver->is_active) ? 'checked' : '' }}>
                Active (can log in and be allocated jobs)
            </label>
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
@endsection
