<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Book a chauffeur · Central Executive Transfers</title>
    <style>
        :root{
            --gold:#FBBA2A; --ink:#0b0b0b; --paper:#eceae4; --card:#fff;
            --line:#e4e1d8; --muted:#6c6c6c; --ok:#1f7a44;
            --shadow:0 18px 50px rgba(0,0,0,.10); --shadow-sm:0 4px 16px rgba(0,0,0,.06);
        }
        *{box-sizing:border-box}
        html,body{margin:0}
        body{font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
            color:var(--ink);background:var(--paper);line-height:1.5;-webkit-font-smoothing:antialiased}
        a{color:inherit}
        .wrap{max-width:1000px;margin:0 auto;padding:0 18px}

        header.top{background:var(--ink);color:#fff;padding:15px 0;position:sticky;top:0;z-index:20}
        .brand{display:flex;align-items:center;gap:11px}
        .brand .mark{font-weight:800;letter-spacing:.04em;line-height:1}
        .brand .mark b{display:block;font-size:17px}
        .brand .mark span{display:block;font-size:10px;letter-spacing:.22em;color:var(--gold);margin-top:2px}
        .brand .dot{width:10px;height:10px;border-radius:50%;background:var(--gold)}
        .brand .cta{margin-left:auto;background:var(--gold);color:#111;font-weight:800;font-size:13px;padding:9px 16px;border-radius:8px;text-decoration:none}

        .hero{padding:34px 0 8px;text-align:center}
        .hero h1{font-size:clamp(26px,4.2vw,40px);line-height:1.08;margin:0 0 8px;letter-spacing:-.02em}
        .hero h1 em{font-style:normal;color:var(--gold)}
        .hero p{margin:0 auto;color:var(--muted);max-width:52ch;font-size:15.5px}

        /* Stepper */
        .steps{display:flex;gap:6px;max-width:760px;margin:22px auto 0;padding:0 4px}
        .steps .s{flex:1;display:flex;align-items:center;gap:9px;background:#fff;border:1px solid var(--line);
            border-radius:10px;padding:11px 12px;font-size:12.5px;font-weight:700;color:var(--muted)}
        .steps .s .n{display:inline-grid;place-items:center;width:22px;height:22px;border-radius:50%;
            background:#efece3;color:var(--muted);font-size:12px;flex:none}
        .steps .s.on{border-color:var(--gold);color:var(--ink);box-shadow:var(--shadow-sm)}
        .steps .s.on .n{background:var(--ink);color:#fff}
        .steps .s.done .n{background:var(--gold);color:#111}
        @media(max-width:560px){.steps .s span{display:none}.steps .s{justify-content:center}}

        .panel{background:var(--card);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow);
            padding:22px;margin:16px auto 40px;max-width:820px}
        .sec-title{display:flex;align-items:center;gap:9px;font-size:13px;font-weight:800;letter-spacing:.12em;
            text-transform:uppercase;color:var(--muted);margin:2px 0 14px}
        .sec-title .n{display:inline-grid;place-items:center;width:22px;height:22px;border-radius:50%;background:var(--ink);color:#fff;font-size:12px}
        .grid{display:grid;gap:14px}
        @media(min-width:640px){.grid.two{grid-template-columns:1fr 1fr}.grid.three{grid-template-columns:2fr 1fr 1fr}}
        .addr-row{display:grid;gap:14px}
        @media(min-width:640px){.addr-row{grid-template-columns:2.4fr 1fr}}
        label.f{display:block;font-size:13px;font-weight:700;margin:0 0 6px}
        .req{color:var(--gold)}
        input,select,textarea{width:100%;padding:12px 13px;border:1px solid var(--line);border-radius:11px;font:inherit;color:var(--ink);background:#fff}
        input:focus,select:focus,textarea:focus{outline:2px solid var(--gold);border-color:var(--gold)}
        textarea{min-height:70px;resize:vertical}
        .hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
        .note{color:var(--muted);font-size:13px;margin-top:10px}

        .btn{appearance:none;border:0;cursor:pointer;font:inherit;font-weight:800;border-radius:12px;padding:14px 18px;text-decoration:none;display:inline-block;text-align:center}
        .btn.gold{background:var(--gold);color:#111;width:100%;font-size:16px}
        .btn.gold:disabled{background:#efe7cf;color:#a99a6a;cursor:not-allowed}
        .btn.dark{background:var(--ink);color:#fff}
        .btn.ghost{background:#fff;border:1px solid var(--ink);color:var(--ink)}

        /* Vehicle cards */
        .cars{display:grid;gap:14px}
        .vcard{position:relative;display:grid;grid-template-columns:150px 1fr auto;align-items:center;gap:16px;
            border:1.5px solid var(--line);border-radius:15px;padding:14px 16px;cursor:pointer;background:#fff;transition:border-color .12s,box-shadow .12s}
        .vcard:hover{border-color:#d8ca9a;box-shadow:var(--shadow-sm)}
        .vcard input{position:absolute;opacity:0;pointer-events:none}
        .vcard.sel{border-color:var(--gold);box-shadow:0 0 0 3px rgba(251,186,42,.18)}
        .vcard .photo{width:150px;height:86px;display:grid;place-items:center;background:linear-gradient(180deg,#fafafa,#f0eee8);border-radius:10px;overflow:hidden}
        .vcard .photo img{width:100%;height:100%;object-fit:contain}
        .vcard .photo svg{width:132px;height:auto;opacity:.9}
        .vcard .nm{font-weight:800;font-size:16px;letter-spacing:.01em}
        .vcard .tag{color:var(--muted);font-size:12.5px;font-weight:600;margin-top:1px}
        .vcard .caps{display:flex;flex-wrap:wrap;gap:10px 14px;margin-top:9px;color:#444;font-size:12.5px}
        .vcard .caps b{font-weight:700}
        .vcard .price{text-align:right;min-width:96px}
        .vcard .pr{font-size:23px;font-weight:800;letter-spacing:-.01em;white-space:nowrap}
        .vcard .pr small{display:block;font-size:11px;font-weight:600;color:var(--muted);letter-spacing:.02em}
        .vcard .sel-btn{margin-top:6px;font-size:12px;font-weight:800;color:#111;background:#f3efe2;border-radius:7px;padding:5px 10px;display:inline-block}
        .vcard.sel .sel-btn{background:var(--gold)}
        .vcard.poa .pr{font-size:15px;color:var(--muted);white-space:normal}
        @media(max-width:600px){
            .vcard{grid-template-columns:110px 1fr;grid-template-areas:"photo body" "price price";row-gap:10px}
            .vcard .photo{grid-area:photo;width:110px;height:66px}.vcard .photo svg{width:98px}
            .vcard .body{grid-area:body}.vcard .price{grid-area:price;text-align:left;display:flex;align-items:center;gap:14px}
            .vcard .sel-btn{margin-top:0}
        }

        .extras{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(210px,1fr))}
        .xcheck{display:flex;align-items:center;gap:10px;border:1px solid var(--line);border-radius:11px;padding:11px 13px;cursor:pointer;background:#fff;font-size:14px}
        .xcheck input{width:17px;height:17px;flex:none}
        .xcheck b{font-weight:800}
        .vatrow{display:flex;gap:11px;align-items:flex-start;margin-top:2px;padding:13px 14px;border:1px dashed var(--line);border-radius:12px;background:#fbfaf7}
        .vatrow input{width:18px;height:18px;margin-top:2px;flex:none}
        .vatrow .t{font-size:13.5px;color:var(--muted)}.vatrow .t b{color:var(--ink)}
        .summary{display:flex;justify-content:space-between;align-items:baseline;gap:14px;margin:16px 0 4px;padding-top:14px;border-top:1px solid var(--line)}
        .summary .lab{color:var(--muted);font-size:13.5px}
        .summary .amt{font-size:26px;font-weight:800;letter-spacing:-.01em}
        .fine{color:var(--muted);font-size:12.5px;margin:10px 0 0}
        .hidden{display:none}
        .errs{background:#fdecec;border:1px solid #f3b7b7;color:#8f1f1f;border-radius:12px;padding:12px 14px;margin:0 auto 16px;max-width:820px;font-size:14px}
        .errs ul{margin:6px 0 0;padding-left:18px}
        footer{color:var(--muted);font-size:12.5px;text-align:center;padding:22px 0 40px}
        footer b{color:var(--ink)}
    </style>
</head>
<body>
@php
    // Reusable car silhouettes — saloon + van — used when a class has no photo yet.
    $saloon = '<svg viewBox="0 0 240 100" fill="#0b0b0b" xmlns="http://www.w3.org/2000/svg"><path d="M12 74h216c4 0 7-3 7-7v-9c0-6-4-11-10-12l-38-7-30-21c-6-4-13-6-20-6H86c-9 0-17 4-23 11L44 40l-27 6C9 47 3 54 3 62v5c0 4 4 7 9 7z"/><circle cx="66" cy="76" r="15" fill="#0b0b0b"/><circle cx="66" cy="76" r="7" fill="#fff"/><circle cx="182" cy="76" r="15" fill="#0b0b0b"/><circle cx="182" cy="76" r="7" fill="#fff"/><path d="M92 20h44c5 0 10 2 14 5l16 12H70l14-13c2-2 5-4 8-4z" fill="#cfcfcf"/></svg>';
    $van = '<svg viewBox="0 0 240 110" fill="#0b0b0b" xmlns="http://www.w3.org/2000/svg"><path d="M10 78h222c3 0 5-2 5-5V44c0-8-6-15-14-16l-40-6-26-14c-5-3-10-4-16-4H40c-8 0-15 6-16 14L18 46 12 48c-6 2-9 7-9 13v12c0 3 2 5 7 5z"/><circle cx="66" cy="80" r="15"/><circle cx="66" cy="80" r="7" fill="#fff"/><circle cx="184" cy="80" r="15"/><circle cx="184" cy="80" r="7" fill="#fff"/><path d="M42 18h96c5 0 9 3 11 7l7 15H33l4-15c1-4 3-7 5-7z" fill="#cfcfcf"/></svg>';
@endphp

<header class="top">
    <div class="wrap brand">
        <span class="dot"></span>
        <span class="mark"><b>CENTRAL</b><span>EXECUTIVE TRANSFERS</span></span>
        <a class="cta" href="#bookForm">Book Now</a>
    </div>
</header>

<div class="wrap">
    <section class="hero">
        <h1>Book your <em>executive</em> journey</h1>
        <p>Search, choose your vehicle &amp; price, then complete your details &amp; payment — all in one flow.</p>
        <div class="steps" id="steps">
            <div class="s on" data-step="1"><span class="n">1</span><span>Itinerary</span></div>
            <div class="s" data-step="2"><span class="n">2</span><span>Quote</span></div>
            <div class="s" data-step="3"><span class="n">3</span><span>Confirmation</span></div>
        </div>
    </section>

    @if ($errors->any())
        <div class="errs"><strong>Please check the form:</strong>
            <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('public.book.store') }}" class="panel" id="bookForm">
        @csrf
        <div class="hp"><label>Company<input type="text" name="company" tabindex="-1" autocomplete="off"></label></div>

        {{-- Step 1 — itinerary --}}
        <div class="sec-title"><span class="n">1</span> Your journey</div>
        <div class="addr-row">
            <div><label class="f">Pick-up address <span class="req">*</span></label>
                <input name="pickup_address" id="pickup" value="{{ old('pickup_address') }}" placeholder="House / building and street" required></div>
            <div><label class="f">Pick-up postcode <span class="req">*</span></label>
                <input name="pickup_postcode" id="pickup_postcode" value="{{ old('pickup_postcode') }}" placeholder="e.g. S10 4BL" style="text-transform:uppercase" autocomplete="postal-code" required></div>
        </div>
        <div class="addr-row" style="margin-top:14px">
            <div><label class="f">Drop-off address <span class="req">*</span></label>
                <input name="destination_address" id="destination" value="{{ old('destination_address') }}" placeholder="e.g. Manchester Airport (MAN)" required></div>
            <div><label class="f">Drop-off postcode</label>
                <input name="destination_postcode" id="destination_postcode" value="{{ old('destination_postcode') }}" placeholder="If known" style="text-transform:uppercase" autocomplete="postal-code"></div>
        </div>
        <div class="grid three" style="margin-top:14px">
            <div><label class="f">Date &amp; time <span class="req">*</span></label>
                <input type="datetime-local" name="pickup_at" id="pickup_at" value="{{ old('pickup_at') }}" required></div>
            <div><label class="f">Passengers <span class="req">*</span></label>
                <input type="number" name="passengers" min="1" max="60" value="{{ old('passengers', 1) }}" required></div>
            <div><label class="f">Flight no. <small style="color:var(--muted)">(optional)</small></label>
                <input name="flight_number" value="{{ old('flight_number') }}" placeholder="e.g. BA222"></div>
        </div>
        <div class="grid two" style="margin-top:14px">
            <div><label class="f">Suitcases</label><input type="number" name="suitcases" min="0" max="30" value="{{ old('suitcases', 0) }}"></div>
            <div><label class="f">Hand luggage</label><input type="number" name="hand_luggage" min="0" max="30" value="{{ old('hand_luggage', 0) }}"></div>
        </div>
        <div style="margin-top:16px">
            <button type="button" class="btn gold" id="getPrices">See prices &amp; vehicles →</button>
            <div class="note" id="quoteNote"></div>
        </div>

        {{-- Step 2 — vehicle --}}
        <div id="step2" class="hidden" style="margin-top:26px">
            <div class="sec-title"><span class="n">2</span> Choose your vehicle</div>
            <div class="cars" id="cars">
                @foreach ($vehicleTypes as $vt)
                    <label class="vcard {{ $vt->isQuoteOnly() ? 'poa' : '' }}" data-id="{{ $vt->id }}">
                        <input type="radio" name="vehicle_type_id" value="{{ $vt->id }}" {{ old('vehicle_type_id') == $vt->id ? 'checked' : '' }}>
                        <div class="photo">
                            @if($vt->photoUrl())<img src="{{ $vt->photoUrl() }}" alt="{{ $vt->name }}">
                            @else {!! $vt->isLargeVehicle() ? $van : $saloon !!}@endif
                        </div>
                        <div class="body">
                            <div class="nm">{{ $vt->name }}</div>
                            @if($vt->tagline())<div class="tag">{{ $vt->tagline() }}</div>@endif
                            <div class="caps">
                                <span>👤 <b>{{ $vt->passenger_capacity }}</b> max</span>
                                <span>🧳 <b>{{ $vt->luggage_capacity }}</b> cases</span>
                            </div>
                        </div>
                        <div class="price">
                            <div class="pr" data-price>—</div>
                            <span class="sel-btn">{{ $vt->isQuoteOnly() ? 'Request quote' : 'Select' }} →</span>
                        </div>
                    </label>
                @endforeach
            </div>
        </div>

        {{-- Step 3 — details + pay --}}
        <div id="step3" class="hidden" style="margin-top:26px">
            <div class="sec-title"><span class="n">3</span> Your details</div>
            <div class="grid two">
                <div><label class="f">Full name <span class="req">*</span></label><input name="customer_name" value="{{ old('customer_name') }}" required></div>
                <div><label class="f">Mobile <span class="req">*</span></label><input name="customer_phone" value="{{ old('customer_phone') }}" placeholder="07…" required></div>
            </div>
            <div style="margin-top:14px"><label class="f">Email <span class="req">*</span></label>
                <input type="email" name="customer_email" value="{{ old('customer_email') }}" placeholder="For your confirmation &amp; receipt" required></div>
            <div style="margin-top:14px"><label class="f">Notes for your driver <small style="color:var(--muted)">(optional)</small></label>
                <textarea name="notes" placeholder="Anything we should know">{{ old('notes') }}</textarea></div>

            <div class="sec-title" style="margin-top:22px"><span class="n">+</span> Extras <small style="text-transform:none;letter-spacing:0;font-weight:600;color:var(--muted)">(optional)</small></div>
            @php $sc = $surcharges; @endphp
            <div class="extras">
                <label class="xcheck"><input type="checkbox" name="meet_greet" value="1" data-extra="{{ $sc['meet_greet'] ?? 0 }}" {{ old('meet_greet') ? 'checked' : '' }}><span>Meet &amp; greet <b>£{{ number_format($sc['meet_greet'] ?? 0, 0) }}</b></span></label>
                <label class="xcheck"><input type="checkbox" name="ribbons" value="1" data-extra="{{ $sc['ribbons_car'] ?? 0 }}" data-extra-minibus="{{ $sc['ribbons_minibus'] ?? 0 }}" {{ old('ribbons') ? 'checked' : '' }}><span>Wedding ribbons <b>from £{{ number_format($sc['ribbons_car'] ?? 0, 0) }}</b></span></label>
                <label class="xcheck"><input type="checkbox" name="wheelchair" value="1" data-extra="{{ $sc['wheelchair'] ?? 0 }}" {{ old('wheelchair') ? 'checked' : '' }}><span>Wheelchair accessible {!! ($sc['wheelchair'] ?? 0) > 0 ? '<b>£'.number_format($sc['wheelchair'],0).'</b>' : '<b>free</b>' !!}</span></label>
            </div>
            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin-top:12px">
                @foreach ([
                    'child_seats' => ['Child seats', $sc['child_seat'] ?? 0],
                    'booster_seats' => ['Booster seats', $sc['booster_seat'] ?? 0],
                    'infant_seats' => ['Infant seats', $sc['infant_seat'] ?? 0],
                    'stopovers' => ['Extra stops', $sc['stopover'] ?? 0],
                ] as $field => [$label, $unit])
                    <div><label class="f">{{ $label }} <small style="color:var(--muted)">£{{ number_format($unit, 0) }} ea</small></label>
                        <input type="number" name="{{ $field }}" min="0" max="20" value="{{ old($field, 0) }}" data-extra="{{ $unit }}"></div>
                @endforeach
            </div>

            <div style="margin-top:16px"><label class="f">Voucher code <small style="color:var(--muted)">(optional)</small></label>
                <input name="voucher" value="{{ old('voucher') }}" placeholder="e.g. RACHEL20" style="text-transform:uppercase;max-width:240px">
                <div class="note" style="margin-top:6px">Any discount is applied when you pay.</div></div>

            <label class="vatrow" style="margin-top:14px">
                <input type="checkbox" name="vat_invoice" value="1" id="vatInvoice" {{ old('vat_invoice') ? 'checked' : '' }}>
                <span class="t"><b>I need a VAT invoice (business).</b> {{ $vatPercent }}% VAT is added on top and you’ll receive a VAT invoice to reclaim it. Leave unticked for a standard booking (price shown is all-inclusive).</span>
            </label>

            <div class="summary"><span class="lab" id="sumLab">Total to pay today</span><span class="amt" id="sumAmt">—</span></div>
            <div style="margin-top:14px"><button type="submit" class="btn gold" id="payBtn" disabled>Pay &amp; confirm booking</button></div>
            <p class="fine" id="payFine">
                @if($payEnabled)Secure card payment by Square. Your booking is confirmed the moment payment succeeds.
                @else Online payment isn’t available right now — send it and the office will confirm your price and take payment.@endif
            </p>
        </div>
    </form>
</div>

<footer class="wrap"><b>Central Executive Transfers Ltd</b> · Operator Licence OP037 · 07405 172435 · centralexecutivetransfers.co.uk</footer>

<script>
(function(){
    var token=document.querySelector('meta[name=csrf-token]').content;
    var vatPercent={{ $vatPercent }}, payEnabled={{ $payEnabled ? 'true' : 'false' }};
    var prices={};
    var step2=document.getElementById('step2'), step3=document.getElementById('step3'),
        cars=document.getElementById('cars'), note=document.getElementById('quoteNote'),
        payBtn=document.getElementById('payBtn'), sumAmt=document.getElementById('sumAmt'),
        sumLab=document.getElementById('sumLab'), vatBox=document.getElementById('vatInvoice');
    function p(n){return (n<10?'0':'')+n;}
    function money(n){return '£'+Number(n).toLocaleString('en-GB',{minimumFractionDigits:0,maximumFractionDigits:2});}
    function setStep(n){document.querySelectorAll('#steps .s').forEach(function(s){var d=+s.getAttribute('data-step');
        s.classList.toggle('on',d===n);s.classList.toggle('done',d<n);});}

    var pt=document.getElementById('pickup_at');
    if(!pt.value){var d=new Date(Date.now()+2*3600*1000);d.setMinutes(Math.ceil(d.getMinutes()/15)*15,0,0);
        pt.value=d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+'T'+p(d.getHours())+':'+p(d.getMinutes());}

    document.getElementById('getPrices').addEventListener('click',function(){
        var pickup=document.getElementById('pickup').value.trim(), dest=document.getElementById('destination').value.trim();
        var pcode=document.getElementById('pickup_postcode').value.trim(), dcode=document.getElementById('destination_postcode').value.trim();
        if(!pickup||!dest){note.textContent='Enter a pick-up and drop-off first.';return;}
        if(!pcode){note.textContent='Please add your pick-up postcode for an accurate price.';document.getElementById('pickup_postcode').focus();return;}
        this.disabled=true;note.textContent='Getting your prices…';
        fetch('{{ route('public.book.quotes') }}',{method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-TOKEN':token,'Accept':'application/json'},
            body:JSON.stringify({pickup:pickup,destination:dest,pickup_postcode:pcode,destination_postcode:dcode,pickup_at:pt.value})
        }).then(function(r){return r.json();}).then(function(data){
            (data.options||[]).forEach(function(o){
                prices[o.id]=o.price;
                var card=cars.querySelector('.vcard[data-id="'+o.id+'"]'); if(!card) return;
                var el=card.querySelector('[data-price]'); card.classList.toggle('poa',!!o.poa);
                el.innerHTML=o.poa?'On request':money(o.price)+'<small>'+(o.fixed?'fixed price':'inc. VAT')+'</small>';
            });
            step2.classList.remove('hidden');step3.classList.remove('hidden');setStep(2);
            note.textContent=data.surcharge?(data.surcharge.label+' rate applies to this date. Prices include VAT — choose a vehicle.'):'Prices include VAT. Choose a vehicle to continue.';
            step2.scrollIntoView({behavior:'smooth',block:'start'});refreshTotal();
        }).catch(function(){note.textContent='Sorry, we couldn’t price that just now. Please try again.';})
          .finally((function(b){return function(){b.disabled=false;};})(this));
    });

    cars.addEventListener('change',function(e){
        if(e.target.name!=='vehicle_type_id') return;
        cars.querySelectorAll('.vcard').forEach(function(c){c.classList.remove('sel');});
        e.target.closest('.vcard').classList.add('sel');setStep(3);refreshTotal();
    });
    vatBox.addEventListener('change',refreshTotal);
    document.getElementById('bookForm').addEventListener('input',function(e){
        if(e.target.hasAttribute&&e.target.hasAttribute('data-extra')) refreshTotal();});

    function extrasTotal(){
        var sel=cars.querySelector('input[name=vehicle_type_id]:checked'), isMini=false;
        if(sel){var nm=(sel.closest('.vcard').querySelector('.nm').textContent||'').toLowerCase();
            isMini=nm.indexOf('seater')>-1||nm.indexOf('v class')>-1||nm.indexOf('minibus')>-1;}
        var sum=0;
        document.querySelectorAll('[data-extra]').forEach(function(el){
            var unit=parseFloat(el.getAttribute('data-extra'))||0;
            if(el.type==='checkbox'){if(el.checked){if(el.name==='ribbons'&&isMini){unit=parseFloat(el.getAttribute('data-extra-minibus'))||unit;}sum+=unit;}}
            else{sum+=(parseInt(el.value,10)||0)*unit;}});
        return sum;
    }
    function refreshTotal(){
        var sel=cars.querySelector('input[name=vehicle_type_id]:checked');
        if(!sel){payBtn.disabled=true;sumAmt.textContent='—';return;}
        var base=prices[sel.value];
        if(base==null){sumLab.textContent='Price';sumAmt.textContent='On request';
            payBtn.disabled=false;payBtn.textContent=payEnabled?'Send enquiry':'Send booking request';return;}
        var total=base+extrasTotal(); if(vatBox.checked) total=Math.round(total*(1+vatPercent/100)*100)/100;
        sumLab.textContent=payEnabled?'Total to pay today':'Estimated total';
        sumAmt.textContent=money(total)+(vatBox.checked?' inc. VAT':'');
        payBtn.disabled=false;payBtn.textContent=payEnabled?('Pay '+money(total)+' & confirm'):'Send booking request';
    }
    refreshTotal();
})();
</script>
</body>
</html>
