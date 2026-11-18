@extends('layouts.app')
@section('title', $label.' · Social')

@section('content')
@php
    $fmt = fn ($n) => $n >= 10000 ? round($n / 1000, 1).'K' : number_format((int) $n);
    $maxDay = max(1, collect($bestDay['days'])->max('avg'));
    $colour = \App\Models\SocialPost::COLOURS[$platform];
@endphp
    @include('admin.social._shell', ['tab' => $platform])

    <div class="sc-grid">
        <div class="card sc-card sc-kpi" style="border-top:3px solid {{ $colour }}"><div class="l">{{ ucfirst($followers['unit']) }}</div><div class="n">{{ $followers['now'] !== null ? number_format($followers['now']) : '–' }}</div>
            @if($followers['change'] !== null)<div class="d {{ $followers['change'] >= 0 ? 'sc-up' : 'sc-down' }}">{{ $followers['change'] >= 0 ? '▲' : '▼' }} {{ number_format(abs($followers['change'])) }}</div>@endif</div>
        <div class="card sc-card sc-kpi"><div class="l">Posts out</div><div class="n">{{ $summary['posts'] }}</div></div>
        <div class="card sc-card sc-kpi"><div class="l">Avg views / post</div><div class="n">{{ $fmt($summary['avg_views']) }}</div></div>
        <div class="card sc-card sc-kpi"><div class="l">Avg engagement</div><div class="n">{{ $summary['avg_eng_rate'] !== null ? $summary['avg_eng_rate'].'%' : '–' }}</div></div>
        <div class="card sc-card sc-kpi"><div class="l">Follows from posts</div><div class="n">{{ number_format($summary['follows']) }}</div></div>
    </div>

    <div class="sc-two" style="margin-bottom:14px">
        <div class="card sc-card">
            <h2>What's working</h2>
            <p class="sc-sub">Content types and formats compared with your {{ $label }} average (needs 2+ posts of a type).</p>
            @if($working['working'] || $working['not'])
                @foreach($working['working'] as $w)<p style="margin:0 0 6px">✅ {{ $w }}</p>@endforeach
                @foreach($working['not'] as $w)<p style="margin:0 0 6px">⚠️ {{ $w }}</p>@endforeach
            @else
                <p class="muted" style="margin:0">Not enough posts with numbers yet. Keep posting and typing in the numbers; this fills in by itself.</p>
            @endif
        </div>
        <div class="card sc-card">
            <h2>Best day on {{ $label }}</h2>
            <div class="sc-days" role="img" aria-label="Average views by weekday">
                @foreach($bestDay['days'] as $d => $v)
                    <div class="sc-day {{ $bestDay['best'] === $d ? 'best' : '' }}" title="{{ $d }}: {{ number_format($v['avg']) }} avg views">
                        <i style="height:{{ max(2, round($v['avg'] / $maxDay * 80)) }}px"></i><span>{{ $d }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="sc-two">
        <div class="card sc-card">
            <h2>Top posts</h2>
            @if($top->count())
                <ul class="sc-list">
                    @foreach($top as $p)
                        <li><span class="t"><a href="{{ route('social.posts.edit', $p) }}">{{ $p->title }}</a> <span class="m">· {{ $p->posted_at->format('j M') }}</span></span>
                            <span class="sc-chip">{{ $fmt($p->views) }} views</span></li>
                    @endforeach
                </ul>
            @else
                <p class="muted" style="margin:0">Nothing with numbers yet.</p>
            @endif
        </div>
        <div class="card sc-card">
            <h2>Next up</h2>
            @if($next->count())
                <ul class="sc-list">
                    @foreach($next as $p)
                        <li><span class="t"><a href="{{ route('social.posts.edit', $p) }}">{{ $p->title }}</a> <span class="m">· {{ $p->scheduled_at?->format('D j M H:i') ?? 'no date' }}</span></span>
                            <span class="sc-chip sc-st-{{ $p->status }}">{{ $p->statusLabel() }}</span></li>
                    @endforeach
                </ul>
            @else
                <p class="muted" style="margin:0">Nothing planned for {{ $label }}. <a href="{{ route('social.posts.create', ['platform' => $platform]) }}">Add a post</a>.</p>
            @endif
        </div>
    </div>
@endsection
