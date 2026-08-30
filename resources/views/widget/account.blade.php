<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>My bookings · Central Executive Transfers</title>
    <style>
        :root { --gold:#FBBA2A; --ink:#111; --line:#e6e6e6; --muted:#666; }
        * { box-sizing:border-box; }
        html,body { margin:0; }
        body { font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:var(--ink); background:transparent; }
        .cet-widget { max-width:600px; margin:0 auto; background:#fff; border:1px solid var(--line); border-radius:14px; padding:18px; }
        .cet-head { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:14px; }
        .cet-brand { font-weight:800; letter-spacing:.5px; font-size:14px; display:flex; align-items:center; gap:8px; }
        .cet-brand .dot { width:10px; height:10px; border-radius:50%; background:var(--gold); }
        .cet-brand span { color:var(--gold); }
        .cet-field { margin-bottom:10px; }
        .cet-field label { display:block; font-size:12px; font-weight:600; color:var(--muted); margin-bottom:4px; }
        .cet-field input, .cet-field select, .cet-field textarea { width:100%; padding:11px 12px; border:1px solid var(--line); border-radius:9px; font-size:15px; font-family:inherit; }
        .cet-btn { width:100%; padding:13px; border:0; border-radius:10px; background:var(--gold); color:#111; font-weight:800; font-size:15px; cursor:pointer; }
        .cet-link { background:none; border:0; color:var(--muted); text-decoration:underline; cursor:pointer; font-size:13px; padding:0; }
        .cet-alert { padding:10px 12px; border-radius:9px; font-size:14px; margin-bottom:12px; }
        .cet-ok { background:#eaf6ef; color:#1f7a44; }
        .cet-bad { background:#fbeaea; color:#b32020; }
        .cet-job { border:1px solid var(--line); border-radius:11px; padding:12px 14px; margin-bottom:10px; }
        .cet-job h3 { margin:0 0 2px; font-size:15px; }
        .cet-badge { display:inline-block; font-size:11px; font-weight:700; padding:2px 8px; border-radius:20px; background:#eee; color:#333; }
        .cet-muted { color:var(--muted); font-size:13px; }
        .cet-row { display:flex; justify-content:space-between; gap:8px; flex-wrap:wrap; align-items:center; }
        details.cet-manage { margin-top:8px; }
        details.cet-manage summary { cursor:pointer; font-size:13px; font-weight:600; color:var(--muted); }
        .cet-foot { text-align:center; color:var(--muted); font-size:11px; margin-top:12px; }
    </style>
</head>
<body>
    <div class="cet-widget" id="cet-widget">
        <div class="cet-head">
            <span class="cet-brand"><span class="dot"></span>CENTRAL <span>EXECUTIVE</span> TRANSFERS</span>
            @if($verified)
                <form action="{{ route('widget.account.logout') }}" method="POST" style="margin:0">@csrf<button class="cet-link">Log out</button></form>
            @endif
        </div>

        @if(session('account_status'))<div class="cet-alert cet-ok">{{ session('account_status') }}</div>@endif
        @if(session('account_error'))<div class="cet-alert cet-bad">{{ session('account_error') }}</div>@endif

        @unless($verified)
            <h2 style="margin:0 0 4px;font-size:18px">Manage my bookings</h2>
            <p class="cet-muted" style="margin:0 0 12px">Enter a booking reference and the phone number or email on it.</p>
            <form action="{{ route('widget.account.verify') }}" method="POST" autocomplete="off">
                @csrf
                <div class="cet-field"><label for="a-ref">Booking reference</label>
                    <input id="a-ref" name="reference" required placeholder="e.g. CET-XXXXXX or your ETO ref"></div>
                <div class="cet-field"><label for="a-contact">Phone or email on the booking</label>
                    <input id="a-contact" name="contact" required placeholder="07… or you@email.com"></div>
                <button class="cet-btn" type="submit">View my bookings</button>
            </form>
            <p class="cet-foot">Don't have a reference? <a href="{{ route('widget.book') }}" target="_top">Make a booking →</a></p>
        @else
            <h2 style="margin:0 0 12px;font-size:18px">My bookings</h2>
            @forelse($bookings as $b)
                @php $upcoming = $b->pickup_at && $b->pickup_at->isFuture() && ! $b->status->isTerminal(); @endphp
                <div class="cet-job">
                    <div class="cet-row">
                        <h3>{{ $b->pickup_at?->format('D d M Y · H:i') }}</h3>
                        <span class="cet-badge">{{ $b->status->label() }}</span>
                    </div>
                    <div class="cet-muted">{{ \Illuminate\Support\Str::limit($b->pickup_address, 34) }} → {{ \Illuminate\Support\Str::limit($b->destination_address, 34) }}</div>
                    <div class="cet-muted" style="margin-top:4px">
                        {{ $b->vehicleType?->name }}
                        @if($b->fareAmount()) · £{{ number_format($b->fareAmount(), 2) }}@endif
                        @if($b->driverPublicName()) · Driver: {{ $b->driverPublicName() }}@endif
                        · Ref {{ $b->reference }}
                    </div>

                    @if($upcoming)
                        <details class="cet-manage">
                            <summary>Change or cancel this booking</summary>
                            <form action="{{ route('widget.account.request', $b) }}" method="POST" style="margin-top:8px">
                                @csrf
                                <div class="cet-field">
                                    <label>What do you need?</label>
                                    <select name="type">
                                        <option value="change">Request a change</option>
                                        <option value="cancel">Request cancellation</option>
                                    </select>
                                </div>
                                <div class="cet-field">
                                    <label>Details (optional)</label>
                                    <textarea name="message" rows="2" placeholder="e.g. move to 3pm, add a stop, cancel please"></textarea>
                                </div>
                                <button class="cet-btn" type="submit" style="font-size:14px;padding:10px">Send request to the office</button>
                            </form>
                        </details>
                    @endif
                </div>
            @empty
                <p class="cet-muted">No bookings found on your record yet.</p>
            @endforelse
            <p class="cet-foot">Need something else? <a href="{{ route('widget.book') }}" target="_top">Make a new booking →</a></p>
        @endunless
    </div>
    <script>
        try { parent.postMessage({ cetWidgetHeight: document.getElementById('cet-widget').offsetHeight + 24 }, '*'); } catch (e) {}
    </script>
</body>
</html>
