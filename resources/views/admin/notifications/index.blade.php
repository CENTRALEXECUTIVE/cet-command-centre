@extends('layouts.app')
@section('title', 'Notifications')

@section('content')
    <div class="form-hero">
        <div class="form-hero-glow"></div>
        <div class="fh-eyebrow">Fleet &amp; admin · alerts</div>
        <div class="fh-title">Notification preferences</div>
        <div class="fh-sub">Which watchdog alerts buzz each admin's phone. Push must be enabled on the device (the 🔔 banner on My jobs / the dashboard).</div>
    </div>

    <form method="POST" action="{{ route('notifications.update') }}">
        @csrf
        @method('PUT')

        @foreach($admins as $admin)
            @php $p = $admin->alertPreferences(); @endphp
            <div class="card">
                <h2 style="margin:0 0 4px">{{ $admin->name }} @if($admin->is_super_admin)<span class="badge" style="background:#0b0b0b;color:#FBBA2A">Super admin</span>@endif</h2>
                <p class="hint" style="margin:0 0 12px">{{ $admin->email }}</p>

                <div class="grid grid-2">
                    @foreach($types as $key => $label)
                        <div class="checkbox-row">
                            <input id="p{{ $admin->id }}_{{ $key }}" type="checkbox"
                                   name="prefs[{{ $admin->id }}][{{ $key }}]" value="1" @checked($p[$key])>
                            <label for="p{{ $admin->id }}_{{ $key }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </div>

                <div style="border-top:1px solid var(--line);margin-top:12px;padding-top:12px" class="grid grid-2">
                    <div class="checkbox-row">
                        <input id="p{{ $admin->id }}_critical_only" type="checkbox"
                               name="prefs[{{ $admin->id }}][critical_only]" value="1" @checked($p['critical_only'])>
                        <label for="p{{ $admin->id }}_critical_only"><strong>Critical only</strong> — mute everything except 🔴 alerts</label>
                    </div>
                    <div class="checkbox-row">
                        <input id="p{{ $admin->id }}_chime" type="checkbox"
                               name="prefs[{{ $admin->id }}][chime]" value="1" @checked($p['chime'])>
                        <label for="p{{ $admin->id }}_chime">Soft chime on new critical alerts (dashboard open)</label>
                    </div>
                </div>
            </div>
        @endforeach

        <button type="submit" class="btn btn-primary">Save preferences</button>
    </form>
@endsection
