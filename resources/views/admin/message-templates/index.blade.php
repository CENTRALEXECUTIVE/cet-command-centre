@extends('layouts.app')
@section('title', 'Message templates')

@section('content')
    <div class="list-head" style="align-items:center">
        <div class="form-hero" style="flex:1;margin-bottom:0">
            <div class="form-hero-glow"></div>
            <div class="fh-eyebrow">Fleet &amp; admin · messaging</div>
            <div class="fh-title">Message templates</div>
            <div class="fh-sub">Edit the wording of the notifications you send customers and drivers.</div>
        </div>
    </div>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    <div class="card" style="background:var(--cream,#fbfaf6)">
        <strong>Placeholders</strong> — these get filled in automatically:
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
            @foreach($placeholders as $token => $desc)
                <span class="badge" style="background:#0b0b0c;color:#FBBA2A" title="{{ $desc }}">{{ $token }}</span>
            @endforeach
        </div>
    </div>

    @foreach($templates as $key => $t)
        <div class="card">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
                <h2 style="margin:0;font-size:16px">{{ $t['label'] }}
                    @if($t['customised'])<span class="badge" style="background:#1f8b4c;color:#fff;margin-left:6px">Customised</span>
                    @else<span class="badge" style="margin-left:6px">Default</span>@endif
                </h2>
                @if($t['customised'])
                    <form method="POST" action="{{ route('message-templates.update', $key) }}" onsubmit="return confirm('Reset {{ $t['label'] }} to the default wording?')" style="margin:0">
                        @csrf @method('PUT')
                        <input type="hidden" name="reset" value="1">
                        <button class="btn btn-ghost" style="padding:6px 12px;font-size:13px">Reset to default</button>
                    </form>
                @endif
            </div>
            <form method="POST" action="{{ route('message-templates.update', $key) }}" style="margin-top:10px">
                @csrf @method('PUT')
                <textarea name="text" rows="7" style="width:100%;font-family:inherit;font-size:14px;line-height:1.5">{{ $t['text'] }}</textarea>
                <button class="btn btn-primary" style="padding:9px 18px;margin-top:8px">Save {{ $t['label'] }}</button>
            </form>
        </div>
    @endforeach
@endsection
