{{-- One journey's details in the customer confirmation. Expects $leg, $row,
     optional $title / $changed (UPDATED tag) / $showRef (per-leg reference). --}}
    <tr><td style="padding:8px 26px 0">
        <h2 style="font-size:17px;margin:0 0 8px">{{ $title ?? 'Journey' }}@if(!empty($changed)) <span style="display:inline-block;background:#12558c;color:#fff;font-size:11px;font-weight:700;border-radius:4px;padding:2px 6px;vertical-align:middle">UPDATED</span>@endif</h2>
        <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px">
            {!! $row('Date &amp; time:', $leg->pickup_at?->format('d/m/Y H:i')) !!}
            {{-- The real UK offset ON THE PICKUP DATE (was hard-coded UTC+1, wrong all winter). --}}
            @php $ukAt = $leg->pickup_at?->clone()->setTimezone('Europe/London'); @endphp
            {!! $row('Time zone:', $ukAt ? ($ukAt->dst ? 'UTC+1 London (BST)' : 'UTC+0 London (GMT)') : 'UK time') !!}
            {!! $row('Pickup:', $leg->pickup_address) !!}
            @foreach($leg->viaStops() as $stop){!! $row('Via:', $stop) !!}@endforeach
            {!! $row('Dropoff:', $leg->destination_address) !!}
            @if(!empty($showRef)){!! $row('Reference:', $leg->external_reference ?: $leg->reference) !!}@endif
            {!! $row('Vehicle type:', $leg->vehicleType?->name) !!}
            {!! $row('Passengers:', $leg->passengers) !!}
            @php
                $bags = [];
                $sc = $leg->displaySuitcases(); $hl = $leg->displayHandLuggage();
                if ($sc > 0) { $bags[] = $sc.' suitcase'.($sc === 1 ? '' : 's'); }
                if ($hl > 0) { $bags[] = $hl.' hand bag'.($hl === 1 ? '' : 's'); }
                $seats = [];
                foreach (['child_seats' => 'child', 'booster_seats' => 'booster', 'infant_seats' => 'infant'] as $k => $lbl) {
                    $n = (int) ($leg->meta[$k] ?? 0);
                    if ($n > 0) { $seats[] = $n.' '.$lbl.' seat'.($n === 1 ? '' : 's'); }
                }
            @endphp
            {!! $row('Luggage:', $bags ? implode(', ', $bags) : 'None') !!}
            @if($seats){!! $row('Child seats:', implode(', ', $seats)) !!}@endif
            {!! $row('Flight:', $leg->displayFlightNumber()) !!}
            @php $landing = $leg->meta['flight_landing_at'] ?? null; @endphp
            @if($landing){!! $row('Flight lands:', \Illuminate\Support\Carbon::parse($landing)->format('d/m/Y H:i')) !!}@endif
            @if(!empty($leg->meta['meet_greet']) && (! $leg->is_return_leg || \App\Support\AirportMatcher::isAirport($leg->pickup_address))){!! $row('Meet &amp; greet:', 'Yes — driver waits in arrivals with a name board') !!}@endif
            @if(trim((string) ($leg->special_requests ?? '')) !== ''){!! $row('Notes:', trim((string) $leg->special_requests)) !!}@endif
        </table>
    </td></tr>

