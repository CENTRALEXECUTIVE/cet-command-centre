@extends('layouts.app')
@section('title', 'Vehicles')

@section('content')
    <h1 class="page-title">Vehicles</h1>
    <p class="page-sub">Control the vehicle classes customers see on the booking page — name, subtitle, how many passengers &amp; bags each carries, whether it's shown, and the order. Prices live in the pricing editors; photos in <a href="{{ route('fleet-photos.index') }}">Fleet photos</a>.</p>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())
        <div class="alert alert-danger">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    @foreach($vehicleTypes as $vt)
        <div class="card" style="margin-bottom:14px;{{ $vt->is_active ? '' : 'opacity:.6' }}">
            <form method="POST" action="{{ route('vehicles.update', $vt) }}">
                @csrf
                @method('PUT')

                <div style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap">
                    <div style="flex:0 0 auto">
                        @if($vt->photoUrl())
                            <img src="{{ $vt->photoUrl() }}" alt="{{ $vt->name }}" style="width:120px;height:80px;object-fit:cover;border-radius:8px;border:1px solid var(--line)">
                        @else
                            <div style="width:120px;height:80px;border-radius:8px;border:1px dashed var(--line);display:flex;align-items:center;justify-content:center;font-size:26px;color:var(--muted,#999)">🚗</div>
                        @endif
                    </div>

                    <div style="flex:1 1 460px;min-width:280px">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 14px">
                            <label style="grid-column:1 / -1">
                                <span class="hint" style="display:block;margin-bottom:3px">Name (what customers see)</span>
                                <input type="text" name="name" value="{{ old('name', $vt->name) }}" maxlength="60" required
                                       style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                            </label>

                            <label style="grid-column:1 / -1">
                                <span class="hint" style="display:block;margin-bottom:3px">Subtitle (small text under the name — e.g. “Mercedes E/S-Class”)</span>
                                <input type="text" name="tagline" value="{{ old('tagline', $vt->tagline()) }}" maxlength="60"
                                       style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                            </label>

                            <label>
                                <span class="hint" style="display:block;margin-bottom:3px">👥 Max passengers</span>
                                <input type="number" name="passenger_capacity" value="{{ old('passenger_capacity', $vt->passenger_capacity) }}" min="1" max="60" required
                                       style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                            </label>

                            <label>
                                <span class="hint" style="display:block;margin-bottom:3px">🧳 Max suitcases</span>
                                <input type="number" name="luggage_capacity" value="{{ old('luggage_capacity', $vt->luggage_capacity) }}" min="0" max="60" required
                                       style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                            </label>

                            <label>
                                <span class="hint" style="display:block;margin-bottom:3px">🎒 Max hand luggage</span>
                                <input type="number" name="hand_luggage_capacity" value="{{ old('hand_luggage_capacity', $vt->handLuggageCapacity()) }}" min="0" max="60" required
                                       style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                            </label>

                            <label>
                                <span class="hint" style="display:block;margin-bottom:3px">Order (low = first)</span>
                                <input type="number" name="sort_order" value="{{ old('sort_order', $vt->sort_order) }}" min="0" max="999" required
                                       style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                            </label>
                        </div>

                        <div style="display:flex;align-items:center;gap:16px;margin-top:12px;flex-wrap:wrap">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $vt->is_active)) style="width:18px;height:18px">
                                <span>Show this vehicle to customers</span>
                            </label>
                            <button class="btn btn-primary" style="padding:8px 18px;margin-left:auto">Save</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    @endforeach
@endsection
