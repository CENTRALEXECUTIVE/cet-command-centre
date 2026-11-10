@extends('layouts.app')
@section('title', 'Claude access')

@section('content')
    <div class="form-hero">
        <div class="form-hero-glow"></div>
        <div class="fh-eyebrow">Settings · security</div>
        <div class="fh-title">Claude access (read-only)</div>
        <div class="fh-sub">Lets Claude read the outreach report: today's batch, what was sent, replies and what's due. It can't see bookings, customers, phone numbers or money, and it can't change or send anything.</div>
    </div>

    @if(session('status'))
        <div class="card" style="border-left:4px solid #1f7a45">{{ session('status') }}</div>
    @endif

    @if($newKey)
        <div class="card" style="border:2px solid #FBBA2A">
            <h2 style="margin:0 0 6px">Your new key (shown once)</h2>
            <p class="hint" style="margin:0 0 10px">Copy it now. Paste it only into the secure form Claude shows you in the Claude app, never into the chat. If you lose it, make a new one.</p>
            <input id="claude-key" readonly value="{{ $newKey }}" style="width:100%;font-family:monospace;font-size:14px;padding:10px" onclick="this.select()">
            <button type="button" class="btn btn-primary" style="margin-top:10px" onclick="var i=document.getElementById('claude-key');i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value);this.textContent='Copied'">Copy key</button>
        </div>
    @endif

    <div class="card">
        <h2 style="margin:0 0 6px">Status: {{ $enabled ? 'On' : 'Off' }}</h2>
        @if($enabled && $createdAt)
            <p class="hint" style="margin:0 0 12px">Key created {{ $createdAt }}.</p>
        @endif
        <p class="hint" style="margin:0 0 12px">Report address: <code>{{ route('claude.report.outreach') }}</code></p>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <form method="POST" action="{{ route('claude-access.generate') }}">
                @csrf
                <button class="btn btn-primary" type="submit">{{ $enabled ? 'Make a new key (the old one stops working)' : 'Turn on and make a key' }}</button>
            </form>
            @if($enabled)
                <form method="POST" action="{{ route('claude-access.revoke') }}">
                    @csrf
                    <button class="btn btn-light" type="submit">Turn off</button>
                </form>
            @endif
        </div>
    </div>
@endsection
