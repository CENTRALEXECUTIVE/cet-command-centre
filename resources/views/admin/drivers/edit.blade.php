@extends('layouts.app')
@section('title', 'Edit Driver')

@section('content')
    <p class="page-sub"><a href="{{ route('driver-documents.show', $driver) }}">← {{ $driver->name }}'s documents</a></p>
    <h1 class="page-title">Edit Driver</h1>
    <p class="page-sub">Edit everything about this driver — account, compliance and vehicle.</p>

    @if($errors->any())
        <div class="alert alert-error">
            <ul style="margin:0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('drivers.update', $driver) }}" class="eto-form">
        @csrf
        @method('PUT')

        {{-- ───────────── Account ───────────── --}}
        <div class="eto-section">
            <div class="head"><span class="ico">👤</span> Account &amp; Login</div>
            <div class="body">
                <div class="grid grid-2">
                    <div class="field">
                        <label for="name">Full name <span class="req">*</span></label>
                        <input id="name" type="text" name="name" value="{{ old('name', $driver->name) }}" required>
                    </div>
                    <div class="field">
                        <label for="email">Email (login)</label>
                        <input id="email" type="email" name="email" value="{{ old('email', $driver->email) }}" required>
                    </div>
                    <div class="field">
                        <label for="phone">Phone</label>
                        <input id="phone" type="text" name="phone" value="{{ old('phone', $driver->phone) }}" placeholder="07…">
                    </div>
                    <div class="field">
                        <label for="password">Reset password</label>
                        <input id="password" type="text" name="password" placeholder="Leave blank to keep current" autocomplete="off">
                    </div>
                </div>
                <div class="checkbox-row">
                    <input id="is_active" type="checkbox" name="is_active" value="1" {{ old('is_active', $driver->is_active) ? 'checked' : '' }}>
                    <label for="is_active">Active — can log in and be allocated jobs</label>
                </div>
            </div>
        </div>

        {{-- ───────────── Compliance / profile ───────────── --}}
        <div class="eto-section">
            <div class="head"><span class="ico">🪪</span> Driver Compliance</div>
            <div class="body">
                <div class="field">
                    <label for="callsign">Callsign / display name <span class="muted">(shown to customers)</span></label>
                    <input id="callsign" name="callsign" value="{{ old('callsign', $profile?->callsign) }}" placeholder="e.g. Abdi, Kash, Maj">
                    <div class="hint">This is the name on WhatsApp driver-details and the calendar tag. Leave blank to use the login name.</div>
                </div>

                <div class="checkbox-row" style="margin-bottom:14px">
                    <input id="is_third_party" type="checkbox" name="is_third_party" value="1" {{ old('is_third_party', $profile?->is_third_party) ? 'checked' : '' }}>
                    <label for="is_third_party">Third-party / cover driver (not a core CET driver)</label>
                </div>
                <div class="checkbox-row" style="margin-bottom:14px">
                    <input id="is_available" type="checkbox" name="is_available" value="1" {{ old('is_available', $profile?->is_available ?? true) ? 'checked' : '' }}>
                    <label for="is_available">Available for allocation</label>
                </div>

                <div class="grid grid-2">
                    <div class="field">
                        <label for="phv_badge_number">PHV / private hire badge no.</label>
                        <input id="phv_badge_number" name="phv_badge_number" value="{{ old('phv_badge_number', $profile?->phv_badge_number) }}">
                    </div>
                    <div class="field">
                        <label for="phv_badge_expiry">Badge expiry</label>
                        <input id="phv_badge_expiry" type="date" name="phv_badge_expiry" value="{{ old('phv_badge_expiry', $profile?->phv_badge_expiry?->format('Y-m-d')) }}">
                    </div>
                    <div class="field">
                        <label for="driving_licence_number">Driving licence no.</label>
                        <input id="driving_licence_number" name="driving_licence_number" value="{{ old('driving_licence_number', $profile?->driving_licence_number) }}">
                    </div>
                    <div class="field">
                        <label for="driving_licence_expiry">Licence expiry</label>
                        <input id="driving_licence_expiry" type="date" name="driving_licence_expiry" value="{{ old('driving_licence_expiry', $profile?->driving_licence_expiry?->format('Y-m-d')) }}">
                    </div>
                    <div class="field">
                        <label for="dbs_status">DBS status</label>
                        <input id="dbs_status" name="dbs_status" value="{{ old('dbs_status', $profile?->dbs_status) }}" placeholder="e.g. Enhanced — clear">
                    </div>
                    <div class="field">
                        <label for="dbs_issue_date">DBS issued</label>
                        <input id="dbs_issue_date" type="date" name="dbs_issue_date" value="{{ old('dbs_issue_date', $profile?->dbs_issue_date?->format('Y-m-d')) }}">
                    </div>
                    <div class="field">
                        <label for="dbs_expiry">DBS expiry</label>
                        <input id="dbs_expiry" type="date" name="dbs_expiry" value="{{ old('dbs_expiry', $profile?->dbs_expiry?->format('Y-m-d')) }}">
                    </div>
                </div>
                <div class="field" style="margin-bottom:0">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" placeholder="Anything worth flagging about this driver">{{ old('notes', $profile?->notes) }}</textarea>
                </div>
                <p class="hint">Expiry dates set here feed the compliance reminders and the days-left cards. Uploading &amp; approving a document later will overwrite the matching date.</p>
            </div>
        </div>

        {{-- ───────────── Vehicle ───────────── --}}
        <div class="eto-section">
            <div class="head"><span class="ico">🚗</span> Vehicle</div>
            <div class="body">
                @unless($vehicle)
                    <p class="hint" style="margin-top:0">No vehicle on file yet — fill in a registration below to create one.</p>
                @endunless
                <div class="grid grid-2">
                    <div class="field">
                        <label for="registration">Registration</label>
                        <input id="registration" name="registration" value="{{ old('registration', $vehicle?->registration) }}" placeholder="e.g. KX19 ABC" style="text-transform:uppercase">
                    </div>
                    <div class="field">
                        <label for="vehicle_type_id">Vehicle class</label>
                        <select id="vehicle_type_id" name="vehicle_type_id">
                            @foreach($vehicleTypes as $vt)
                                <option value="{{ $vt->id }}" @selected(old('vehicle_type_id', $vehicle?->vehicle_type_id)==$vt->id)>{{ $vt->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="make">Make</label>
                        <input id="make" name="make" value="{{ old('make', $vehicle?->make) }}" placeholder="e.g. Mercedes">
                    </div>
                    <div class="field">
                        <label for="model">Model</label>
                        <input id="model" name="model" value="{{ old('model', $vehicle?->model) }}" placeholder="e.g. E-Class">
                    </div>
                    <div class="field">
                        <label for="colour">Colour</label>
                        <input id="colour" name="colour" value="{{ old('colour', $vehicle?->colour) }}" placeholder="e.g. Black">
                    </div>
                    <div class="field">
                        <label for="year">Year</label>
                        <input id="year" type="number" name="year" min="1990" max="2100" value="{{ old('year', $vehicle?->year) }}">
                    </div>
                    <div class="field">
                        <label for="mot_expiry">MOT expiry</label>
                        <input id="mot_expiry" type="date" name="mot_expiry" value="{{ old('mot_expiry', $vehicle?->mot_expiry?->format('Y-m-d')) }}">
                    </div>
                    <div class="field">
                        <label for="insurance_expiry">Insurance expiry</label>
                        <input id="insurance_expiry" type="date" name="insurance_expiry" value="{{ old('insurance_expiry', $vehicle?->insurance_expiry?->format('Y-m-d')) }}">
                    </div>
                    <div class="field">
                        <label for="phv_licence_expiry">PHV plate / licence expiry</label>
                        <input id="phv_licence_expiry" type="date" name="phv_licence_expiry" value="{{ old('phv_licence_expiry', $vehicle?->phv_licence_expiry?->format('Y-m-d')) }}">
                    </div>
                    <div class="field">
                        <label for="compliance_test_date">Last compliance test</label>
                        <input id="compliance_test_date" type="date" name="compliance_test_date" value="{{ old('compliance_test_date', $vehicle?->compliance_test_date?->format('Y-m-d')) }}">
                    </div>
                </div>
                <div class="checkbox-row" style="margin:4px 0 14px">
                    <input id="vehicle_active" type="checkbox" name="vehicle_active" value="1" {{ old('vehicle_active', $vehicle?->is_active ?? true) ? 'checked' : '' }}>
                    <label for="vehicle_active">Vehicle in service</label>
                </div>
                <div class="field" style="margin-bottom:0">
                    <label for="vehicle_notes">Vehicle notes</label>
                    <textarea id="vehicle_notes" name="vehicle_notes" placeholder="Damage, servicing, plate authority…">{{ old('vehicle_notes', $vehicle?->notes) }}</textarea>
                </div>
            </div>
        </div>

        <div class="toolbar">
            <button type="submit" class="btn btn-primary">Save all changes</button>
            <a href="{{ route('driver-documents.show', $driver) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
@endsection
