@extends('layouts.app')
@section('title', 'Free-roam rates')

@section('content')
    <h1 class="page-title">Free-roam rates</h1>
    <p class="page-sub">The distance-based fares for anything that isn't a fixed airport route. Change the minimum fare and the per-mile rates here — customers see the new prices straight away. All figures are <strong>VAT-exclusive</strong>; the VAT uplift below is added on top, then the quote is rounded to a clean £5.</p>

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
                <label>VAT uplift (£ added to every quote)
                    <input type="number" step="0.01" min="0" name="vat_uplift" required
                           value="{{ old('vat_uplift', number_format((float) $vatUplift, 2, '.', '')) }}"
                           style="width:120px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                </label>
                <label>Estate uplift (£ over Executive)
                    <input type="number" step="0.01" min="0" name="estate_uplift" required
                           value="{{ old('estate_uplift', number_format((float) $estateUplift, 2, '.', '')) }}"
                           style="width:120px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
                </label>
            </div>
        </div>

        <button class="btn btn-primary" style="padding:9px 20px">Save rates</button>
    </form>

    <div class="card" style="margin-top:16px">
        <h2 style="margin:0 0 6px">Worked examples <span class="hint" style="font-weight:400">(current live prices, incl. VAT, rounded)</span></h2>
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;min-width:420px">
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
                            <td style="padding:6px">£{{ number_format((float) ($s['prices']['executive'] ?? 0), 0) }}</td>
                            <td style="padding:6px">£{{ number_format((float) ($s['prices']['estate'] ?? 0), 0) }}</td>
                            <td style="padding:6px">£{{ number_format((float) ($s['prices']['v-class'] ?? 0), 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="hint" style="margin:10px 0 0">Save a change above and reload to see these update.</p>
    </div>
@endsection
