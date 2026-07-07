@extends('layouts.app')
@section('title', 'Check the booking')

@section('content')
    <p class="page-sub"><a href="{{ route('intake.index') }}">← Paste another</a></p>
    <h1 class="page-title">Check the booking</h1>
    <p class="page-sub">This is how it will look on the calendar. Fix anything that’s wrong, press <strong>Update preview</strong> to re-check, then <strong>Confirm</strong> when you’re happy.</p>

    @unless($aiAvailable)
        <div class="card" style="border-left:4px solid #b8860b;background:rgba(184,134,11,.08);margin-bottom:16px">
            The AI reader isn’t connected, so the fields below weren’t auto-filled. Type them in and the preview still builds correctly.
        </div>
    @endunless

    @if($errors->any())
        <div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08);margin-bottom:16px"><ul style="margin:0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    {{-- The calendar preview exactly as it will be written. --}}
    <div class="card" style="border-left:4px solid #FBBA2A">
        <h2 style="margin-top:0">📅 Calendar preview</h2>
        <div style="font-weight:700;font-size:16px;margin-bottom:6px">{{ $preview['title'] }}</div>
        <div class="muted" style="margin-bottom:10px">📍 {{ $preview['location'] ?: '— no pickup location —' }}</div>
        <div style="white-space:pre-wrap;font-size:14px;line-height:1.6;background:rgba(0,0,0,.03);padding:12px;border-radius:8px">{{ $preview['description'] }}</div>
    </div>

    {{-- Editable fields. Both buttons submit these. --}}
    <form method="POST" action="{{ route('intake.confirm') }}" class="eto-form" style="margin-top:16px">
        @csrf
        <div class="eto-section">
            <div class="head"><span class="ico">✏️</span> Details</div>
            <div class="body">
                <div class="grid grid-2">
                    <div class="field"><label>Passenger (lead) name <span class="req">*</span></label><input name="fields[lead_name]" value="{{ $fields['lead_name'] }}" required></div>
                    <div class="field"><label>Contact number</label><input name="fields[contact_no]" value="{{ $fields['contact_no'] }}"></div>
                    <div class="field"><label>Email</label><input name="fields[email]" value="{{ $fields['email'] }}"></div>
                    <div class="field"><label>Pickup date &amp; time <span class="req">*</span></label><input name="fields[pickup_at]" value="{{ $fields['pickup_at'] }}" placeholder="2026-07-12 14:30" required></div>
                    <div class="field"><label>Pickup address <span class="req">*</span></label><input name="fields[pickup_address]" value="{{ $fields['pickup_address'] }}" required></div>
                    <div class="field"><label>Drop-off address <span class="req">*</span></label><input name="fields[destination_address]" value="{{ $fields['destination_address'] }}" required></div>
                    <div class="field"><label>Where (title tag)</label><input name="fields[where]" value="{{ $fields['where'] }}" placeholder="MAN / LHR / Sheffield"></div>
                    <div class="field"><label>Flight number</label><input name="fields[flight_number]" value="{{ $fields['flight_number'] }}"></div>
                    <div class="field"><label>Passengers</label><input type="number" min="1" name="fields[passengers]" value="{{ $fields['passengers'] }}"></div>
                    <div class="field"><label>Luggage</label><input type="number" min="0" name="fields[luggage]" value="{{ $fields['luggage'] }}"></div>
                    <div class="field">
                        <label>Vehicle</label>
                        <select name="fields[vehicle]">
                            @foreach($vehicleTypes as $vt)
                                <option value="{{ $vt }}" @selected($fields['vehicle'] === $vt)>{{ $vt }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label>Payment</label>
                        <select name="fields[payment]">
                            <option value="cash" @selected($fields['payment']==='cash')>Cash</option>
                            <option value="card" @selected($fields['payment']==='card')>Card</option>
                            <option value="account" @selected($fields['payment']==='account')>Account</option>
                        </select>
                    </div>
                    <div class="field"><label>Booked by</label><input name="fields[booked_by]" value="{{ $fields['booked_by'] }}"></div>
                </div>
                <div class="checkbox-row"><input id="paid" type="checkbox" name="fields[paid]" value="1" @checked(!empty($fields['paid']))><label for="paid">Fully paid</label></div>
                <div class="field"><label>Notes</label><textarea name="fields[notes]" rows="2">{{ $fields['notes'] }}</textarea></div>
            </div>
        </div>

        <div class="toolbar">
            <button type="submit" formaction="{{ route('intake.preview') }}" class="btn btn-ghost">↻ Update preview</button>
            <span style="flex:1"></span>
            <button type="submit" class="btn btn-primary" onclick="return confirm('Create this booking and add it to the calendar?')">✓ Confirm &amp; create</button>
        </div>
    </form>
@endsection
