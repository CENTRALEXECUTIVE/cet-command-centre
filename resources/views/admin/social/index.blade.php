@extends('layouts.app')
@section('title', 'Social media')

@section('content')
@php
    use App\Models\SocialPost;
    $C = SocialPost::COLOURS;
    $hour = (int) now()->format('G');
    $greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    $maxWeek = max(1, collect($weeks)->max('total'));
    $maxDay = max(1, collect($bestDay['days'])->max('avg'));
    $fmt = fn ($n) => $n >= 10000 ? round($n / 1000, 1).'K' : number_format((int) $n);
@endphp
    @include('admin.social._shell', ['tab' => 'overview'])

    @if(! $anyPosts)
        <div class="card sc-empty">
            <h2>Nothing posted yet. Let's start.</h2>
            <p>Load the starter plan: two weeks of posts built from the three Reels already edited (prep, fleet, wedding), two LinkedIn posts for business accounts, two Google Business updates, and a bank of 14 more ideas. Captions are written; nothing is posted until you post it in the app.</p>
            <form method="POST" action="{{ route('social.starter') }}" class="sc-inline">@csrf
                <button class="btn btn-primary">Load the 2-week starter plan</button>
            </form>
            <a href="{{ route('social.posts.create') }}" class="btn btn-ghost">Or add a post yourself</a>
        </div>
    @endif

    <p style="margin:0 0 12px;font-size:15px"><strong>{{ $greet }}, {{ \Illuminate\Support\Str::of(auth()->user()->name)->before(' ') }}.</strong>
        @php $todayN = $upcoming->filter(fn ($p) => $p->scheduled_at->isToday())->count(); @endphp
        @if($todayN)
            {{ $todayN }} post{{ $todayN > 1 ? 's' : '' }} planned for today.
        @elseif($upcoming->count())
            Next post: {{ $upcoming->first()->platformLabel() }}, {{ $upcoming->first()->scheduled_at->format('D j M, H:i') }}.
        @else
            Nothing planned for the next 7 days.
        @endif
    </p>

    @if($overdue->count() || $needsNumbers->count())
        <div class="card sc-alert" style="margin-bottom:14px">
            @if($overdue->count())
                <h2 style="font-size:15px;margin:0 0 6px">⏰ {{ $overdue->count() }} planned post(s) have passed</h2>
                <ul class="sc-list">
                    @foreach($overdue->take(5) as $p)
                        <li><span class="t"><span class="sc-dot" style="background:{{ $p->colour() }}"></span> <a href="{{ route('social.posts.edit', $p) }}">{{ $p->title }}</a> <span class="m">· {{ $p->platformLabel() }}, {{ $p->scheduled_at->format('D j M H:i') }}</span></span>
                            <span class="sc-actions">
                                <form method="POST" action="{{ route('social.posts.posted', $p) }}" class="sc-inline">@csrf<button class="btn btn-ghost">Posted</button></form>
                                <a class="btn btn-ghost" href="{{ route('social.posts.edit', $p) }}">Move / skip</a>
                            </span></li>
                    @endforeach
                </ul>
            @endif
            @if($needsNumbers->count())
                <h2 style="font-size:15px;margin:{{ $overdue->count() ? '12px' : '0' }} 0 6px">📊 Add the numbers for {{ $needsNumbers->count() }} post(s)</h2>
                <p class="sc-sub" style="margin:0 0 6px">Open each app's insights and type in views, likes, comments, shares, saves. Two minutes, and the charts below come alive.</p>
                <ul class="sc-list">
                    @foreach($needsNumbers->take(5) as $p)
                        <li><span class="t"><span class="sc-dot" style="background:{{ $p->colour() }}"></span> <a href="{{ route('social.posts.edit', $p) }}#numbers">{{ $p->title }}</a> <span class="m">· {{ $p->platformLabel() }}, posted {{ $p->posted_at->format('D j M') }}</span></span></li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- Platform cards: followers --}}
    <form method="POST" action="{{ route('social.followers') }}" id="followers">@csrf
    <div class="sc-grid">
        @foreach($followers as $k => $f)
            <a class="card sc-card sc-kpi" href="{{ route('social.platform', $k) }}" style="text-decoration:none;color:inherit;border-top:3px solid {{ $C[$k] }}">
                <div class="l"><span class="sc-dot" style="background:{{ $C[$k] }}"></span>{{ $f['label'] }}</div>
                <div class="n">{{ $f['now'] !== null ? number_format($f['now']) : '–' }}</div>
                <div class="d">
                    @if($f['change'] !== null)
                        <span class="{{ $f['change'] >= 0 ? 'sc-up' : 'sc-down' }}">{{ $f['change'] >= 0 ? '▲' : '▼' }} {{ number_format(abs($f['change'])) }}</span> <span class="muted">{{ $f['unit'] }}, last 4 logs</span>
                    @else
                        <span class="muted">{{ $f['unit'] }}</span>
                    @endif
                </div>
            </a>
        @endforeach
    </div>
    <details class="card" style="margin:-4px 0 14px">
        <summary style="cursor:pointer;font-weight:600">Update this week's follower counts</summary>
        <p class="sc-sub" style="margin-top:8px">Type the number from each app's profile once a week (Monday is easiest). Blank boxes keep the last figure. Shared with the Growth dashboard.</p>
        <div class="sc-nums">
            @foreach(\App\Services\Social\SocialCommandCentre::FOLLOWER_FIELDS as $k => $field)
                <div class="field"><label for="f-{{ $field }}">{{ SocialPost::PLATFORMS[$k] }} {{ $k === 'gbp' ? 'reviews' : '' }}</label>
                    <input id="f-{{ $field }}" type="number" min="0" name="{{ $field }}" inputmode="numeric" placeholder="{{ $followers[$k]['now'] ?? '' }}"></div>
            @endforeach
        </div>
        <button class="btn btn-primary" style="margin-top:10px">Save follower counts</button>
    </details>
    </form>

    {{-- Headline numbers --}}
    <div class="sc-grid">
        <div class="card sc-card sc-kpi"><div class="l">Posts out (all time)</div><div class="n">{{ number_format($summary['posts']) }}</div></div>
        <div class="card sc-card sc-kpi"><div class="l">Total views</div><div class="n">{{ $fmt($summary['views']) }}</div></div>
        <div class="card sc-card sc-kpi"><div class="l">Avg views / post</div><div class="n">{{ $fmt($summary['avg_views']) }}</div></div>
        <div class="card sc-card sc-kpi"><div class="l">Avg engagement</div><div class="n">{{ $summary['avg_eng_rate'] !== null ? $summary['avg_eng_rate'].'%' : '–' }}</div></div>
        <div class="card sc-card sc-kpi"><div class="l">Enquiries from posts</div><div class="n">{{ number_format($summary['enquiries']) }}</div></div>
    </div>

    <div class="sc-two" style="margin-bottom:14px">
        <div class="card sc-card">
            <h2>Views by week</h2>
            <p class="sc-sub">Last 8 weeks, by the day each post went out. Stacked by platform.</p>
            <div class="sc-bars" role="img" aria-label="Views per week for the last 8 weeks">
                @foreach($weeks as $w)
                    <div style="display:flex;flex-direction:column;justify-content:flex-end;height:100%">
                        @if($w['total'])<div class="sc-bar-v">{{ $fmt($w['total']) }}</div>@endif
                        <div class="sc-bar" style="height:{{ max(1, round($w['total'] / $maxWeek * 140)) }}px" title="{{ $w['label'] }}: {{ number_format($w['total']) }} views">
                            @foreach($w['by'] as $p => $v)
                                @if($v)<span style="height:{{ round($v / max(1, $w['total']) * 100, 2) }}%;background:{{ $C[$p] }}"></span>@endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="sc-bar-l">@foreach($weeks as $w)<span>{{ $w['label'] }}</span>@endforeach</div>
            <div class="sc-legend">@foreach(SocialPost::PLATFORMS as $k => $l)<span><i class="sc-dot" style="background:{{ $C[$k] }}"></i>{{ $l }}</span>@endforeach</div>
        </div>

        <div class="card sc-card" style="text-align:center">
            <h2>Posting consistency</h2>
            <p class="sc-sub">This week: goal {{ $consistency['goal'] }} posts across all platforms.</p>
            <div class="sc-gauge" style="--p: {{ $consistency['pct'] }}"><div><span><b>{{ $consistency['done'] }}</b><small>of {{ $consistency['goal'] }}</small></span></div></div>
            <div style="font-size:13px">
                @if($consistency['streak'] > 0)🔥 {{ $consistency['streak'] }} week{{ $consistency['streak'] > 1 ? 's' : '' }} in a row on target · @endif
                {{ $consistency['planned_left'] }} more planned this week
            </div>
            <div class="sc-legend" style="justify-content:center">
                @foreach($consistency['by_platform'] as $k => $n)<span><i class="sc-dot" style="background:{{ $C[$k] }}"></i>{{ SocialPost::PLATFORMS[$k] }} {{ $n }}</span>@endforeach
            </div>
            <form method="POST" action="{{ route('social.settings') }}" class="sc-actions" style="justify-content:center;margin-top:10px">@csrf
                <label for="goal" class="muted" style="font-size:12px">Weekly goal</label>
                <input id="goal" type="number" name="goal" min="1" max="100" value="{{ $consistency['goal'] }}" style="width:70px">
                <button class="btn btn-ghost">Set</button>
            </form>
        </div>
    </div>

    <div class="sc-two" style="margin-bottom:14px">
        <div class="card sc-card">
            <h2>Top 5 posts</h2>
            <p class="sc-sub">By views. Open one to audit it against your average.</p>
            @if($top->count())
                <ul class="sc-list">
                    @foreach($top as $i => $p)
                        <li><span class="t"><span class="sc-dot" style="background:{{ $p->colour() }}"></span> <a href="{{ route('social.posts.edit', $p) }}">{{ $p->title }}</a>
                            <span class="m">· {{ $p->platformLabel() }}, {{ $p->posted_at->format('j M') }}</span></span>
                            <span class="sc-chip">{{ $fmt($p->views) }} views</span>
                            @if($p->engagementRate() !== null)<span class="sc-chip">{{ $p->engagementRate() }}% eng.</span>@endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="muted" style="margin:0">Your best posts show here once their numbers are in.</p>
            @endif
        </div>

        <div class="card sc-card">
            <h2>Best day to post</h2>
            <p class="sc-sub">Average views by the day a post went out.@unless($bestDay['enough']) Needs 6+ posts with numbers to be reliable.@endunless</p>
            <div class="sc-days" role="img" aria-label="Average views by weekday">
                @foreach($bestDay['days'] as $d => $v)
                    <div class="sc-day {{ $bestDay['best'] === $d ? 'best' : '' }}" title="{{ $d }}: {{ number_format($v['avg']) }} avg views from {{ $v['posts'] }} posts">
                        <i style="height:{{ max(2, round($v['avg'] / $maxDay * 80)) }}px"></i><span>{{ $d }}</span>
                    </div>
                @endforeach
            </div>
            <p style="margin:10px 0 0;font-size:13px">
                @if($bestDay['best']) Best so far: <strong>{{ $bestDay['best'] }}</strong>.
                @else Starting rhythm: Tue, Thu 7pm and Sat late morning for Reels; LinkedIn on weekday mornings. @endif
            </p>
        </div>
    </div>

    <div class="sc-two">
        <div class="card sc-card">
            <h2>Coming up (7 days)</h2>
            <p class="sc-sub"><a href="{{ route('social.planner') }}">Open the planner</a> to move or add posts.</p>
            @if($upcoming->count())
                <ul class="sc-list">
                    @foreach($upcoming as $p)
                        <li><span class="t"><span class="sc-dot" style="background:{{ $p->colour() }}"></span> <a href="{{ route('social.posts.edit', $p) }}">{{ $p->title }}</a>
                            <span class="m">· {{ $p->platformLabel() }}, {{ $p->scheduled_at->format('D j M H:i') }}</span></span>
                            <span class="sc-chip sc-st-{{ $p->status }}">{{ $p->statusLabel() }}</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="muted" style="margin:0">Nothing planned. @if(! $starterLoaded)<form method="POST" action="{{ route('social.starter') }}" class="sc-inline">@csrf<button class="btn btn-ghost">Load the starter plan</button></form>@endif</p>
            @endif
        </div>
        <div class="card sc-card">
            <h2>Notes</h2>
            <p class="sc-sub">What you've learned, ideas, reminders. Shared between both of you.</p>
            <form method="POST" action="{{ route('social.settings') }}">@csrf
                <textarea name="notes" rows="6" style="width:100%;box-sizing:border-box" placeholder="e.g. Airport Reels get more saves than fleet ones.">{{ $notes }}</textarea>
                <button class="btn btn-ghost" style="margin-top:8px">Save notes</button>
            </form>
            <p class="sc-sub" style="margin:10px 0 0">Bookings that came from social show in <a href="{{ route('reports.sources') }}">Bookings by source</a>.</p>
        </div>
    </div>
    <div class="card sc-card" style="margin-top:14px" id="meta">
        <h2>Facebook &amp; Instagram numbers {{ $metaConnected ? '· connected' : '· not connected' }}</h2>
        @if($metaConnected)
            <p class="sc-sub">Followers and every post's views, likes, comments, shares and saves update every night (read-only: nothing is ever posted from here).
                @if($metaLastSync) Last update {{ \Illuminate\Support\Carbon::parse($metaLastSync)->timezone(config('app.timezone'))->format('D j M, H:i') }}.@endif</p>
            <form method="POST" action="{{ route('social.meta.sync') }}" class="sc-inline">@csrf<button class="btn btn-ghost">Sync now</button></form>
        @else
            <p class="sc-sub">Connect once and the numbers fill themselves in. Free, read-only. Steps: docs/growth/social/meta-connect.md (Claude can walk you through it).</p>
        @endif
        @if(auth()->user()->isSuperAdmin())
            <details style="margin-top:10px"><summary style="cursor:pointer;font-weight:600">{{ $metaConnected ? 'Change connection' : 'Connect' }}</summary>
                <form method="POST" action="{{ route('social.meta.save') }}" class="sc-form" style="margin-top:10px">@csrf
                    <div class="sc-nums">
                        <div class="field"><label for="meta-page">Facebook Page ID</label><input id="meta-page" name="page_id" inputmode="numeric" required value="{{ $metaPageId }}"></div>
                        <div class="field"><label for="meta-token">Page access token</label><input id="meta-token" name="token" type="password" autocomplete="off" placeholder="{{ $metaConnected ? 'Saved (leave blank to keep)' : 'Paste the token' }}"></div>
                    </div>
                    <button class="btn btn-primary" style="margin-top:10px">Save and test</button>
                </form>
            </details>
        @endif
    </div>
@endsection
