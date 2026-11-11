{{-- Contact number with an ETO-style country picker (flag + dialling code).
     The number is saved in international format (+447…, +1…) read in the picked
     country; typing "+…" or "00…" picks the country automatically.
     @param string|null $value --}}
@php
    $phoneValue = (string) ($value ?? '');
    $phoneRegion = old('customer_phone_country', \App\Support\Phone::pickerRegion($phoneValue));
@endphp
<div class="cet-phone" style="display:flex;gap:6px">
    <select name="customer_phone_country" id="customer_phone_country" aria-label="Country of the number"
            style="flex:0 0 auto;width:auto;max-width:118px;padding-right:22px">
        @foreach(\App\Support\Phone::COUNTRIES as $iso => [$cName, $cCode])
            <option value="{{ $iso }}" data-code="{{ $cCode }}" title="{{ $cName }}" @selected($phoneRegion === $iso)>{{ \App\Support\Phone::flag($iso) }} +{{ $cCode }}&nbsp; {{ $cName }}</option>
        @endforeach
    </select>
    <input id="customer_phone" name="customer_phone" type="tel" inputmode="tel" autocomplete="off"
           value="{{ $phoneValue }}" placeholder="07… or +1…" style="flex:1 1 auto;min-width:0">
</div>
<div class="muted" id="customer_phone_hint" style="font-size:12px;margin-top:4px"></div>
<script>
(function () {
    var sel = document.getElementById('customer_phone_country');
    var box = document.getElementById('customer_phone');
    var hint = document.getElementById('customer_phone_hint');
    if (!sel || !box) return;
    // Longest dialling code first so +353 wins over +35…, and +1 picks the US.
    var codes = Array.prototype.map.call(sel.options, function (o) { return { iso: o.value, code: o.dataset.code }; })
        .filter(function (c, i, all) { return all.findIndex(function (x) { return x.code === c.code; }) === i; })
        .sort(function (a, b) { return b.code.length - a.code.length; });

    function digits(v) { return (v || '').replace(/\D/g, ''); }
    function international(v) {
        v = (v || '').trim();
        if (v.charAt(0) === '+') return digits(v);
        var d = digits(v);
        return d.indexOf('00') === 0 ? d.slice(2) : null;
    }
    function detect() {
        var d = international(box.value);
        if (!d) return;
        for (var i = 0; i < codes.length; i++) {
            if (d.indexOf(codes[i].code) === 0) {
                if (sel.options[sel.selectedIndex].dataset.code !== codes[i].code) sel.value = codes[i].iso;
                return;
            }
        }
    }
    function preview() {
        var v = box.value.trim(), d = digits(v);
        if (d.length < 7) { hint.textContent = ''; return; }
        var full = international(v);
        if (!full) {
            var code = sel.options[sel.selectedIndex].dataset.code;
            if (code === '44') { full = '44' + d.replace(/^44/, '').replace(/^0/, ''); }
            else if (d.indexOf(code) === 0 && d.length - code.length >= 8) { full = d; }
            else { full = code + (sel.value === 'IT' ? d : d.replace(/^0+/, '')); }
        }
        hint.textContent = 'Saved as +' + full.replace(/^440/, '44');
    }
    box.addEventListener('input', function () { detect(); preview(); });
    sel.addEventListener('change', preview);
    detect(); preview();
})();
</script>
