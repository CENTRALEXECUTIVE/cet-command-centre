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

        <script src="{{ asset('js/cet-pushsetup.js') }}?v=3" defer></script>
    </div>

    {{-- Test the emergency "job at risk" safety net --}}
    <div class="form-card" style="margin-bottom:18px;border-left:4px solid #b32020">
        <h2 style="margin:0 0 4px">🚨 Test the emergency alert</h2>
        <p class="hint" style="margin:0 0 12px">The safety net for a job about to be missed: a blaring siren on any open dashboard, a push to every director's phone, and an automatic call to the office line that repeats until answered. Test each part here.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <button type="button" id="test-siren" class="btn btn-dark" style="padding:9px 16px;font-size:14px">🔊 Test the siren (this device)</button>
            <form method="POST" action="{{ route('notifications.test') }}" style="display:inline">
                @csrf
                <button type="submit" class="btn btn-primary" style="padding:9px 16px;font-size:14px;background:#b32020;border-color:#b32020">🔔 Fire a full test (siren + push + call)</button>
            </form>
            <form method="POST" action="{{ route('notifications.test-call') }}" style="display:inline">
                @csrf
                <button type="submit" class="btn btn-dark" style="padding:9px 16px;font-size:14px">📞 Test the emergency call only</button>
            </form>
        </div>
        <p class="hint" style="margin:10px 0 0">The full test rings the office line only when Twilio and the alert numbers are set up. The siren button plays the sound right here so you can check it’s loud enough.</p>
    </div>

    <script>
    (function () {
        // Instant in-browser siren test — the same wailing sweep the dashboard uses.
        var btn = document.getElementById('test-siren'); if (!btn) return;
        var ctx = null, timer = null;
        function wail() {
            if (!ctx) { var C = window.AudioContext || window.webkitAudioContext; if (C) ctx = new C(); }
            if (!ctx) return;
            if (ctx.state === 'suspended') ctx.resume();
            var t = ctx.currentTime, dur = 0.75, o = ctx.createOscillator(), g = ctx.createGain();
            o.type = 'sawtooth';
            o.frequency.setValueAtTime(700, t);
            o.frequency.linearRampToValueAtTime(1300, t + dur / 2);
            o.frequency.linearRampToValueAtTime(700, t + dur);
            g.gain.setValueAtTime(1.0, t);
            o.connect(g); g.connect(ctx.destination); o.start(t); o.stop(t + dur);
            if (navigator.vibrate) navigator.vibrate([600, 100, 600]);
        }
        btn.addEventListener('click', function () {
            if (timer) { clearInterval(timer); timer = null; btn.textContent = '🔊 Test the siren (this device)'; return; }
            wail(); timer = setInterval(wail, 760);
            btn.textContent = '⏹ Stop siren';
            setTimeout(function () { if (timer) { clearInterval(timer); timer = null; btn.textContent = '🔊 Test the siren (this device)'; } }, 8000);
        });
    })();
    </script>

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
                    <div class="checkbox-row">
                        <input id="p{{ $admin->id }}_alarm" type="checkbox"
                               name="prefs[{{ $admin->id }}][alarm]" value="1" @checked($p['alarm'] ?? false)>
                        <label for="p{{ $admin->id }}_alarm"><strong>🚨 Alarm</strong> — loud repeating siren + buzz on 🔴 critical alerts, until you acknowledge or silence it (dashboard open)</label>
                    </div>
                </div>
            </div>
        @endforeach

        <button type="submit" class="btn btn-primary">Save preferences</button>
    </form>
@endsection
