<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Book a chauffeur · Central Executive Transfers</title>
    <style>
        :root{
            --gold:#FBBA2A; --ink:#0b0b0b; --paper:#f6f5f2; --card:#fff;
            --line:#e7e4dc; --muted:#6b6b6b; --ok:#1f7a44; --shadow:0 10px 40px rgba(0,0,0,.08);
        }
        *{box-sizing:border-box}
        html,body{margin:0}
        body{font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
            color:var(--ink);background:var(--paper);line-height:1.5;-webkit-font-smoothing:antialiased}
        a{color:inherit}
        .wrap{max-width:960px;margin:0 auto;padding:0 20px}

        header.top{background:var(--ink);color:#fff;padding:16px 0}
        .brand{display:flex;align-items:center;gap:10px;font-weight:800;letter-spacing:.02em}
        .brand .dot{width:11px;height:11px;border-radius:50%;background:var(--gold)}
        .brand b{font-weight:800}.brand span{color:var(--gold)}
        .brand small{font-weight:600;color:#bdbdbd;margin-left:auto;font-size:12.5px;letter-spacing:.16em;text-transform:uppercase}

        .hero{padding:44px 0 26px}
        .hero h1{font-size:clamp(28px,4.4vw,44px);line-height:1.08;margin:0 0 10px;letter-spacing:-.02em}
        .hero h1 em{font-style:normal;color:var(--gold)}
        .hero p{margin:0;color:var(--muted);max-width:56ch;font-size:16px}
        .trust{display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:18px;color:var(--muted);font-size:13.5px}
        .trust b{color:var(--ink);font-weight:700}

        .panel{background:var(--card);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow);
            padding:22px;margin:8px 0 40px}
        .step-label{display:flex;align-items:center;gap:9px;font-size:12.5px;font-weight:800;letter-spacing:.14em;
            text-transform:uppercase;color:var(--muted);margin:2px 0 14px}
        .step-label .n{display:inline-grid;place-items:center;width:22px;height:22px;border-radius:50%;
            background:var(--ink);color:#fff;font-size:12px}
        .grid{display:grid;gap:14px}
        @media(min-width:640px){.grid.two{grid-template-columns:1fr 1fr}.grid.three{grid-template-columns:2fr 1fr 1fr}}
        .addr-row{display:grid;gap:14px}
        @media(min-width:640px){.addr-row{grid-template-columns:2.4fr 1fr}}
        label.f{display:block;font-size:13px;font-weight:700;margin:0 0 6px}
        .req{color:var(--gold)}
        input,select,textarea{width:100%;padding:12px 13px;border:1px solid var(--line);border-radius:11px;
            font:inherit;color:var(--ink);background:#fff}
        input:focus,select:focus,textarea:focus{outline:2px solid var(--gold);border-color:var(--gold)}
        textarea{min-height:70px;resize:vertical}
        .hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}

        .btn{appearance:none;border:0;cursor:pointer;font:inherit;font-weight:800;border-radius:12px;padding:14px 18px}
        .btn.gold{background:var(--gold);color:#111;width:100%;font-size:16px}
        .btn.gold:disabled{background:#efe7cf;color:#a99a6a;cursor:not-allowed}
        .btn.ghost{background:#fff;border:1px solid var(--ink);color:var(--ink)}

        .cars{display:grid;gap:12px;margin-top:4px}
        @media(min-width:560px){.cars{grid-template-columns:1fr 1fr}}
        .car{position:relative;border:1.5px solid var(--line);border-radius:14px;padding:14px 15px;cursor:pointer;
            transition:border-color .12s,background .12s;background:#fff}
        .car:hover{border-color:#cbbf9c}
        .car input{position:absolute;opacity:0;pointer-events:none}
        .car.sel{border-color:var(--gold);background:#fffdf5;box-shadow:0 0 0 3px rgba(251,186,42,.16)}
        .car .nm{font-weight:800;font-size:15.5px}
        .car .cap{color:var(--muted);font-size:13px;margin-top:2px}
        .car .pr{margin-top:10px;font-size:22px;font-weight:800;letter-spacing:-.01em}
        .car .pr small{font-size:12.5px;font-weight:600;color:var(--muted)}
        .car.poa .pr{font-size:16px;color:var(--muted)}

        .extras{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(210px,1fr))}
        .xcheck{display:flex;align-items:center;gap:10px;border:1px solid var(--line);border-radius:11px;padding:11px 13px;cursor:pointer;background:#fff;font-size:14px}
        .xcheck input{width:17px;height:17px;flex:none}
        .xcheck b{font-weight:800}
        .vatrow{display:flex;gap:11px;align-items:flex-start;margin-top:2px;padding:13px 14px;border:1px dashed var(--line);border-radius:12px;background:#fbfaf7}
        .vatrow input{width:18px;height:18px;margin-top:2px;flex:none}
        .vatrow .t{font-size:13.5px;color:var(--muted)}.vatrow .t b{color:var(--ink)}

        .summary{display:flex;justify-content:space-between;align-items:baseline;gap:14px;margin:16px 0 4px;
            padding-top:14px;border-top:1px solid var(--line)}
        .summary .lab{color:var(--muted);font-size:13.5px}
        .summary .amt{font-size:26px;font-weight:800;letter-spacing:-.01em}
        .fine{color:var(--muted);font-size:12.5px;margin:10px 0 0}
        .hidden{display:none}
        .errs{background:#fdecec;border:1px solid #f3b7b7;color:#8f1f1f;border-radius:12px;padding:12px 14px;margin-bottom:16px;font-size:14px}
        .errs ul{margin:6px 0 0;padding-left:18px}
        .note{color:var(--muted);font-size:13px;margin-top:10px}
        footer{color:var(--muted);font-size:12.5px;text-align:center;padding:26px 0 40px}
        footer b{color:var(--ink)}
    </style>
</head>
<body>
<header class="top">
    <div class="wrap brand">
        <span class="dot"></span><b>CENTRAL <span>EXECUTIVE</span></b>
        <small>Chauffeur &amp; Airport Transfers</small>
    </div>
</header>

<div class="wrap">
    <section class="hero">
        <h1>Book your <em>executive</em> journey</h1>
        <p>Fixed airport prices, professional chauffeurs, and instant confirmation. Get a price for every vehicle in seconds — pay securely and your car is booked.</p>
        <div class="trust">
            <span>✓ <b>Fixed</b> airport pricing</span>
            <span>✓ <b>Meet &amp; greet</b> included</span>
            <span>✓ Flight tracking</span>
            <span>✓ Licensed &amp; insured · <b>OP037</b></span>
        </div>
    </section>

    @if ($errors->any())
        <div class="errs">
            <strong>Please check the form:</strong>
            <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('public.book.store') }}" class="panel" id="bookForm">
        @csrf
        <div class="hp"><label>Company<input type="text" name="company" tabindex="-1" autocomplete="off"></label></div>

        {{-- Step 1 — journey --}}
        <div class="step-label"><span class="n">1</span> Your journey</div>
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
        <div class="note" style="margin-top:8px">Please double-check the pick-up postcode is correct — it sets your exact price and helps your driver find you.</div>
        <div class="grid three" style="margin-top:14px">
            <div><label class="f">Date &amp; time <span class="req">*</span></label>
                <input type="datetime-local" name="pickup_at" id="pickup_at" value="{{ old('pickup_at') }}" required></div>
            <div><label class="f">Passengers <span class="req">*</span></label>
                <input type="number" name="passengers" min="1" max="60" value="{{ old('passengers', 1) }}" required></div>
            <div><label class="f">Flight no. <small style="color:var(--muted)">(optional)</small></label>
                <input name="flight_number" value="{{ old('flight_number') }}" placeholder="e.g. TK1995"></div>
        </div>
        <div class="grid two" style="margin-top:14px">
            <div><label class="f">Suitcases</label>
                <input type="number" name="suitcases" min="0" max="30" value="{{ old('suitcases', 0) }}"></div>
            <div><label class="f">Hand luggage</label>
                <input type="number" name="hand_luggage" min="0" max="30" value="{{ old('hand_luggage', 0) }}"></div>
        </div>
        <div style="margin-top:16px">
            <button type="button" class="btn ghost" id="getPrices">See prices →</button>
            <div class="note" id="quoteNote"></div>
        </div>

        {{-- Step 2 — vehicle --}}
        <div id="step2" class="hidden" style="margin-top:26px">
            <div class="step-label"><span class="n">2</span> Choose your vehicle</div>
            <div class="cars" id="cars">
                @foreach ($vehicleTypes as $vt)
                    <label class="car" data-id="{{ $vt->id }}">
                        <input type="radio" name="vehicle_type_id" value="{{ $vt->id }}" {{ old('vehicle_type_id') == $vt->id ? 'checked' : '' }}>
                        <div class="nm">{{ $vt->name }}</div>
                        <div class="cap">Up to {{ $vt->passenger_capacity }} passengers · {{ $vt->luggage_capacity }} bags</div>
                        <div class="pr" data-price>—</div>
                    </label>
                @endforeach
            </div>
        </div>

        {{-- Step 3 — details + pay --}}
        <div id="step3" class="hidden" style="margin-top:26px">
            <div class="step-label"><span class="n">3</span> Your details</div>
            <div class="grid two">
                <div><label class="f">Full name <span class="req">*</span></label>
                    <input name="customer_name" value="{{ old('customer_name') }}" required></div>
                <div><label class="f">Mobile <span class="req">*</span></label>
                    <input name="customer_phone" value="{{ old('customer_phone') }}" placeholder="07…" required></div>
            </div>
            <div style="margin-top:14px"><label class="f">Email <span class="req">*</span></label>
                <input type="email" name="customer_email" value="{{ old('customer_email') }}" placeholder="For your confirmation &amp; receipt" required></div>
            <div style="margin-top:14px"><label class="f">Notes for your driver <small style="color:var(--muted)">(optional)</small></label>
                <textarea name="notes" placeholder="Anything we should know">{{ old('notes') }}</textarea></div>

            {{-- Extras (mirrors ETO Item Surcharge) --}}
            <div class="step-label" style="margin-top:22px"><span class="n">+</span> Extras <small style="text-transform:none;letter-spacing:0;font-weight:600;color:var(--muted)">(optional)</small></div>
            <div class="extras">
                @php $sc = $surcharges; @endphp
                <label class="xcheck">
                    <input type="checkbox" name="meet_greet" value="1" data-extra="{{ $sc['meet_greet'] ?? 0 }}" {{ old('meet_greet') ? 'checked' : '' }}>
                    <span>Meet &amp; greet <b>£{{ number_format($sc['meet_greet'] ?? 0, 0) }}</b></span>
                </label>
                <label class="xcheck">
                    <input type="checkbox" name="ribbons" value="1" data-extra="{{ $sc['ribbons_car'] ?? 0 }}" data-extra-minibus="{{ $sc['ribbons_minibus'] ?? 0 }}" {{ old('ribbons') ? 'checked' : '' }}>
                    <span>Wedding ribbons <b>from £{{ number_format($sc['ribbons_car'] ?? 0, 0) }}</b></span>
                </label>
                @if(($sc['wheelchair'] ?? 0) >= 0)
                <label class="xcheck">
                    <input type="checkbox" name="wheelchair" value="1" data-extra="{{ $sc['wheelchair'] ?? 0 }}" {{ old('wheelchair') ? 'checked' : '' }}>
                    <span>Wheelchair accessible {!! ($sc['wheelchair'] ?? 0) > 0 ? '<b>£'.number_format($sc['wheelchair'],0).'</b>' : '<b>free</b>' !!}</span>
                </label>
                @endif
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
                <span class="t"><b>I need a VAT invoice (business).</b> {{ $vatPercent }}% VAT is added on top of the price and you’ll receive a VAT invoice to reclaim it. Leave unticked for a standard booking (price shown is all-inclusive).</span>
            </label>

            <div class="summary">
                <span class="lab" id="sumLab">Total to pay today</span>
                <span class="amt" id="sumAmt">—</span>
            </div>

            <div style="margin-top:14px">
                <button type="submit" class="btn gold" id="payBtn" disabled>Pay &amp; confirm booking</button>
            </div>
            <p class="fine" id="payFine">
                @if($payEnabled)Secure card payment by Square. Your booking is confirmed the moment payment succeeds.
                @else Online payment isn’t available right now — send it and the office will confirm your price and take payment.@endif
                Cancellations &amp; changes: just reply to your confirmation email.
            </p>
        </div>
    </form>
</div>

<footer class="wrap">
    <b>Central Executive Transfers Ltd</b> · Operator Licence OP037 · 07405 172435 · centralexecutivetransfers.co.uk
</footer>

<script>
(function(){
    var token = document.querySelector('meta[name=csrf-token]').content;
    var vatPercent = {{ $vatPercent }};
    var payEnabled = {{ $payEnabled ? 'true' : 'false' }};
    var prices = {};            // vehicle id -> list price (null = POA)
    var step2 = document.getElementById('step2'),
        step3 = document.getElementById('step3'),
        cars  = document.getElementById('cars'),
        note  = document.getElementById('quoteNote'),
        payBtn= document.getElementById('payBtn'),
        sumAmt= document.getElementById('sumAmt'),
        sumLab= document.getElementById('sumLab'),
        vatBox= document.getElementById('vatInvoice');

    // Sensible default pickup time: +2 hours, rounded to the next 15 min.
    var pt = document.getElementById('pickup_at');
    if(!pt.value){
        var d = new Date(Date.now()+2*3600*1000);
        d.setMinutes(Math.ceil(d.getMinutes()/15)*15,0,0);
        pt.value = d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+'T'+p(d.getHours())+':'+p(d.getMinutes());
    }
    function p(n){return (n<10?'0':'')+n;}
    function money(n){return '£'+Number(n).toLocaleString('en-GB',{minimumFractionDigits:0,maximumFractionDigits:2});}

    document.getElementById('getPrices').addEventListener('click', function(){
        var pickup = document.getElementById('pickup').value.trim();
        var dest = document.getElementById('destination').value.trim();
        var pcode = document.getElementById('pickup_postcode').value.trim();
        var dcode = document.getElementById('destination_postcode').value.trim();
        if(!pickup || !dest){ note.textContent = 'Enter a pick-up and drop-off first.'; return; }
        if(!pcode){ note.textContent = 'Please add your pick-up postcode for an accurate price.'; document.getElementById('pickup_postcode').focus(); return; }
        this.disabled = true; note.textContent = 'Getting your prices…';
        fetch('{{ route('public.book.quotes') }}', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-TOKEN':token,'Accept':'application/json'},
            body: JSON.stringify({pickup:pickup, destination:dest, pickup_postcode:pcode, destination_postcode:dcode, pickup_at:pt.value})
        }).then(function(r){return r.json();}).then(function(data){
            (data.options||[]).forEach(function(o){
                prices[o.id]=o.price;
                var card = cars.querySelector('.car[data-id="'+o.id+'"]');
                if(!card) return;
                var el = card.querySelector('[data-price]');
                card.classList.toggle('poa', !!o.poa);
                el.innerHTML = o.poa ? 'Price on request'
                    : money(o.price)+' <small>'+(o.fixed?'fixed':'inc. everything')+'</small>';
            });
            step2.classList.remove('hidden'); step3.classList.remove('hidden');
            note.textContent = data.surcharge
                ? (data.surcharge.label+' rate applies to this date. Prices include VAT — choose a vehicle.')
                : 'Prices include VAT. Choose a vehicle to continue.';
            refreshTotal();
        }).catch(function(){ note.textContent='Sorry, we couldn’t price that just now. Please try again.'; })
          .finally((function(b){return function(){b.disabled=false;};})(this));
    });

    cars.addEventListener('change', function(e){
        if(e.target.name!=='vehicle_type_id') return;
        cars.querySelectorAll('.car').forEach(function(c){c.classList.remove('sel');});
        e.target.closest('.car').classList.add('sel');
        refreshTotal();
    });
    vatBox.addEventListener('change', refreshTotal);
    // Any extra (checkbox or quantity) changes the running total.
    document.getElementById('bookForm').addEventListener('input', function(e){
        if(e.target.hasAttribute && e.target.hasAttribute('data-extra')) refreshTotal();
    });

    function extrasTotal(){
        var sel = cars.querySelector('input[name=vehicle_type_id]:checked');
        var isMinibus = false;
        if(sel){ var nm=(sel.closest('.car').querySelector('.nm').textContent||'').toLowerCase();
            isMinibus = nm.indexOf('seater')>-1 || nm.indexOf('v class')>-1 || nm.indexOf('minibus')>-1; }
        var sum=0;
        document.querySelectorAll('[data-extra]').forEach(function(el){
            var unit=parseFloat(el.getAttribute('data-extra'))||0;
            if(el.type==='checkbox'){
                if(el.checked){ if(el.name==='ribbons' && isMinibus){ unit=parseFloat(el.getAttribute('data-extra-minibus'))||unit; } sum+=unit; }
            } else { sum += (parseInt(el.value,10)||0)*unit; }
        });
        return sum;
    }

    function refreshTotal(){
        var sel = cars.querySelector('input[name=vehicle_type_id]:checked');
        if(!sel){ payBtn.disabled=true; sumAmt.textContent='—'; return; }
        var base = prices[sel.value];
        if(base==null){ // POA
            sumLab.textContent='Price'; sumAmt.textContent='On request';
            payBtn.disabled=false; payBtn.textContent = payEnabled ? 'Send enquiry' : 'Send booking request';
            return;
        }
        var total = base + extrasTotal();
        if(vatBox.checked) total = Math.round(total*(1+vatPercent/100)*100)/100;
        sumLab.textContent = payEnabled ? 'Total to pay today' : 'Estimated total';
        sumAmt.textContent = money(total) + (vatBox.checked ? ' inc. VAT' : '');
        payBtn.disabled=false;
        payBtn.textContent = payEnabled ? ('Pay '+money(total)+' & confirm') : 'Send booking request';
    }
    refreshTotal();
})();
</script>
</body>
</html>
