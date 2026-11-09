@extends('layouts.app')
@section('title', "Today's outreach batch")

@section('content')
    @include('admin.outreach._style')

    <div class="list-head" style="align-items:center">
        <div class="form-hero" style="flex:1;margin-bottom:0">
            <div class="form-hero-glow"></div>
            <div class="fh-eyebrow">Sales · Outreach</div>
            <div class="fh-title">Today's batch</div>
            <div class="fh-sub">Check each email, untick any you don't want, then approve. They send from admin@ with the Matthew Green signature. Weekdays only, up to {{ $limit }} a day, mostly new: at most {{ \App\Services\Outreach\OutreachBatch::maxFollowUps() }} follow-ups (the rest wait for the next day).</div>
        </div>
    </div>

    <p><a href="{{ route('outreach.index') }}">← Outreach</a></p>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08)">{{ $errors->first() }}</div>@endif

    <div class="card" style="display:flex;gap:16px;flex-wrap:wrap;align-items:center">
        <div><strong>{{ $sentToday }}</strong> of {{ $limit }} sent today</div>
        <div><strong>{{ $items->count() }}</strong> ready to send</div>
        @if($weekend)<div style="color:#b32020"><strong>It's the weekend.</strong> Batches send Monday to Friday, when people read business email.</div>@endif
    </div>

    @if($items->isEmpty())
        <div class="card">Nothing to send right now. Either today's {{ $limit }} have gone, or no prospects with an email address are due.</div>
    @else
        <form method="POST" action="{{ route('outreach.batch.send') }}">
            @csrf
            @foreach($items as $i)
                @php($p = $i['prospect'])
                <div class="card" style="margin-bottom:12px">
                    <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer">
                        <input type="checkbox" name="ids[]" value="{{ $p->id }}" checked style="margin-top:4px;width:20px;height:20px">
                        <span style="flex:1;min-width:0">
                            <strong>{{ $p->company }}</strong>
                            <span class="muted" style="font-size:12px"> · {{ $i['mail']['stage'] }} · to {{ $p->contact_email }}@if($p->cc_email), cc {{ $p->cc_email }}@endif</span>
                            <div style="margin-top:6px"><strong>Subject:</strong> {{ $i['mail']['subject'] }}</div>
                        </span>
                    </label>
                    <details style="margin-top:8px">
                        <summary style="cursor:pointer">Read the email</summary>
                        <pre style="white-space:pre-wrap;font-family:inherit;font-size:14px;margin:8px 0 0">{{ $i['mail']['body'] }}

Matthew Green
Central Executive Transfers
0114 399 8242
www.centralexecutivetransfers.co.uk
[CENTRAL logo]</pre>
                    </details>
                    <div style="margin-top:6px;font-size:12px"><a href="{{ route('outreach.show', $p) }}">Open prospect / edit draft →</a></div>
                </div>
            @endforeach

            <div class="card" style="position:sticky;bottom:0;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
                <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="confirm" value="1" style="width:20px;height:20px"> I have checked these emails</label>
                <button type="submit" class="btn btn-primary" @if($weekend) disabled @endif>Approve &amp; send ticked emails</button>
            </div>
        </form>
    @endif
@endsection
