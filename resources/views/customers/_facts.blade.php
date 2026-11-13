{{-- Office-only customer facts (admin pages; never shown to customers). --}}
@php $facts = $customer->adminFacts(); @endphp
<div class="cet-facts" style="display:flex;flex-wrap:wrap;gap:6px;margin:{{ $margin ?? '0 0 16px' }}">
    @foreach($facts as [$label, $value, $tone])
        @php
            $style = match ($tone) {
                'good' => 'background:rgba(31,140,74,.12);color:#1f8c4a;border-color:rgba(31,140,74,.35)',
                'warn' => 'background:rgba(214,140,0,.12);color:#a86b00;border-color:rgba(214,140,0,.4)',
                default => 'background:rgba(127,127,127,.08);color:inherit;border-color:rgba(127,127,127,.25)',
            };
        @endphp
        <span style="display:inline-flex;gap:5px;align-items:baseline;padding:4px 10px;border:1px solid;border-radius:999px;font-size:12px;{{ $style }}">
            <span style="opacity:.75">{{ $label }}:</span><strong>{{ $value }}</strong>
        </span>
    @endforeach
</div>
