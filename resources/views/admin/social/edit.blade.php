@extends('layouts.app')
@section('title', $post->exists ? 'Social post' : 'New social post')

@section('content')
@php
    use App\Models\SocialPost;
    $dt = fn ($c) => $c ? $c->format('Y-m-d\TH:i') : '';
@endphp
    @include('admin.social._shell', ['tab' => 'posts'])

    <div class="sc-two">
        <form method="POST" action="{{ $post->exists ? route('social.posts.update', $post) : route('social.posts.store') }}" class="card sc-card sc-form">
            @csrf
            @if($post->exists) @method('PUT') @endif
            <h2>{{ $post->exists ? $post->title : 'New post' }}</h2>
            <p class="sc-sub">Rules: no prices, no customer faces, names or flight details, nothing invented.</p>

            <div class="sc-nums">
                <div class="field"><label for="platform">Platform</label>
                    <select id="platform" name="platform">@foreach(SocialPost::PLATFORMS as $k => $l)<option value="{{ $k }}" @selected(old('platform', $post->platform) === $k)>{{ $l }}</option>@endforeach</select></div>
                <div class="field"><label for="status">Status</label>
                    <select id="status" name="status">@foreach(SocialPost::STATUSES as $k => $l)<option value="{{ $k }}" @selected(old('status', $post->status) === $k)>{{ $l }}</option>@endforeach</select></div>
                <div class="field"><label for="pillar">Content type</label>
                    <select id="pillar" name="pillar"><option value="">—</option>@foreach(SocialPost::PILLARS as $k => $l)<option value="{{ $k }}" @selected(old('pillar', $post->pillar) === $k)>{{ $l }}</option>@endforeach</select></div>
                <div class="field"><label for="format">Format</label>
                    <input id="format" name="format" maxlength="40" list="formats" value="{{ old('format', $post->format) }}" placeholder="Reel">
                    <datalist id="formats"><option value="Reel"><option value="Video"><option value="Carousel"><option value="Photo"><option value="Story"><option value="Image post"><option value="Text post"><option value="Update"></datalist></div>
            </div>

            <div class="field" style="margin-top:12px"><label for="title">Title (for you, not posted)</label>
                <input id="title" name="title" required maxlength="160" value="{{ old('title', $post->title) }}"></div>
            <div class="field"><label for="caption">Caption</label>
                <textarea id="caption" name="caption" maxlength="5000">{{ old('caption', $post->caption) }}</textarea></div>
            <div class="field"><label for="hashtags">Hashtags</label>
                <input id="hashtags" name="hashtags" maxlength="500" value="{{ old('hashtags', $post->hashtags) }}"></div>
            <div class="field"><label for="media_note">What to film / which file</label>
                <textarea id="media_note" name="media_note" maxlength="2000" style="min-height:70px">{{ old('media_note', $post->media_note) }}</textarea></div>

            <div class="sc-nums">
                <div class="field"><label for="scheduled_at">Planned for</label>
                    <input id="scheduled_at" type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', $dt($post->scheduled_at)) }}"></div>
                <div class="field"><label for="posted_at">Posted at</label>
                    <input id="posted_at" type="datetime-local" name="posted_at" value="{{ old('posted_at', $dt($post->posted_at)) }}"></div>
            </div>
            <div class="field" style="margin-top:12px"><label for="url">Link to the live post</label>
                <input id="url" type="url" name="url" maxlength="500" value="{{ old('url', $post->url) }}" placeholder="https://"></div>

            <h3 id="numbers" style="font-size:14px;margin:16px 0 4px">Numbers (from the app's insights)</h3>
            <p class="sc-sub">Fill these in 2 to 3 days after posting. "Enquiries" = people who asked to book because of it.</p>
            <div class="sc-nums">
                @foreach(SocialPost::METRICS as $m)
                    <div class="field"><label for="m-{{ $m }}">{{ ucfirst($m) }}</label>
                        <input id="m-{{ $m }}" type="number" min="0" inputmode="numeric" name="{{ $m }}" value="{{ old($m, $post->{$m}) }}"></div>
                @endforeach
            </div>

            <div class="field" style="margin-top:12px"><label for="notes">Notes</label>
                <textarea id="notes" name="notes" maxlength="2000" style="min-height:60px">{{ old('notes', $post->notes) }}</textarea></div>

            @unless($post->exists)
                <fieldset class="field" style="border:0;padding:0;margin:0 0 12px">
                    <legend style="font-weight:600;font-size:13px;margin-bottom:4px">Also create it for (film once, post everywhere)</legend>
                    <div class="sc-actions">@foreach(SocialPost::PLATFORMS as $k => $l)<label style="font-size:13px;display:inline-flex;gap:5px;align-items:center"><input type="checkbox" name="also[]" value="{{ $k }}"> {{ $l }}</label>@endforeach</div>
                </fieldset>
            @endunless

            <div class="sc-actions">
                <button class="btn btn-primary">Save</button>
                <a class="btn btn-ghost" href="{{ route('social.planner') }}">Back to planner</a>
            </div>
        </form>

        <div style="display:flex;flex-direction:column;gap:14px">
            @if($post->exists)
                <div class="card sc-card">
                    <h2>Ready to paste</h2>
                    <p class="sc-sub">Copy, open {{ $post->platformLabel() }}, paste, post. Then tap Posted.</p>
                    <pre class="sc-copy" id="copytext">{{ $post->copyText() ?: 'No caption yet.' }}</pre>
                    <div class="sc-actions" style="margin-top:10px">
                        <button type="button" class="btn btn-ghost" id="copybtn">Copy caption</button>
                        @if($post->status !== 'posted')
                            <form method="POST" action="{{ route('social.posts.posted', $post) }}" class="sc-inline">@csrf<button class="btn btn-primary">✓ Posted</button></form>
                        @elseif($post->url)
                            <a class="btn btn-ghost" href="{{ $post->url }}" target="_blank" rel="noopener">Open live post ↗</a>
                        @endif
                    </div>
                    @if($post->media_note)<p style="margin:12px 0 0;font-size:13px"><strong>Media:</strong> {{ $post->media_note }}</p>@endif
                </div>
            @endif

            @if($audit)
                <div class="card sc-card" id="audit">
                    <h2>Audit</h2>
                    <p class="sc-sub">This post against the average of {{ $audit['baseline'] }} other {{ $post->platformLabel() }} post{{ $audit['baseline'] === 1 ? '' : 's' }} with numbers.</p>
                    <div class="table-scroll">
                        <table class="sc-audit" style="width:100%;border-collapse:collapse;font-size:13px">
                            <thead><tr><th>Metric</th><th>This post</th><th>Average</th><th>Diff</th></tr></thead>
                            <tbody>
                            @foreach($audit['rows'] as $r)
                                <tr>
                                    <td>{{ $r['metric'] }}</td>
                                    <td>{{ $r['value'] !== null ? number_format($r['value']) : '—' }}</td>
                                    <td>{{ $r['avg'] !== null ? number_format($r['avg']) : '—' }}</td>
                                    <td>@if($r['diff'] !== null)<span class="{{ $r['diff'] >= 0 ? 'sc-up' : 'sc-down' }}" style="font-weight:700">{{ $r['diff'] >= 0 ? '+' : '' }}{{ $r['diff'] }}%</span>@else — @endif</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @foreach($audit['verdict'] as $v)<p style="margin:10px 0 0;font-size:13.5px">{{ $v }}</p>@endforeach
                </div>
            @endif

            @if($post->exists)
                <form method="POST" action="{{ route('social.posts.destroy', $post) }}" onsubmit="return confirm('Delete this post from the planner? (It does not touch the live post.)')">
                    @csrf @method('DELETE')
                    <button class="btn btn-ghost" style="color:#b32020">Delete from planner</button>
                </form>
            @endif
        </div>
    </div>

    <script>
        (function () {
            var b = document.getElementById('copybtn'), t = document.getElementById('copytext');
            if (!b || !t) return;
            b.addEventListener('click', function () {
                var done = function () { b.textContent = 'Copied'; setTimeout(function () { b.textContent = 'Copy caption'; }, 1600); };
                if (navigator.clipboard) { navigator.clipboard.writeText(t.textContent).then(done); }
                else { var r = document.createRange(); r.selectNodeContents(t); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); document.execCommand('copy'); done(); }
            });
        })();
    </script>
@endsection
