@extends('layouts.app')
@section('title', 'SEO')

@section('content')
    <h1 class="page-title">SEO</h1>
    <p class="page-sub">Manage page titles, meta descriptions, target keywords and tracked rankings.</p>

    @if($errors->any())<div class="alert alert-error">{{ $errors->first() }}</div>@endif

    <div class="card">
        <h2>Add / update a page</h2>
        <form method="POST" action="{{ route('marketing.seo.store') }}">
            @csrf
            <div class="grid grid-2">
                <div class="field">
                    <label for="path">Page path <span class="req">*</span></label>
                    <input id="path" name="path" value="{{ old('path') }}" placeholder="/airport-transfers-sheffield" required>
                </div>
                <div class="field">
                    <label for="target_keyword">Target keyword</label>
                    <input id="target_keyword" name="target_keyword" value="{{ old('target_keyword') }}" placeholder="sheffield airport transfers">
                </div>
            </div>
            <div class="field">
                <label for="title">Page title <span class="req">*</span></label>
                <input id="title" name="title" value="{{ old('title') }}" placeholder="Sheffield Airport Transfers | Central Executive Transfers" required>
            </div>
            <div class="field">
                <label for="meta_description">Meta description</label>
                <textarea id="meta_description" name="meta_description" maxlength="320" placeholder="Reliable executive airport transfers from Sheffield…">{{ old('meta_description') }}</textarea>
            </div>
            <div class="grid grid-3">
                <div class="field">
                    <label for="current_rank">Current Google rank</label>
                    <input id="current_rank" type="number" min="1" max="200" name="current_rank" value="{{ old('current_rank') }}">
                </div>
                <div class="field">
                    <label for="monthly_searches">Monthly searches</label>
                    <input id="monthly_searches" type="number" min="0" name="monthly_searches" value="{{ old('monthly_searches') }}">
                </div>
                <div class="field"><label>&nbsp;</label><button class="btn btn-primary btn-block" type="submit">Save page</button></div>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Pages</h2>
        @if($pages->isEmpty())
            <p class="muted mb-0">No pages tracked yet — add one above.</p>
        @else
            <table>
                <thead><tr><th>Path</th><th>Target keyword</th><th class="right">Rank</th><th class="right">Searches</th><th></th></tr></thead>
                <tbody>
                    @foreach($pages as $p)
                        <tr>
                            <td><strong>{{ $p->path }}</strong><br><span class="muted" style="font-size:12px">{{ \Illuminate\Support\Str::limit($p->title, 50) }}</span></td>
                            <td>{{ $p->target_keyword ?? '—' }}</td>
                            <td class="right">{{ $p->current_rank ? '#'.$p->current_rank : '—' }}</td>
                            <td class="right">{{ $p->monthly_searches ? number_format($p->monthly_searches) : '—' }}</td>
                            <td class="right">
                                <form method="POST" action="{{ route('marketing.seo.destroy', $p) }}" onsubmit="return confirm('Remove this page?');">
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
