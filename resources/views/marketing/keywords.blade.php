@extends('layouts.app')
@section('title', 'Keywords')

@section('content')
    <h1 class="page-title">Google Ads Keywords</h1>
    <p class="page-sub">Manage the keywords you bid on, with performance at a glance.</p>

    @if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif

    {{-- Bulk import from a Google Ads keyword report. --}}
    <div class="card" style="margin-bottom:16px">
        <h2 style="margin:0 0 4px">Upload Google Ads keyword report</h2>
        <p class="hint" style="margin:0 0 10px">Export the <strong>Keywords</strong> report from Google Ads as CSV and drop it here — clicks, impressions, conversions and cost import per keyword for review. Re-importing refreshes the figures.</p>
        <form method="POST" action="{{ route('marketing.keywords.import') }}" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            @csrf
            <input type="file" name="file" accept=".csv,text/csv" required>
            <button class="btn btn-primary" style="padding:8px 16px">Import keywords</button>
        </form>
    </div>

    <div class="grid grid-3" style="margin-bottom:20px">
        <div class="stat"><div class="n">{{ number_format($totals['clicks']) }}</div><div class="l">Clicks</div></div>
        <div class="stat"><div class="n">{{ $totals['conversions'] }}</div><div class="l">Conversions</div></div>
        <div class="stat"><div class="n">£{{ number_format($totals['cost'], 2) }}</div><div class="l">Spend</div></div>
    </div>

    <div class="card">
        <h2>Add a keyword</h2>
        <form method="POST" action="{{ route('marketing.keywords.store') }}">
            @csrf
            <div class="grid grid-3">
                <div class="field">
                    <label for="keyword">Keyword <span class="req">*</span></label>
                    <input id="keyword" name="keyword" value="{{ old('keyword') }}" placeholder="airport transfers sheffield" required>
                </div>
                <div class="field">
                    <label for="match_type">Match type</label>
                    <select id="match_type" name="match_type">
                        <option value="phrase">Phrase</option>
                        <option value="exact">Exact</option>
                        <option value="broad">Broad</option>
                    </select>
                </div>
                <div class="field">
                    <label for="campaign">Campaign</label>
                    <input id="campaign" name="campaign" value="{{ old('campaign') }}" placeholder="Airport Transfers">
                </div>
            </div>
            <div class="grid grid-3">
                <div class="field">
                    <label for="max_cpc">Max CPC (£)</label>
                    <input id="max_cpc" type="number" step="0.01" min="0" name="max_cpc" value="{{ old('max_cpc') }}">
                </div>
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="enabled">Enabled</option>
                        <option value="paused">Paused</option>
                    </select>
                </div>
                <div class="field"><label>&nbsp;</label><button class="btn btn-primary btn-block" type="submit">Add keyword</button></div>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Keywords</h2>
        @if($keywords->isEmpty())
            <p class="muted mb-0">No keywords yet. Add one above, or sync from Google Ads.</p>
        @else
            <table>
                <thead><tr><th>Keyword</th><th>Match</th><th>Status</th><th class="right">Clicks</th><th class="right">CTR</th><th class="right">Conv.</th><th class="right">Cost</th><th class="right">CPA</th><th></th></tr></thead>
                <tbody>
                    @foreach($keywords as $k)
                        <tr>
                            <td><strong>{{ $k->keyword }}</strong>@if($k->campaign)<br><span class="muted" style="font-size:12px">{{ $k->campaign }}</span>@endif</td>
                            <td>{{ ucfirst($k->match_type) }}</td>
                            <td><span class="badge badge-{{ $k->status === 'enabled' ? 'complete' : 'pending' }}">{{ ucfirst($k->status) }}</span></td>
                            <td class="right">{{ number_format($k->clicks) }}</td>
                            <td class="right">{{ $k->ctr !== null ? $k->ctr.'%' : '—' }}</td>
                            <td class="right">{{ $k->conversions }}</td>
                            <td class="right">£{{ number_format($k->cost, 2) }}</td>
                            <td class="right">{{ $k->cpa !== null ? '£'.number_format($k->cpa, 2) : '—' }}</td>
                            <td class="right">
                                <form method="POST" action="{{ route('marketing.keywords.destroy', $k) }}" onsubmit="return confirm('Remove this keyword?');">
                                    @csrf @method('DELETE')
                                    <button class="btn-xs" style="background:#b32020" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
