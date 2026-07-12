<tr class="audit-row" data-status="{{ $r['status'] }}" style="{{ $r['status'] === 'ok' ? 'opacity:.6' : '' }}">
    <td style="white-space:nowrap">
        @if($r['url'])<a href="{{ $r['url'] }}">{{ $r['reference'] }}</a>@else {{ $r['reference'] }} @endif
    </td>
    <td>{{ $r['name'] }}</td>
    <td class="muted" style="white-space:nowrap">{{ $r['pickup']?->format('D d M · H:i') ?? '—' }}</td>
    <td>
        @if($r['status'] === 'ok')
            <span style="color:#1f7a44">✓ OK</span>
        @elseif($r['status'] === 'missing')
            <span style="color:#b32020">✗ Not imported</span>
            <div class="muted" style="font-size:12px">Import it via Imports → ETO bookings.</div>
        @else
            <span style="color:#b8860b;font-weight:600">⚠ Check</span>
            <ul style="margin:4px 0 0;padding-left:18px;font-size:13px">
                @foreach($r['issues'] as $issue)<li>{{ $issue }}</li>@endforeach
            </ul>
        @endif
    </td>
</tr>
