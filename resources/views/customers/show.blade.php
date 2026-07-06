@extends('layouts.app')
@section('title', $customer->name)

@section('content')
    <p class="page-sub"><a href="{{ route('customers.index') }}">← Customers</a></p>
    <h1 class="page-title">
        {{ $customer->name }}
        @if($customer->is_vip)<span class="badge" style="background:#FBBA2A;color:#0b0b0b">VIP</span>@endif
    </h1>
    <p class="page-sub">{{ $customer->phone ?? '—' }} @if($customer->email)· {{ $customer->email }}@endif</p>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    {{-- Headline stats + quick actions --}}
    <div class="grid grid-3" style="margin-bottom:16px">
        <div class="stat"><div class="n">{{ $tripCount }}</div><div class="l">Trips</div></div>
        <div class="stat"><div class="n">£{{ number_format($lifetimeValue, 0) }}</div><div class="l">Lifetime value</div></div>
        <div class="stat">
            <div class="n" style="font-size:15px;padding-top:8px">
                <a href="{{ route('bookings.create', ['customer' => $customer->id]) }}" class="btn btn-primary" style="padding:9px 14px">＋ New booking</a>
            </div>
            <div class="l">Re-book this customer</div>
        </div>
    </div>

    <div class="grid grid-2">
        {{-- Details / edit --}}
        <div class="card">
            <h2>Details</h2>
            <form method="POST" action="{{ route('customers.update', $customer) }}">
                @csrf @method('PUT')
                <div class="field">
                    <label for="name">Full name</label>
                    <input id="name" name="name" value="{{ old('name', $customer->name) }}" required>
                </div>
                <div class="grid grid-2">
                    <div class="field">
                        <label for="phone">Phone</label>
                        <input id="phone" name="phone" value="{{ old('phone', $customer->phone) }}">
                    </div>
                    <div class="field">
                        <label for="email">Email</label>
                        <input id="email" type="email" name="email" value="{{ old('email', $customer->email) }}">
                    </div>
                </div>
                <div class="checkbox-row" style="margin-bottom:10px">
                    <input id="is_vip" type="checkbox" name="is_vip" value="1" {{ old('is_vip', $customer->is_vip) ? 'checked' : '' }}>
                    <label for="is_vip">VIP — flag for priority handling</label>
                </div>
                <div class="checkbox-row" style="margin-bottom:12px">
                    <input id="marketing_consent" type="checkbox" name="marketing_consent" value="1" {{ old('marketing_consent', $customer->marketing_consent) ? 'checked' : '' }}>
                    <label for="marketing_consent">Consents to marketing messages</label>
                </div>
                <div class="field">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" placeholder="Preferences, access notes, anything worth remembering">{{ old('notes', $customer->notes) }}</textarea>
                </div>
                <button class="btn btn-primary">Save</button>
            </form>
        </div>

        {{-- Saved addresses --}}
        <div class="card">
            <h2>Saved addresses</h2>
            @forelse($customer->addresses as $addr)
                <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;padding:8px 0;border-bottom:1px solid rgba(128,128,128,.12)">
                    <div>
                        <strong style="font-size:13px">{{ $addr->label }}</strong>
                        <div class="muted" style="font-size:13px">{{ $addr->address }}@if($addr->postcode) · {{ $addr->postcode }}@endif</div>
                    </div>
                    <form method="POST" action="{{ route('customers.addresses.destroy', [$customer, $addr]) }}" onsubmit="return confirm('Remove this address?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-ghost" style="padding:3px 10px;font-size:12px">Remove</button>
                    </form>
                </div>
            @empty
                <p class="muted">No saved addresses yet.</p>
            @endforelse

            <form method="POST" action="{{ route('customers.addresses.store', $customer) }}" style="margin-top:12px">
                @csrf
                <div class="grid grid-2">
                    <div class="field"><label for="label">Label</label><input id="label" name="label" placeholder="Home / Work" required></div>
                    <div class="field"><label for="postcode">Postcode</label><input id="postcode" name="postcode" placeholder="S20 1AA"></div>
                </div>
                <div class="field">
                    <label for="address">Address</label>
                    <input id="address" name="address" data-places autocomplete="off" placeholder="Start typing…" required>
                </div>
                <button class="btn btn-dark" style="padding:8px 16px">Add address</button>
            </form>
        </div>
    </div>

    {{-- Booking history --}}
    <div class="card">
        <h2>Trip history</h2>
        @if($bookings->isEmpty())
            <p class="muted mb-0">No trips yet.</p>
        @else
            <table>
                <thead><tr><th>Ref</th><th>Pickup</th><th>Route</th><th>Vehicle</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach($bookings as $b)
                        <tr>
                            <td><a href="{{ route('bookings.show', $b) }}" class="mono">{{ $b->reference }}</a></td>
                            <td>{{ $b->pickup_at->format('d M Y, H:i') }}</td>
                            <td style="font-size:13px">{{ \Illuminate\Support\Str::limit($b->pickup_address, 20) }} → {{ \Illuminate\Support\Str::limit($b->destination_address, 20) }}</td>
                            <td>{{ $b->vehicleType?->name }}</td>
                            <td><span class="badge badge-{{ $b->status->value }}">{{ $b->status->label() }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <script>
        window.CET_MAPS_KEY = "{{ \App\Models\Setting::mapsKey() }}";
        window.CET_PLACES_URL = "{{ route('places.autocomplete') }}";
    </script>
    <script src="{{ asset('js/cet-forms.js') }}?v=4"></script>
@endsection
