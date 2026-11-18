{{-- Shared header, tabs and styles for the Social Media Command Centre. --}}
@php
    $platformColour = \App\Models\SocialPost::COLOURS;
    $tab = $tab ?? 'overview';
@endphp
<style>
    .sc-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin: 0 0 14px; }
    .sc-tabs a { padding: 8px 14px; border-radius: 999px; border: 1px solid var(--line); color: inherit; text-decoration: none; font-weight: 600; font-size: 13.5px; display: inline-flex; gap: 6px; align-items: center; min-height: 40px; }
    .sc-tabs a.on { background: #0b0b0b; color: #fff; border-color: #0b0b0b; box-shadow: inset 0 -2px 0 var(--gold); }
    .sc-tabs a:focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; }
    .sc-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; flex: none; }
    .sc-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); margin-bottom: 14px; }
    .sc-two { display: grid; gap: 14px; grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); }
    @media (max-width: 900px) { .sc-two { grid-template-columns: minmax(0, 1fr); } }
    .sc-card { margin: 0; }
    .sc-card h2 { margin: 0 0 4px; font-size: 16px; }
    .sc-sub { color: var(--muted); font-size: 12.5px; margin: 0 0 12px; }
    .sc-kpi .n { font-size: 26px; font-weight: 800; font-variant-numeric: tabular-nums; line-height: 1.1; }
    .sc-kpi .l { font-size: 12.5px; color: var(--muted); display: flex; gap: 6px; align-items: center; }
    .sc-kpi .d { font-size: 12px; margin-top: 2px; }
    .sc-up { color: var(--green); } .sc-down { color: var(--red); }
    .sc-bars { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(0, 1fr); gap: 8px; align-items: end; height: 170px; padding-top: 6px; border-bottom: 1px solid var(--line); background-image: linear-gradient(to top, rgba(127,127,127,.12) 1px, transparent 1px); background-size: 100% 25%; }
    .sc-bar { display: flex; flex-direction: column-reverse; border-radius: 6px 6px 0 0; overflow: hidden; min-height: 2px; background: rgba(127,127,127,.12); }
    .sc-bar span { display: block; }
    .sc-bar-l { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(0, 1fr); gap: 8px; font-size: 11px; color: var(--muted); text-align: center; margin-top: 4px; font-variant-numeric: tabular-nums; }
    .sc-bar-v { font-size: 11px; text-align: center; font-weight: 700; font-variant-numeric: tabular-nums; }
    .sc-legend { display: flex; gap: 12px; flex-wrap: wrap; font-size: 12px; color: var(--muted); margin-top: 10px; }
    .sc-legend span { display: inline-flex; gap: 5px; align-items: center; }
    .sc-gauge { --p: 0; width: 132px; height: 132px; border-radius: 50%; display: grid; place-items: center; margin: 4px auto 8px;
        background: conic-gradient(var(--gold) calc(var(--p) * 1%), rgba(127,127,127,.16) 0); }
    .sc-gauge > div { width: 100px; height: 100px; border-radius: 50%; background: var(--bg, #fff); display: grid; place-items: center; text-align: center; }
    .sc-gauge b { font-size: 26px; font-variant-numeric: tabular-nums; display: block; line-height: 1; }
    .sc-gauge small { color: var(--muted); font-size: 11.5px; }
    .sc-list { list-style: none; margin: 0; padding: 0; }
    .sc-list li { display: flex; gap: 10px; align-items: center; justify-content: space-between; padding: 9px 0; border-top: 1px solid rgba(127,127,127,.16); flex-wrap: wrap; }
    .sc-list li:first-child { border-top: 0; }
    .sc-list .t { min-width: 0; flex: 1 1 200px; }
    .sc-list .t a { color: inherit; font-weight: 600; text-decoration: none; }
    .sc-list .t a:hover { text-decoration: underline; }
    .sc-list .m { font-size: 12px; color: var(--muted); }
    .sc-chip { display: inline-flex; align-items: center; gap: 5px; padding: 2px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 700; background: rgba(127,127,127,.12); white-space: nowrap; }
    .sc-st-idea { background: rgba(127,127,127,.14); }
    .sc-st-planned { background: #e7efff; color: #1d4ed8; }
    .sc-st-ready { background: #fff4d6; color: #8a6d00; }
    .sc-st-posted { background: #d9f3e2; color: #1f7a44; }
    .sc-st-skipped { background: #f3f3f3; color: #777; text-decoration: line-through; }
    .sc-days { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; align-items: end; height: 110px; }
    .sc-day { display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 4px; height: 100%; font-size: 11px; color: var(--muted); }
    .sc-day i { width: 100%; max-width: 34px; border-radius: 5px 5px 0 0; background: rgba(127,127,127,.22); display: block; min-height: 2px; }
    .sc-day.best i { background: var(--gold); }
    .sc-day.best { color: inherit; font-weight: 700; }
    .sc-alert { border-left: 4px solid var(--gold); }
    .sc-empty { text-align: center; padding: 28px 16px; }
    .sc-empty h2 { font-size: 20px; margin: 0 0 6px; }
    .sc-empty p { max-width: 560px; margin: 0 auto 14px; color: var(--muted); }
    .sc-form .field input, .sc-form .field select, .sc-form .field textarea { width: 100%; box-sizing: border-box; }
    .sc-form textarea { min-height: 120px; }
    .sc-nums { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); }
    .sc-nums .field { margin-bottom: 0; }
    .sc-inline { display: inline; margin: 0; }
    .sc-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .sc-actions .btn { min-height: 40px; display: inline-flex; align-items: center; }
    .sc-copy { white-space: pre-wrap; word-break: break-word; font-family: inherit; font-size: 13.5px; line-height: 1.5; margin: 0; padding: 12px; border-radius: 10px; background: rgba(127,127,127,.08); max-height: 360px; overflow: auto; }
    .sc-week { display: grid; grid-template-columns: repeat(7, minmax(150px, 1fr)); gap: 8px; overflow-x: auto; padding-bottom: 6px; }
    .sc-col { border: 1px solid var(--line); border-radius: 12px; padding: 8px; min-height: 180px; display: flex; flex-direction: column; gap: 6px; background: rgba(127,127,127,.03); }
    .sc-col.today { border-color: var(--gold); box-shadow: 0 0 0 1px var(--gold) inset; }
    .sc-col h3 { font-size: 12.5px; margin: 0 0 2px; display: flex; justify-content: space-between; color: var(--muted); }
    .sc-col.today h3 { color: inherit; }
    .sc-post { border-radius: 9px; padding: 7px 8px; background: #fff; border: 1px solid var(--line); border-left: 4px solid var(--c, #999); font-size: 12.5px; text-decoration: none; color: inherit; display: block; }
    .sc-post:hover { border-color: var(--gold); }
    .sc-post b { display: block; font-size: 12.5px; line-height: 1.3; }
    .sc-post small { color: var(--muted); }
    .sc-post.posted { opacity: .7; }
    .sc-add { font-size: 12px; color: var(--muted); text-decoration: none; margin-top: auto; padding: 4px; text-align: center; border: 1px dashed var(--line); border-radius: 8px; }
    .sc-add:hover { color: inherit; border-color: var(--gold); }
    .sc-audit td, .sc-audit th { padding: 6px 10px; text-align: right; font-variant-numeric: tabular-nums; }
    .sc-audit td:first-child, .sc-audit th:first-child { text-align: left; }
    @media (prefers-color-scheme: dark) {
        .sc-post { background: rgba(255,255,255,.04); }
        .sc-tabs a.on { background: var(--gold); color: #0b0b0b; border-color: var(--gold); box-shadow: none; }
    }
</style>

<div class="list-head" style="align-items:center">
    <div class="form-hero" style="flex:1;margin-bottom:0">
        <div class="form-hero-glow"></div>
        <div class="fh-eyebrow">Marketing · Social media</div>
        <div class="fh-title">Social Media Command Centre</div>
        <div class="fh-sub">Plan, post, and see what's working across TikTok, Instagram, Facebook, LinkedIn and Google Business. Nothing posts from here: post in each app, then tap <strong>Posted</strong>.</div>
    </div>
    <a href="{{ route('social.posts.create') }}" class="btn btn-primary" style="margin-left:12px">＋ New post</a>
</div>

<nav class="sc-tabs" aria-label="Social media sections">
    <a href="{{ route('social.index') }}" class="{{ $tab === 'overview' ? 'on' : '' }}">Overview</a>
    <a href="{{ route('social.planner') }}" class="{{ $tab === 'planner' ? 'on' : '' }}">Planner</a>
    <a href="{{ route('social.posts') }}" class="{{ $tab === 'posts' ? 'on' : '' }}">Posts</a>
    @foreach(\App\Models\SocialPost::PLATFORMS as $k => $l)
        <a href="{{ route('social.platform', $k) }}" class="{{ $tab === $k ? 'on' : '' }}"><span class="sc-dot" style="background:{{ $platformColour[$k] }}"></span>{{ $l }}</a>
    @endforeach
</nav>

@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08)">{{ $errors->first() }}</div>@endif
