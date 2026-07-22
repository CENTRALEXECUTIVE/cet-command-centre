@extends('layouts.app')
@section('title', 'Marketing Studio')

@section('content')
    <h1 class="page-title" style="margin-bottom:2px">🎨 Marketing Studio</h1>
    <p class="page-sub">Generate on-brand social posts, ads, a weekly plan and SEO copy — powered by your Claude AI. Generate, tweak, post.</p>

    @if(! $available)
        <div class="card" style="border-left:4px solid #b8860b;background:rgba(184,134,11,.08)">
            <strong>AI isn’t switched on yet.</strong>
            <p class="hint" style="margin:6px 0 0">The Studio needs the Anthropic API key set (<span class="mono">ANTHROPIC_API_KEY</span>) — the same one the pricing and paste-a-booking features use. Add it and this page comes alive.</p>
        </div>
    @else
        <form method="POST" action="{{ route('marketing.studio.generate') }}" class="card">
            @csrf
            <div class="grid grid-2">
                <div class="field">
                    <label for="type">What do you want to create?</label>
                    <select id="type" name="type">
                        @foreach($types as $key => $label)
                            <option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="topic">Topic / focus <span class="muted">(optional)</span></label>
                    <input id="topic" name="topic" value="{{ old('topic', $topic) }}" placeholder="e.g. Christmas parties, Manchester Airport, corporate accounts" maxlength="160">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">✨ Generate</button>
            <span class="hint" style="margin-left:8px">Takes a few seconds.</span>
        </form>

        @if(($failed ?? false) && $result === null)
            <div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.06)">
                Couldn’t generate that just now — please try again.
            </div>
        @endif

        @if($result)
            <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px;margin:6px 0 10px">
                <h2 style="margin:0">{{ $types[$type] }}</h2>
                <span class="muted" style="font-size:13px">Tap <strong>Copy</strong> on anything to grab it.</span>
            </div>

            @if($type === 'social_posts' && !empty($result['posts']))
                @foreach($result['posts'] as $p)
                    <div class="card studio-item">
                        <div class="copytext" style="white-space:pre-line;font-size:15px">{{ $p['caption'] ?? '' }}

{{ $p['hashtags'] ?? '' }}</div>
                        @if(!empty($p['image_idea']))<p class="hint" style="margin:8px 0 0">📷 {{ $p['image_idea'] }}</p>@endif
                        <button type="button" class="btn btn-light studio-copy" style="margin-top:10px;padding:6px 14px;font-size:13px">⧉ Copy</button>
                    </div>
                @endforeach
            @elseif($type === 'ad_copy' && !empty($result['ads']))
                @foreach($result['ads'] as $a)
                    <div class="card studio-item">
                        <div class="copytext">
                            <strong>{{ $a['headline'] ?? '' }}</strong>@if(!empty($a['long_headline'])) — {{ $a['long_headline'] }}@endif
                            <div style="margin-top:4px">{{ $a['description'] ?? '' }}</div>
                            @if(!empty($a['primary_text']))<div style="margin-top:6px;color:#555">{{ $a['primary_text'] }}</div>@endif
                        </div>
                        <button type="button" class="btn btn-light studio-copy" style="margin-top:10px;padding:6px 14px;font-size:13px">⧉ Copy</button>
                    </div>
                @endforeach
            @elseif($type === 'content_calendar' && !empty($result['days']))
                <div class="card">
                    @foreach($result['days'] as $d)
                        <div class="studio-item" style="padding:10px 0;border-bottom:1px solid rgba(128,128,128,.12)">
                            <div class="copytext"><strong>{{ $d['day'] ?? '' }}</strong>@if(!empty($d['theme'])) · {{ $d['theme'] }}@endif
                                <div class="muted" style="font-size:13px;margin:2px 0 4px">{{ $d['idea'] ?? '' }}</div>
                                <div style="white-space:pre-line">{{ $d['caption'] ?? '' }}</div>
                            </div>
                            <button type="button" class="btn btn-light studio-copy" style="margin-top:8px;padding:5px 12px;font-size:12px">⧉ Copy caption</button>
                        </div>
                    @endforeach
                </div>
            @elseif($type === 'seo_snippet' && !empty($result['title']))
                <div class="card studio-item">
                    <div class="copytext">
                        <p class="hint" style="margin:0 0 6px">🎯 Target: <strong>{{ $result['target_keyword'] ?? '' }}</strong></p>
                        <strong>Title:</strong> {{ $result['title'] ?? '' }}<br>
                        <strong>Meta description:</strong> {{ $result['meta_description'] ?? '' }}<br>
                        <strong>H1:</strong> {{ $result['heading'] ?? '' }}
                        <div style="white-space:pre-line;margin-top:8px">{{ $result['body'] ?? '' }}</div>
                    </div>
                    <button type="button" class="btn btn-light studio-copy" style="margin-top:10px;padding:6px 14px;font-size:13px">⧉ Copy</button>
                </div>
            @else
                <div class="card muted">Nothing came back in the expected format — try generating again.</div>
            @endif
        @endif
    @endif

    @verbatim
    <script>
        document.querySelectorAll('.studio-copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var box = btn.closest('.studio-item').querySelector('.copytext');
                var text = box ? box.innerText : '';
                navigator.clipboard.writeText(text).then(function () {
                    var old = btn.textContent; btn.textContent = '✓ Copied';
                    setTimeout(function () { btn.textContent = old; }, 1500);
                }).catch(function () {});
            });
        });
    </script>
    @endverbatim
@endsection
