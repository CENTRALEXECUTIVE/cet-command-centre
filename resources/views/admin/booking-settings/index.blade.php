@extends('layouts.app')
@section('title', 'Booking settings')

@section('content')
    <div class="list-head" style="align-items:center">
        <div class="form-hero" style="flex:1;margin-bottom:0">
            <div class="form-hero-glow"></div>
            <div class="fh-eyebrow">Fleet &amp; admin · booking system</div>
            <div class="fh-title">Booking settings</div>
            <div class="fh-sub">How the online booking system behaves — notice, pricing rules, policies.</div>
        </div>
    </div>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08)"><ul style="margin:0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    <form method="POST" action="{{ route('booking-settings.update') }}">
        @csrf @method('PUT')

        <div class="card">
            <h2 style="margin:0 0 10px">Timing &amp; pricing</h2>
            <div class="grid grid-2" style="gap:12px">
                <div class="field"><label>Minimum online notice (hours)</label>
                    <input name="min_lead_hours" type="number" min="0" max="168" value="{{ old('min_lead_hours', $s['min_lead_hours']) }}" required>
                    <span class="hint">Online bookings must be at least this far ahead; anything sooner is office-only.</span>
                </div>
                <div class="field"><label>Estate uplift over Executive (£)</label>
                    <input name="estate_uplift" type="number" step="0.01" min="0" value="{{ old('estate_uplift', $s['estate_uplift']) }}" required>
                    <span class="hint">Estate fares = Executive + this amount.</span>
                </div>
                <div class="field"><label>Review request delay (minutes after completion)</label>
                    <input name="review_delay_minutes" type="number" min="0" value="{{ old('review_delay_minutes', $s['review_delay_minutes']) }}" required>
                </div>
                <div class="field"><label>Driver pay (% of fare)</label>
                    <input name="driver_pay_percent" type="number" step="0.01" min="0" max="100" value="{{ old('driver_pay_percent', $s['driver_pay_percent']) }}" required>
                    <span class="hint">Default share pre-filled on jobs (company keeps the rest).</span>
                </div>
            </div>
        </div>

        <div class="card">
            <h2 style="margin:0 0 10px">VAT</h2>
            <label class="cet-agree" style="margin-bottom:10px"><input type="checkbox" name="vat_registered" value="1" {{ old('vat_registered', $s['vat_registered']) ? 'checked' : '' }}> VAT registered (add VAT on business-invoice bookings)</label>
            <div class="field" style="max-width:220px"><label>VAT rate (%)</label>
                <input name="vat_rate_percent" type="number" step="0.01" min="0" max="100" value="{{ old('vat_rate_percent', $s['vat_rate_percent']) }}" required>
            </div>
        </div>

        <div class="card">
            <h2 style="margin:0 0 10px">Policies &amp; contact</h2>
            <div class="grid grid-2" style="gap:12px">
                <div class="field"><label>Terms &amp; Conditions URL</label><input name="terms_url" type="url" value="{{ old('terms_url', $s['terms_url']) }}"></div>
                <div class="field"><label>Privacy Policy URL</label><input name="privacy_url" type="url" value="{{ old('privacy_url', $s['privacy_url']) }}"></div>
                <div class="field"><label>Cancellation Policy URL</label><input name="cancellation_url" type="url" value="{{ old('cancellation_url', $s['cancellation_url']) }}"></div>
                <div class="field"><label>Office email (new-booking alerts)</label><input name="ops_email" type="email" value="{{ old('ops_email', $s['ops_email']) }}"></div>
            </div>
        </div>

        <button class="btn btn-primary" style="padding:10px 20px">Save settings</button>
    </form>
@endsection
