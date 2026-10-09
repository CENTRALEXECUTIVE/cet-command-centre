{{--
    Reusable searchable / browsable booking picker.
    Params:
      $pid         unique id prefix (e.g. 'inv-combine', 'match-return')
      $searchUrl   JSON search endpoint (?q= optional; empty lists recent)
      $action      POST url the chosen reference is submitted to
      $buttonLabel submit button text
      $buttonClass optional button class (default 'btn-primary')
      $placeholder optional input placeholder
--}}
@php
    $buttonClass = $buttonClass ?? 'btn-primary';
    $placeholder = $placeholder ?? 'Tap to pick a booking, or type to search…';
@endphp
<form method="POST" action="{{ $action }}" id="{{ $pid }}-form" style="margin-top:8px">
    @csrf
    <div style="position:relative">
        <input type="text" name="reference" id="{{ $pid }}-search" autocomplete="off" required
               placeholder="{{ $placeholder }}"
               data-search-url="{{ $searchUrl }}"
               style="width:100%;padding:10px 36px 10px 12px;border:1px solid var(--line);border-radius:8px;font-size:14px;box-sizing:border-box">
        <span id="{{ $pid }}-caret" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);pointer-events:none;color:#888;font-size:12px">▼</span>
        <div id="{{ $pid }}-results" role="listbox"
             style="display:none;position:absolute;z-index:50;left:0;right:0;top:calc(100% + 4px);background:#ffffff;color:#111;border:1px solid #d9d9d9;border-radius:10px;box-shadow:0 12px 34px rgba(0,0,0,.28);max-height:320px;overflow-y:auto"></div>
    </div>
    <button class="btn {{ $buttonClass }}" style="padding:8px 14px;font-size:13px;margin-top:8px">{{ $buttonLabel }}</button>
</form>
<script>
(function () {
    var box = document.getElementById('{{ $pid }}-search');
    var results = document.getElementById('{{ $pid }}-results');
    var form = document.getElementById('{{ $pid }}-form');
    if (!box || !results || !form) return;
    var timer = null, lastReq = '';

    function hide() { results.style.display = 'none'; }

    function esc(s) {
        return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    function render(list) {
        if (!list.length) {
            results.innerHTML = '<div style="padding:11px 13px;font-size:13px;color:#777">No matching bookings.</div>';
            results.style.display = 'block';
            return;
        }
        results.innerHTML = list.map(function (r) {
            var meta = [r.when, r.journey].filter(Boolean).join(' · ');
            var tail = [r.operator ? '🤝 ' + r.operator : null, r.fare].filter(Boolean).join(' · ');
            return '<div class="bp-opt" role="option" tabindex="0" data-ref="' + esc(r.reference) + '"' +
                ' style="padding:10px 13px;border-bottom:1px solid #eee;cursor:pointer;font-size:13px;color:#111">' +
                '<strong>' + esc(r.reference) + '</strong> — ' + esc(r.name) +
                (meta ? '<div style="color:#666;font-size:12px;margin-top:2px">' + esc(meta) + '</div>' : '') +
                (tail ? '<div style="color:#666;font-size:12px">' + esc(tail) + '</div>' : '') +
                '</div>';
        }).join('');
        results.style.display = 'block';
    }

    function load() {
        var q = box.value.trim();
        var query = q.length >= 2 ? q : '';
        if (query === lastReq && results.style.display === 'block') return;
        lastReq = query;
        results.innerHTML = '<div style="padding:11px 13px;font-size:13px;color:#777">Loading…</div>';
        results.style.display = 'block';
        fetch(box.dataset.searchUrl + (query ? '?q=' + encodeURIComponent(query) : ''), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { render(d.results || []); })
            .catch(hide);
    }

    box.addEventListener('input', function () { lastReq = '\0'; clearTimeout(timer); timer = setTimeout(load, 200); });
    box.addEventListener('focus', function () { lastReq = '\0'; load(); });
    box.addEventListener('click', function () { if (results.style.display !== 'block') { lastReq = '\0'; load(); } });

    results.addEventListener('click', pick);
    results.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); pick(e); } });
    function pick(e) {
        var opt = e.target.closest('.bp-opt');
        if (!opt) return;
        box.value = opt.dataset.ref;
        hide();
        form.submit();
    }

    document.addEventListener('click', function (e) {
        if (!results.contains(e.target) && e.target !== box) hide();
    });
})();
</script>
