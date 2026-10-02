@extends('layouts.app')
@section('title', 'Standing bookings')

@section('content')
    <div class="list-head" style="align-items:center">
        <div class="form-hero" style="flex:1;margin-bottom:0">
            <div class="form-hero-glow"></div>
            <div class="fh-eyebrow">Sales · bookings</div>
            <div class="fh-title">Standing bookings</div>
            <div class="fh-sub">Regular runs that create a booking automatically — a weekly airport run, a daily school run.</div>
        </div>
        <form method="POST" action="{{ route('recurring.generate') }}" style="margin:0">
            @csrf
            <button class="btn btn-ghost" style="padding:9px 16px" title="Create upcoming bookings now">⚡ Generate now</button>
        </form>
    </div>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08)"><ul style="margin:0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    {{-- New standing booking --}}
    <div class="card">
        <h2 style="margin:0 0 10px">New standing booking</h2>
        <form method="POST" action="{{ route('recurring.store') }}">
            @csrf
            <div class="grid grid-2" style="gap:12px">
                <div class="field"><label>Customer name</label><input name="customer_name" value="{{ old('customer_name') }}" required></div>
                <div class="field"><label>Customer phone</label><input name="customer_phone" value="{{ old('customer_phone') }}" placeholder="07…" required></div>
                <div class="field"><label>Pickup address</label><input name="pickup_address" value="{{ old('pickup_address') }}" required></div>
                <div class="field"><label>Pickup postcode</label><input name="pickup_postcode" value="{{ old('pickup_postcode') }}" style="text-transform:uppercase"></div>
                <div class="field"><label>Drop-off address</label><input name="destination_address" value="{{ old('destination_address') }}" required></div>
                <div class="field"><label>Drop-off postcode</label><input name="destination_postcode" value="{{ old('destination_postcode') }}" style="text-transform:uppercase"></div>
                <div class="field"><label>Vehicle</label>
                    <select name="vehicle_type_id">@foreach($vehicleTypes as $vt)<option value="{{ $vt->id }}">{{ $vt->name }}</option>@endforeach</select>
                </div>
                <div class="field"><label>Passengers</label><input name="passengers" type="number" min="1" max="16" value="{{ old('passengers', 1) }}" required></div>
                <div class="field"><label>Frequency</label>
                    <select name="frequency" id="rf-freq" onchange="document.getElementById('rf-weekday').style.display=this.value==='weekly'?'':'none'">
                        <option value="weekly">Weekly (choose a day)</option>
                        <option value="weekdays">Weekdays (Mon–Fri)</option>
                        <option value="daily">Every day</option>
                    </select>
                </div>
                <div class="field" id="rf-weekday"><label>Day of week</label>
                    <select name="weekday">
                        @foreach(['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $i => $d)
                            <option value="{{ $i }}" @selected((string)old('weekday','1')===(string)$i)>{{ $d }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field"><label>Pickup time</label><input name="pickup_time" type="time" value="{{ old('pickup_time','09:00') }}" required></div>
                <div class="field"><label>Payment</label>
                    <select name="payment_method">
                        <option value="cash">Cash</option><option value="card">Card</option><option value="account">Account</option>
                    </select>
                </div>
                <div class="field"><label>Create how many days ahead?</label><input name="lead_days" type="number" min="0" max="30" value="{{ old('lead_days', 3) }}" required></div>
            </div>
            <div class="field"><label>Notes (optional)</label><input name="notes" value="{{ old('notes') }}" placeholder="e.g. meet in reception"></div>
            <button class="btn btn-primary" style="padding:9px 18px">Create standing booking</button>
        </form>
    </div>

    {{-- Existing --}}
    <div class="card">
        <div class="table-scroll">
            <table class="table-modern table-cards">
                <thead><tr><th>Customer</th><th>Journey</th><th>When</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse($templates as $t)
                        <tr style="{{ $t->is_active ? '' : 'opacity:.55' }}">
                            <td data-label="Customer">{{ $t->customer?->name ?? '—' }}</td>
                            <td data-label="Journey" style="font-size:13px">{{ \Illuminate\Support\Str::limit($t->pickup_address, 24) }} → {{ \Illuminate\Support\Str::limit($t->destination_address, 24) }}<br><span class="muted">{{ $t->vehicleType?->name }} · {{ $t->passengers }} pax</span></td>
                            <td data-label="When" style="font-size:13px">{{ $t->frequencyLabel() }}<br><span class="muted">{{ $t->pickup_time }}</span></td>
                            <td data-label="Status">@if($t->is_active)<span class="badge" style="background:#1f8b4c;color:#fff">Active</span>@else<span class="badge">Paused</span>@endif</td>
                            <td data-label="" class="right">
                                <form method="POST" action="{{ route('recurring.update', $t) }}" style="display:inline">
                                    @csrf @method('PUT')
                                    {{-- keep current values, just flip active --}}
                                    <input type="hidden" name="customer_name" value="{{ $t->customer?->name }}">
                                    <input type="hidden" name="customer_phone" value="{{ $t->customer?->phone }}">
                                    <input type="hidden" name="vehicle_type_id" value="{{ $t->vehicle_type_id }}">
                                    <input type="hidden" name="frequency" value="{{ $t->frequency }}">
                                    <input type="hidden" name="weekday" value="{{ $t->weekday }}">
                                    <input type="hidden" name="pickup_time" value="{{ $t->pickup_time }}">
                                    <input type="hidden" name="pickup_address" value="{{ $t->pickup_address }}">
                                    <input type="hidden" name="pickup_postcode" value="{{ $t->pickup_postcode }}">
                                    <input type="hidden" name="destination_address" value="{{ $t->destination_address }}">
                                    <input type="hidden" name="destination_postcode" value="{{ $t->destination_postcode }}">
                                    <input type="hidden" name="passengers" value="{{ $t->passengers }}">
                                    <input type="hidden" name="payment_method" value="{{ $t->payment_method }}">
                                    <input type="hidden" name="notes" value="{{ $t->notes }}">
                                    <input type="hidden" name="lead_days" value="{{ $t->lead_days }}">
                                    <input type="hidden" name="is_active" value="{{ $t->is_active ? '0' : '1' }}">
                                    <button class="btn btn-light" style="padding:6px 12px;font-size:13px">{{ $t->is_active ? 'Pause' : 'Resume' }}</button>
                                </form>
                                <form method="POST" action="{{ route('recurring.destroy', $t) }}" style="display:inline" onsubmit="return confirm('Remove this standing booking? Already-created bookings stay.')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-ghost" style="padding:6px 12px;font-size:13px;color:#b32020">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted" style="text-align:center;padding:20px">No standing bookings yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
