@extends('layouts.app')
@section('title', 'AI phone setup')

@section('content')
    <p><a href="{{ route('phone-calls.index') }}">&larr; AI phone calls</a></p>
    <h1 class="page-title">Set up the human-sounding voice (ElevenLabs)</h1>
    <p class="page-sub">ElevenLabs gives the agent its voice and ears. The Command Centre stays the brain: prices, booking requests, messages and the call log. Copy each part below into your ElevenLabs agent. These links contain your webhook secret, so don't share this page.</p>
    @unless($hasSecret)<div class="alert alert-danger">CET_WEBHOOK_SECRET isn't set in .env, so the links below won't work yet.</div>@endunless

    <div class="card" style="margin-bottom:16px">
        <h2>1. Create the agent</h2>
        <p>In ElevenLabs open <strong>Agents</strong>, then <strong>New agent</strong>, then <strong>Blank</strong>. Name it "CET phone". Set the language to English and choose a British voice. A calm, warm female voice suits "Sophie".</p>
        <p class="hint">First message:</p>
        <textarea readonly rows="2" style="width:100%" onclick="this.select()">{{ $firstMessage }}</textarea>
        <p class="hint" style="margin-top:12px">System prompt (click inside, copy all, paste into the agent's System prompt box):</p>
        <textarea readonly rows="16" style="width:100%;font-size:13px" onclick="this.select()">{{ $prompt }}</textarea>
    </div>

    <div class="card" style="margin-bottom:16px">
        <h2>2. Add the tools</h2>
        <p>In the agent, under <strong>Tools</strong>, add each of these as a <strong>Webhook</strong> tool. Method <strong>POST</strong>. Every tool also gets two extra body parameters set as <strong>dynamic variables</strong>: <code>caller_id</code> = <code>system__caller_id</code> and <code>conversation_id</code> = <code>system__conversation_id</code>.</p>
        @foreach($tools as $t)
            <div style="border-top:1px solid rgb(0 0 0 / .1);padding:14px 0">
                <p style="margin:0 0 6px"><strong>{{ $t['name'] }}</strong>: {{ $t['description'] }}</p>
                <input readonly value="{{ $t['url'] }}" style="width:100%;font-size:13px" onclick="this.select()">
                @if($t['params'])
                    <div style="overflow-x:auto;margin-top:8px"><table class="table" style="font-size:13px">
                        <thead><tr><th>Body parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
                        <tbody>
                        @foreach($t['params'] as $p)
                            <tr><td><code>{{ $p['name'] }}</code></td><td>{{ $p['type'] }}</td><td>{{ $p['required'] ? 'Yes' : '' }}</td>
                                <td>{{ $p['description'] }}@if($p['enum']) One of: {{ implode(', ', $p['enum']) }}.@endif</td></tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="card" style="margin-bottom:16px">
        <h2>3. Hand-over and hang-up</h2>
        <p>Turn on the built-in <strong>End call</strong> tool. Add the built-in <strong>Transfer to number</strong> tool with these numbers and the condition "The caller asks for a person, has a complaint, asks about an existing booking, or you are not sure":</p>
        <ul>@forelse($transfer as $n)<li>{{ $n }}</li>@empty<li class="muted">No director numbers found. Add phones to the director accounts, or set CET_AI_VOICE_TRANSFER.</li>@endforelse</ul>
    </div>

    <div class="card" style="margin-bottom:16px">
        <h2>4. Call log</h2>
        <p>In ElevenLabs <strong>Settings</strong>, add a <strong>post-call webhook</strong> (transcription) pointing at:</p>
        <input readonly value="{{ $postCall }}" style="width:100%;font-size:13px" onclick="this.select()">
        <p class="hint">Every call then appears under AI phone calls with its transcript. Booking requests and messages alert you the moment they're taken.</p>
    </div>

    <div class="card">
        <h2>5. Connect your Twilio number</h2>
        <p>In ElevenLabs open <strong>Phone numbers</strong>, then <strong>Import from Twilio</strong>. Enter the spare number, plus a Twilio API key SID and secret (Twilio Console, then Account, then API keys). Then assign the "CET phone" agent to inbound calls on that number. ElevenLabs sets the number up in Twilio for you.</p>
        <p class="mb-0">Then ring it and ask for a quote, for example "Sheffield S1 to Manchester Airport, 6am next Tuesday, executive".</p>
    </div>
@endsection
