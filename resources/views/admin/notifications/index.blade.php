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
    <div class="card">
        <h2 style="margin:0 0 4px">🔔 Alerts on this device</h2>
        <p class="hint" style="margin:0 0 12px">Turn push on for the phone or computer you're reading this on, then send yourself a test. <strong>On iPhone:</strong> tap Share → <em>Add to Home Screen</em>, open the app from that icon, then enable (iOS only allows push from the installed app). Android works either way.</p>

        <div id="push-banner" class="card" style="display:none;border-left:4px solid #FBBA2A;background:rgba(251,186,42,.08);margin-bottom:12px">
            <strong>Turn on office alerts</strong>
            <p class="muted" style="margin:4px 0 10px;font-size:13px">Get buzzed for unallocated jobs, unresponsive drivers and mid-job GPS loss — even with the app closed.</p>
            <button type="button" id="push-enable" class="btn btn-primary" style="padding:8px 16px;font-size:14px">Enable notifications</button>
            <span id="push-state" class="muted" style="font-size:13px;margin-left:8px"></span>
        </div>
        <div id="push-cfg"
             data-key-url="{{ route('driver.push.key') }}"
             data-sub-url="{{ route('driver.push.subscribe') }}"
             data-unsub-url="{{ route('driver.push.unsubscribe') }}" hidden></div>

        <button type="button" id="push-test" class="btn btn-light" style="padding:7px 14px">Send a test notification</button>
        <span id="push-test-state" class="muted" style="font-size:13px;margin-left:8px"></span>

        <script src="{{ asset('js/cet-push.js') }}" defer></script>
        <script>
            (function () {
                var btn = document.getElementById('push-test');
                var state = document.getElementById('push-test-state');
                if (!btn) return;
                var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;
                btn.addEventListener('click', function () {
                    state.style.color = ''; state.textContent = 'sending…';
                    fetch('{{ route('driver.push.test') }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    }).then(function (r) {
                        return r.json().then(function (d) { return { ok: r.ok, d: d }; });
                    }).then(function (res) {
                        if (res.ok && res.d.ok) {
                            state.style.color = '#1f7a44';
                            state.textContent = '✓ Sent to ' + res.d.devices + ' device(s) — check your phone.';
                        } else if (res.d.reason === 'not_configured') {
                            state.style.color = '#b32020';
                            state.textContent = '✗ Push isn’t set up on the server yet (run cet:make-vapid).';
                        } else {
                            state.style.color = '#b32020';
                            state.textContent = '✗ No device subscribed here yet — tap Enable above first.';
                        }
                    }).catch(function () {
                        state.style.color = '#b32020'; state.textContent = '✗ Request failed.';
                    });
                });
            })();
        </script>
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
