@extends('layouts.app')
@section('title', 'Social posts')

@section('content')
@php use App\Models\SocialPost; @endphp
    @include('admin.social._shell', ['tab' => 'posts'])

    <form method="GET" action="{{ route('social.posts') }}" class="card ox-filters sc-actions" style="margin-bottom:12px">
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search title, caption, hashtags" aria-label="Search posts" style="flex:1 1 220px;min-width:0">
        <select name="platform" aria-label="Platform"><option value="">All platforms</option>@foreach(SocialPost::PLATFORMS as $k => $l)<option value="{{ $k }}" @selected(($filters['platform'] ?? '') === $k)>{{ $l }}</option>@endforeach</select>
        <select name="status" aria-label="Status"><option value="">Any status</option>@foreach(SocialPost::STATUSES as $k => $l)<option value="{{ $k }}" @selected(($filters['status'] ?? '') === $k)>{{ $l }}</option>@endforeach</select>
        <select name="pillar" aria-label="Pillar"><option value="">Any pillar</option>@foreach(SocialPost::PILLARS as $k => $l)<option value="{{ $k }}" @selected(($filters['pillar'] ?? '') === $k)>{{ $l }}</option>@endforeach</select>
        <select name="sort" aria-label="Sort"><option value="newest">Newest first</option><option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>Oldest first</option><option value="views" @selected(($filters['sort'] ?? '') === 'views')>Most views</option></select>
        <button class="btn btn-primary">Filter</button>
        @if(array_filter($filters))<a href="{{ route('social.posts') }}" class="btn btn-ghost">Clear</a>@endif
    </form>

    <div class="card">
        <div class="table-scroll">
            <table class="table-modern table-cards">
                <thead><tr><th>Post</th><th>Status</th><th>When</th><th style="text-align:right">Views</th><th style="text-align:right">Eng.</th><th>vs average</th><th></th></tr></thead>
                <tbody>
                @forelse($posts as $p)
                    @php $avg = $averages[$p->platform] ?? 0; $diff = ($p->views !== null && $avg) ? (int) round(($p->views - $avg) / $avg * 100) : null; @endphp
                    <tr>
                        <td data-label="Post"><span class="sc-dot" style="background:{{ $p->colour() }}"></span> <a href="{{ route('social.posts.edit', $p) }}"><strong>{{ $p->title }}</strong></a><br><span class="muted" style="font-size:12px">{{ $p->platformLabel() }}{{ $p->format ? ' · '.$p->format : '' }}{{ $p->pillarLabel() ? ' · '.$p->pillarLabel() : '' }}</span></td>
                        <td data-label="Status"><span class="sc-chip sc-st-{{ $p->status }}">{{ $p->statusLabel() }}</span></td>
                        <td data-label="When" style="font-size:13px;white-space:nowrap">{{ $p->when()?->format('D j M, H:i') ?? '—' }}</td>
                        <td data-label="Views" style="text-align:right;font-variant-numeric:tabular-nums">{{ $p->views !== null ? number_format($p->views) : '—' }}</td>
                        <td data-label="Eng." style="text-align:right;font-variant-numeric:tabular-nums">{{ $p->engagementRate() !== null ? $p->engagementRate().'%' : '—' }}</td>
                        <td data-label="vs average">@if($diff !== null)<span class="{{ $diff >= 0 ? 'sc-up' : 'sc-down' }}" style="font-weight:700">{{ $diff >= 0 ? '+' : '' }}{{ $diff }}%</span>@else<span class="muted">—</span>@endif</td>
                        <td data-label="" style="white-space:nowrap">
                            @if($p->status === 'posted')
                                <a class="btn btn-ghost" href="{{ route('social.posts.edit', $p) }}#audit">Audit</a>
                            @elseif($p->status !== 'skipped')
                                <form method="POST" action="{{ route('social.posts.posted', $p) }}" class="sc-inline">@csrf<button class="btn btn-ghost">Posted</button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No posts match. <a href="{{ route('social.posts.create') }}">Add one</a>.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $posts->links() }}
    </div>
@endsection
