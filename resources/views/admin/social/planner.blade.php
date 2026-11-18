@extends('layouts.app')
@section('title', 'Social planner')

@section('content')
@php use App\Models\SocialPost; @endphp
    @include('admin.social._shell', ['tab' => 'planner'])

    <div class="sc-actions" style="justify-content:space-between;margin-bottom:10px">
        <div class="sc-actions">
            <a class="btn btn-ghost" href="{{ route('social.planner', ['week' => $start->copy()->subWeek()->toDateString()]) }}" aria-label="Previous week">←</a>
            <strong style="font-size:15px">Week of {{ $start->format('j M Y') }}</strong>
            <a class="btn btn-ghost" href="{{ route('social.planner', ['week' => $start->copy()->addWeek()->toDateString()]) }}" aria-label="Next week">→</a>
            @unless($start->isSameDay(now()->startOfWeek(\Illuminate\Support\Carbon::MONDAY)))<a class="btn btn-ghost" href="{{ route('social.planner') }}">This week</a>@endunless
        </div>
        <span class="muted" style="font-size:13px">{{ $consistency['done'] }} of {{ $consistency['goal'] }} posted this week</span>
    </div>

    <div class="sc-week">
        @foreach($days as $d)
            <div class="sc-col {{ $d['date']->isToday() ? 'today' : '' }}">
                <h3><span>{{ $d['date']->format('D') }}</span><span>{{ $d['date']->format('j M') }}</span></h3>
                @foreach($d['posts'] as $p)
                    <a href="{{ route('social.posts.edit', $p) }}" class="sc-post {{ $p->status === 'posted' ? 'posted' : '' }}" style="--c: {{ $p->colour() }}">
                        <small>{{ $p->when()->format('H:i') }} · {{ $p->platformLabel() }}</small>
                        <b>{{ $p->title }}</b>
                        <span class="sc-chip sc-st-{{ $p->status }}" style="margin-top:4px">{{ $p->status === 'posted' ? '✓ Posted' : $p->statusLabel() }}</span>
                    </a>
                @endforeach
                <a class="sc-add" href="{{ route('social.posts.create', ['date' => $d['date']->toDateString()]) }}">＋ Add</a>
            </div>
        @endforeach
    </div>

    <div class="sc-two" style="margin-top:14px">
        <div class="card sc-card">
            <h2>Ideas bank ({{ $ideas->count() }})</h2>
            <p class="sc-sub">Open an idea, give it a date and set it to Planned to put it on the calendar.</p>
            @if($ideas->count())
                <ul class="sc-list">
                    @foreach($ideas as $p)
                        <li><span class="t"><span class="sc-dot" style="background:{{ $p->colour() }}"></span> <a href="{{ route('social.posts.edit', $p) }}">{{ $p->title }}</a>
                            <span class="m">· {{ $p->platformLabel() }}@if($p->pillarLabel()), {{ $p->pillarLabel() }}@endif</span></span></li>
                    @endforeach
                </ul>
            @else
                <p class="muted" style="margin:0">No ideas saved yet.</p>
            @endif
        </div>
        <div class="card sc-card">
            <h2>Get fresh ideas from Claude</h2>
            <p class="sc-sub">Claude looks at what's done best so far and suggests 6 ideas with a hook and what to film. They land in the ideas bank; nothing is posted.</p>
            @if($aiReady)
                <form method="POST" action="{{ route('social.ideas') }}">@csrf
                    <div class="field"><label for="topic">Focus (optional)</label>
                        <input id="topic" name="topic" maxlength="200" placeholder="e.g. Christmas parties, Manchester Airport, weddings" style="width:100%;box-sizing:border-box"></div>
                    <button class="btn btn-primary">Suggest 6 ideas</button>
                </form>
            @else
                <p class="muted" style="margin:0">Claude isn't connected on this server (no AI key set).</p>
            @endif
            @unless(app(\App\Services\Social\SocialStarterPack::class)->alreadyLoaded())
                <form method="POST" action="{{ route('social.starter') }}" style="margin-top:12px">@csrf
                    <button class="btn btn-ghost">Load the 2-week starter plan</button>
                </form>
            @endunless
        </div>
    </div>
@endsection
