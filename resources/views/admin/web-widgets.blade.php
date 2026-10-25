@extends('layouts.app')
@section('title', 'Web Widgets')

@section('content')
    <h1 class="page-title">Web Widgets</h1>
    <p class="page-sub">Drop these into your website to take bookings — like ETO's widgets, but on your own pricing. The live site isn't touched: you paste a snippet, it embeds a page from the Command Centre.</p>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    <div class="card" style="margin-bottom:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <strong style="margin-right:4px">See them live:</strong>
        <a href="{{ $urls['book'] }}" target="_blank" rel="noopener" class="btn btn-primary" style="padding:8px 16px">🌐 Open booking page ↗</a>
        <a href="{{ $urls['mini'] }}" target="_blank" rel="noopener" class="btn btn-dark" style="padding:8px 16px">💷 Open price checker ↗</a>
        <a href="{{ $urls['account'] }}" target="_blank" rel="noopener" class="btn btn-dark" style="padding:8px 16px">👤 Open customer account ↗</a>
        <a href="{{ $urls['openAccount'] }}" target="_blank" rel="noopener" class="btn btn-dark" style="padding:8px 16px">🏢 Open an account (sign-up) ↗</a>
    </div>

    @php
        // The iframe uses data-src so the script can forward this page's Google Ads
        // / UTM params (gclid, utm_*) INTO the widget before it loads — that's how a
        // booking is traced back to the ad that won it. Also auto-sizes the height.
        $snippet = function (string $url, string $id, int $height) {
            return '<iframe id="'.$id.'" data-src="'.$url.'" width="100%" height="'.$height.'" frameborder="0"'."\n"
                .'        style="width:1px;min-width:100%;border:0" allow="geolocation"></iframe>'."\n"
                .'<script>(function(){var f=document.getElementById("'.$id.'"),s=f.getAttribute("data-src");'."\n"
                .'  try{var q=window.location.search;if(q&&q.length>1){s+=(s.indexOf("?")>-1?"&":"?")+q.slice(1);}}catch(e){}'."\n"
                .'  f.src=s;window.addEventListener("message",function(e){if(e.data&&e.data.cetWidgetHeight){'."\n"
                .'    f.style.height=e.data.cetWidgetHeight+"px";}});})();</script>';
        };
        $adsSnippet = '<!-- Google Ads conversion tracking for the CET booking widget -->'."\n"
            .'<!-- Replace AW-XXXXXXXXX (your Conversion ID) and YOUR_LABEL (your Conversion label) -->'."\n"
            .'<script async src="https://www.googletagmanager.com/gtag/js?id=AW-XXXXXXXXX"></script>'."\n"
            .'<script>'."\n"
            .'  window.dataLayer = window.dataLayer || [];'."\n"
            .'  function gtag(){dataLayer.push(arguments);}'."\n"
            .'  gtag("js", new Date());'."\n"
            .'  gtag("config", "AW-XXXXXXXXX");'."\n"
            .'  window.addEventListener("message", function (e) {'."\n"
            .'    var b = e.data && e.data.cetBooking; if (!b) return;'."\n"
            .'    gtag("event", "conversion", {'."\n"
            .'      send_to: "AW-XXXXXXXXX/YOUR_LABEL",'."\n"
            .'      value: b.value || 0, currency: b.currency || "GBP", transaction_id: b.ref || ""'."\n"
            .'    });'."\n"
            .'  });'."\n"
            .'</script>';
        $widgets = [
            ['Mini price check', 'A quick From/To price checker — great on the home page.', $urls['mini'], 'cet-quote', 380],
            ['Full booking', 'The complete booking form (with online payment when a fixed price is known).', $urls['book'], 'cet-book', 680],
            ['Customer account', 'Customers look up & manage their own bookings.', $urls['account'], 'cet-account', 520],
            ['Open an account', 'New customers sign up — personal, or a business/invoice account for your review.', $urls['openAccount'], 'cet-open-account', 720],
        ];
    @endphp

    @foreach($widgets as [$name, $desc, $url, $id, $height])
        <div class="card" style="margin-bottom:14px">
            <h2 style="margin:0 0 2px">{{ $name }}</h2>
            <p class="hint" style="margin:0 0 8px">{{ $desc }} <a href="{{ $url }}" target="_blank" rel="noopener">Preview →</a></p>
            @php $code = $snippet($url, $id, $height); @endphp
            <textarea readonly rows="4" onclick="this.select()" style="width:100%;font-family:ui-monospace,monospace;font-size:12px;border:1px solid var(--line);border-radius:8px;padding:10px">{{ $code }}</textarea>
            <button type="button" class="btn btn-primary copy-snip" data-code="{{ $code }}" style="margin-top:8px;padding:7px 14px;font-size:13px">⧉ Copy snippet</button>
            <span class="copy-done hint" style="color:#1f8b4c;margin-left:8px"></span>
        </div>
    @endforeach

    <div class="card" style="border-left:4px solid #4285F4;margin-bottom:14px">
        <h2 style="margin:0 0 6px">📈 Google Ads conversion tracking</h2>
        <p class="hint" style="margin:0 0 10px">
            Paste this <strong>once</strong> into your website (in the <code>&lt;head&gt;</code>, on every page that can show the
            booking widget). It tells Google Ads when a booking is placed, so you can see which ads and keywords actually win
            jobs. Replace <strong>AW-XXXXXXXXX</strong> with your Conversion ID and <strong>YOUR_LABEL</strong> with the
            Conversion label — both from Google Ads → <em>Goals → Conversions → your "Booking" action → Tag setup</em>.
        </p>
        <ul class="hint" style="margin:0 0 10px;padding-left:18px">
            <li>The widget already forwards the ad click (<code>gclid</code>) into every booking, so each booking is traced back to its ad — and stored for an exact <em>offline conversion import</em> later if you want paid-only revenue.</li>
            <li>This fires when a booking <strong>request is submitted</strong>. Tell me if you'd rather only count <strong>paid</strong> bookings and I'll switch it to offline-import.</li>
        </ul>
        <textarea readonly rows="6" onclick="this.select()" style="width:100%;font-family:ui-monospace,monospace;font-size:12px;border:1px solid var(--line);border-radius:8px;padding:10px">{{ $adsSnippet }}</textarea>
        <button type="button" class="btn btn-primary copy-snip" data-code="{{ $adsSnippet }}" style="margin-top:8px;padding:7px 14px;font-size:13px">⧉ Copy snippet</button>
        <span class="copy-done hint" style="color:#1f8b4c;margin-left:8px"></span>
    </div>

    <div class="card" style="border-left:4px solid #FBBA2A">
        <h2 style="margin:0 0 6px">Automatic customer emails</h2>
        <p class="hint" style="margin:0 0 10px">
            When ON, a customer who books through <strong>your widget</strong> gets an automatic confirmation email.
            <strong>This only ever emails people who book through the widget.</strong> It can never email your existing
            or ETO/website customers — those bookings are a different source and are always skipped.
        </p>
        <form method="POST" action="{{ route('web-widgets.update') }}">
            @csrf
            @method('PUT')
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:15px">
                <input type="checkbox" name="customer_emails" value="1" @checked($emailsOn) style="width:20px;height:20px">
                Send automatic confirmation emails for website-widget bookings
            </label>
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:15px;margin-top:12px">
                <input type="checkbox" name="show_hourly" value="1" @checked($hourlyOn ?? false) style="width:20px;height:20px">
                Offer “Hourly hire (as directed)” as a journey type on the booking form
            </label>
            <button class="btn btn-primary" style="margin-top:12px;padding:8px 16px">Save</button>
            <span class="hint" style="margin-left:10px">Currently <strong>{{ $emailsOn ? 'ON' : 'OFF' }}</strong>.</span>
        </form>
    </div>

    <script>
        document.querySelectorAll('.copy-snip').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var done = btn.parentElement.querySelector('.copy-done');
                var finish = function () { if (done) done.textContent = '✓ Copied'; };
                if (navigator.clipboard) { navigator.clipboard.writeText(btn.dataset.code).then(finish).catch(finish); }
                else { finish(); }
            });
        });
    </script>
@endsection
