@extends('layouts.app')
@section('title', 'Free-roam rates')

@section('content')
    <h1 class="page-title">Free-roam rates</h1>
    <p class="page-sub">The distance-based fares for anything that isn't a fixed airport route. Change the minimum fare and the per-mile rates here — customers see the new prices straight away. Every figure is the <strong>standard VAT-exclusive price</strong>, rounded to a clean £5. VAT ({{ $vatPercent }}%) is never baked in — it's only added on top when a VAT invoice is requested, and the office is shown both prices.</p>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())
        <div class="alert alert-danger">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    <form method="POST" action="{{ route('free-roam.update') }}">
        @csrf
        @method('PUT')

        <div class="card" style="margin-bottom:14px;overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;min-width:560px">
                <thead>
                    <tr style="text-align:left;border-bottom:2px solid var(--line)">
                        <th style="padding:8px 6px">Vehicle</th>
                        <th style="padding:8px 6px">Min fare<br><span class="hint" style="font-weight:400">first 10 miles</span></th>
                        <th style="padding:8px 6px">Per mile<br><span class="hint" style="font-weight:400">11–100 mi</span></th>
                        <th style="padding:8px 6px">Per mile<br><span class="hint" style="font-weight:400">100+ mi</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $r)
                        <tr style="border-bottom:1px solid var(--line)">
                            <td style="padding:10px 6px;font-weight:600">{{ $r['name'] }}</td>
                            @foreach(['flat' => $r['flat'], 'tier1' => $r['tier1'], 'tier2' => $r['tier2']] as $field => $val)
                                <td style="padding:8px 6px">
                                    <span style="color:var(--muted,#888)">£</span>
                                    <input type="number" step="0.01" min="0" required
                                           name="rates[{{ $r['slug'] }}][{{ $field }}]"
                                           value="{{ old('rates.'.$r['slug'].'.'.$field, number_format((float) $val, 2, '.', '')) }}"
                                           style="width:90px;padding:6px 8px;border:1px solid var(--line);border-radius:8px">
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="hint" style="margin:10px 0 0">Estate is always Executive + the uplift below, and Rolls-Royce is quote-only — so neither is set here.</p>
        </div>

        <div class="card" style="margin-bottom:14px">
            <div class="grid grid-2">
                <label>Estate uplift (£ over Executive)
                    <input type="number" step="0.01" min="0" name="estate_uplift" required
                           value="{{ old('estate_uplift', number_format((float) $estateUplift, 2, '.', '')) }}"
                           style="width:120px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                </label>
            </div>
            <div class="grid grid-2" style="margin-top:12px;border-top:1px solid var(--line);padding-top:12px">
                <label>Out-of-area free radius (miles)
                    <input type="number" step="0.1" min="0" name="deadmile_radius" required
                           value="{{ old('deadmile_radius', number_format((float) $deadMileRadius, 1, '.', '')) }}"
                           style="width:120px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                </label>
                <label>Band 1 rate (£ per mile, free radius → band 1 end)
                    <input type="number" step="0.01" min="0" name="deadmile_rate" required
                           value="{{ old('deadmile_rate', number_format((float) $deadMileRate, 2, '.', '')) }}"
                           style="width:120px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                </label>
                <label>Band 1 ends at (miles from base)
                    <input type="number" step="0.1" min="0" name="deadmile_band_miles" required
                           value="{{ old('deadmile_band_miles', number_format((float) $deadMileBand, 1, '.', '')) }}"
                           style="width:120px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                </label>
                <label>Band 2 rate (£ per mile beyond that)
                    <input type="number" step="0.01" min="0" name="deadmile_rate2" required
                           value="{{ old('deadmile_rate2', number_format((float) $deadMileRate2, 2, '.', '')) }}"
                           style="width:120px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                </label>
            </div>
            <p class="hint" style="margin:10px 0 0">Drag is charged in bands, each stretch at its own rate: nothing inside the free radius, then band 1's rate up to band 1's end, then band 2's rate for every mile after. E.g. 15 mi free, £1 to 30 mi, £2 after: a pickup 40 miles out adds £15 + £20 = £35. Rounded to £5. Airport fixed prices are unaffected.</p>
        </div>

        <button class="btn btn-primary" style="padding:9px 20px">Save rates</button>
    </form>

    <div class="card" style="margin-top:16px">
        <h2 style="margin:0 0 6px">Worked examples <span class="hint" style="font-weight:400">(standard price · with {{ $vatPercent }}% VAT)</span></h2>
        @php $fmt = fn ($p) => $p['net'] === null ? '—' : '£'.number_format((float) $p['net'], 0).' · £'.number_format((float) $p['gross'], 0).' inc'; @endphp
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;min-width:480px">
                <thead>
                    <tr style="text-align:left;border-bottom:2px solid var(--line)">
                        <th style="padding:6px">Distance</th>
                        <th style="padding:6px">Executive</th>
                        <th style="padding:6px">Estate</th>
                        <th style="padding:6px">V-Class</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($samplePrices as $s)
                        <tr style="border-bottom:1px solid var(--line)">
                            <td style="padding:6px">{{ $s['miles'] }} miles</td>
                            <td style="padding:6px">{{ $fmt($s['prices']['executive']) }}</td>
                            <td style="padding:6px">{{ $fmt($s['prices']['estate']) }}</td>
                            <td style="padding:6px">{{ $fmt($s['prices']['v-class']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="hint" style="margin:10px 0 0">Each cell shows the <strong>standard price</strong> and the <strong>with-VAT</strong> price. Save a change above and reload to see these update.</p>
    </div>
@endsection
