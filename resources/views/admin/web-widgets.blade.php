@extends('layouts.app')
@section('title', 'Web Widgets')

@section('content')
    <h1 class="page-title">Web Widgets</h1>
    <p class="page-sub">Drop these into your website to take bookings — like ETO's widgets, but on your own pricing. The live site isn't touched: you paste a snippet, it embeds a page from the Command Centre.</p>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    @php
        $snippet = function (string $url, string $id, int $height) {
            return '<iframe src="'.$url.'" id="'.$id.'" width="100%" height="'.$height.'" frameborder="0"'."\n"
                .'        style="width:1px;min-width:100%;border:0" allow="geolocation"></iframe>'."\n"
                .'<script>window.addEventListener("message",function(e){if(e.data&&e.data.cetWidgetHeight){'."\n"
                .'  document.getElementById("'.$id.'").style.height=e.data.cetWidgetHeight+"px";}});</script>';
        };
        $widgets = [
            ['Mini price check', 'A quick From/To price checker — great on the home page.', $urls['mini'], 'cet-quote', 380],
            ['Full booking', 'The complete booking form (with online payment when a fixed price is known).', $urls['book'], 'cet-book', 680],
            ['Customer account', 'Customers look up & manage their own bookings.', $urls['account'], 'cet-account', 520],
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
