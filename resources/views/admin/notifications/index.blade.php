@extends('layouts.app')
@section('title', 'Notifications')

@section('content')
    <div class="form-hero">
        <div class="form-hero-glow"></div>
        <div class="fh-eyebrow">Fleet &amp; admin · alerts</div>
        <div class="fh-title">Notification preferences</div>
        <div class="fh-sub">Which watchdog alerts buzz each admin's phone. Push must be enabled on the device (the 🔔 banner on My jobs / the dashboard).</div>
    </div>

    {{-- Set up push on THIS phone/computer, then prove it with a test. The
         opt-in reuses the driver push endpoints (admins are allowed on them);
         subscriptions store against whoever is signed in. --}}
    <div class="card" id="push-device"
         data-key-url="{{ route('driver.push.key') }}"
         data-sub-url="{{ route('driver.push.subscribe') }}"
         data-test-url="{{ route('driver.push.test') }}">
        <h2 style="margin:0 0 4px">🔔 Alerts on this device</h2>
        <p class="hint" style="margin:0 0 12px">Turn push on for the phone or computer you're reading this on, then send yourself a test. <strong>On iPhone:</strong> you must first tap Share → <em>Add to Home Screen</em> and open the app from that icon — iOS only allows notifications from the installed app. Android works either way.</p>

        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <button type="button" id="push-enable" class="btn btn-primary" style="padding:9px 16px;font-size:14px">Enable notifications</button>
            <button type="button" id="push-test" class="btn btn-light" style="padding:9px 16px;font-size:14px">Send a test</button>
        </div>
        <div id="push-status" class="hint" style="margin-top:10px;min-height:18px"></div>

        <script src="{{ asset('js/cet-pushsetup.js') }}?v=2" defer></script>
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
