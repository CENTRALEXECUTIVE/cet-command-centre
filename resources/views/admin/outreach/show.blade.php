@extends('layouts.app')
@section('title', $p->company.' · Outreach')

@php
    // Only ever render http(s) links — never a javascript:/data: URL from a CSV.
    $safeUrl = function (?string $u) {
        $u = trim((string) $u);
        if ($u === '') return null;
        if (! preg_match('#^https?://#i', $u)) $u = 'https://'.ltrim($u, '/');
        return filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $u) ? $u : null;
    };
    $dnc = $p->isDoNotContact();
@endphp

@section('content')
    @include('admin.outreach._style')

    <p style="margin:0 0 10px"><a href="{{ route('outreach.index') }}">← Outreach</a></p>

    <div class="form-hero" style="margin-bottom:14px">
        <div class="form-hero-glow"></div>
        <div class="fh-eyebrow">Outreach · {{ $p->segment ?: 'Prospect' }}{{ $p->fit_score ? ' · Fit '.$p->fit_score.'/5' : '' }}</div>
        <div class="fh-title">{{ $p->company }}</div>
        <div class="fh-sub">
            @include('admin.outreach._badge', ['status' => $p->status])
            {{ $p->town }}
            · Owner: {{ $p->owner?->name ?? 'unassigned' }}
            · Next action: {{ $p->next_action_at?->format('D j M Y') ?? '—' }}
            · Last contacted: {{ $p->last_contacted_at?->format('j M Y H:i') ?? 'never' }}
        </div>
    </div>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08)"><ul style="margin:0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    @if($dnc)
        <div class="card ox-dnc"><strong>⛔ Do not contact.</strong> This prospect asked not to be contacted. Email buttons are switched off permanently and a re-import will never re-open them.</div>
    @elseif($p->suggestCloseNoReply())
        <div class="card ox-due">
            <strong>No reply after the first email and both follow-ups.</strong> Suggest closing this as "no reply".
            <form method="POST" action="{{ route('outreach.status', $p) }}" style="display:inline-block;margin:8px 0 0">
                @csrf <input type="hidden" name="status" value="closed_no_reply">
                <button class="btn btn-primary">Close — no reply</button>
            </form>
        </div>
    @endif

    <div class="grid grid-2" style="gap:14px;align-items:start">
        {{-- Drafted first email --}}
        <div class="card">
            <h2 style="margin:0 0 8px">✉ Drafted email</h2>
            @if(! $dnc)
                <p class="ox-subject" id="ox-subj-first">{{ $first['subject'] ?: '(no subject)' }}</p>
                <pre class="ox-email" id="ox-body-first">{{ $first['body'] ?: '(no draft yet — add one under Edit below)' }}</pre>
                <div class="ox-actions">
                    @if($first['mailto'])
                        <a href="{{ $first['mailto'] }}" class="btn btn-primary" data-ox-mailto>✉ Open email</a>
                    @else
                        <span class="muted" style="font-size:13px;align-self:center">Add a contact email to enable “Open email”.</span>
                    @endif
                    <button type="button" class="btn btn-ghost" data-copy="ox-body-first">⧉ Copy email</button>
                    <button type="button" class="btn btn-ghost" data-copy="ox-subj-first">⧉ Copy subject</button>
                    @if($p->markEmailedLabel())
                        <form method="POST" action="{{ route('outreach.emailed', $p) }}">
                            @csrf <button class="btn btn-dark">✓ {{ $p->markEmailedLabel() }}</button>
                        </form>
                    @endif
                </div>
                <p class="muted" style="font-size:12px;margin:0">“Open email” opens your own mail app with this filled in — you press Send. Nothing is sent from the Command Centre. Then tap ✓ to log it and schedule the follow-up.</p>
            @else
                <p class="muted" style="margin:0">Hidden — do not contact.</p>
            @endif
        </div>

        {{-- Key details --}}
        <div class="card">
            <h2 style="margin:0 0 4px">🏢 Details</h2>
            <dl class="ox-meta" style="margin:0">
                <dt>Contact</dt>
                <dd>{{ $p->contact_name ?: '—' }}{{ $p->contact_role ? ' · '.$p->contact_role : '' }}</dd>
                <dt>Email / phone</dt>
                <dd>{{ $dnc ? '—' : ($p->contact_email ?: '—') }}{{ $p->contact_phone ? ' · '.$p->contact_phone : '' }}</dd>
                <dt>Why they need us</dt><dd>{{ $p->why_they_need_us ?: '—' }}</dd>
                <dt>Angle</dt><dd>{{ $p->angle ?: '—' }}</dd>
                <dt>Links</dt>
                <dd>
                    @foreach(['Website' => $p->website, 'LinkedIn' => $p->linkedin_url, 'Source' => $p->source_url] as $label => $url)
                        @if($href = $safeUrl($url))<a href="{{ $href }}" target="_blank" rel="noopener noreferrer">{{ $label }} ↗</a>&nbsp; @endif
                    @endforeach
                </dd>
            </dl>
        </div>
    </div>

    {{-- Quick status --}}
    @if(! $dnc)
        <div class="card">
            <h2 style="margin:0 0 6px">Quick status</h2>
            <div class="ox-actions">
                @foreach($quick as $s)
                    <form method="POST" action="{{ route('outreach.status', $p) }}"
                          @if($s === 'do_not_contact') onsubmit="return confirm('Mark this prospect as DO NOT CONTACT? This is permanent.')" @endif>
                        @csrf <input type="hidden" name="status" value="{{ $s }}">
                        <button class="btn {{ $s === 'do_not_contact' ? 'btn-ghost' : ($p->status === $s ? 'btn-primary' : 'btn-light') }}"
                                @if($s === 'do_not_contact') style="color:#b32020" @endif>
                            {{ ['replied' => '💬 Replied', 'meeting' => '📅 Meeting booked', 'account_opened' => '✅ Account opened', 'not_interested' => '✖ Not interested', 'do_not_contact' => '⛔ Do not contact'][$s] }}
                        </button>
                    </form>
                @endforeach
            </div>
        </div>

        {{-- Follow-up templates --}}
        <div class="card">
            <h2 style="margin:0 0 6px">↻ Follow-ups</h2>
            <p class="muted" style="margin:0 0 10px;font-size:13px">Day 4 after the first email, then day 10. Personalise the first line if you can.</p>
            <div class="grid grid-2" style="gap:14px">
                @foreach($followUps as $key => $f)
                    <div>
                        <h3 style="margin:0 0 6px;font-size:15px">{{ $f['label'] }}</h3>
                        <p class="ox-subject" id="ox-subj-{{ $key }}">{{ $f['subject'] }}</p>
                        <pre class="ox-email" id="ox-body-{{ $key }}">{{ $f['body'] }}</pre>
                        <div class="ox-actions">
                            @if($f['mailto'])<a href="{{ $f['mailto'] }}" class="btn btn-primary">✉ Open email</a>@endif
                            <button type="button" class="btn btn-ghost" data-copy="ox-body-{{ $key }}">⧉ Copy email</button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid grid-2" style="gap:14px;align-items:start">
        {{-- Activity + notes --}}
        <div class="card">
            <h2 style="margin:0 0 8px">🕘 Activity</h2>
            <form method="POST" action="{{ route('outreach.note', $p) }}" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
                @csrf
                <input name="body" placeholder="Log a note — e.g. rang reception, PA is Sarah" required maxlength="2000" style="flex:1 1 200px;min-width:0">
                <button class="btn btn-light">Add note</button>
            </form>
            <ul class="ox-log">
                @forelse($p->activities as $a)
                    <li>
                        <span class="muted" style="font-size:12px">{{ $a->created_at->format('j M Y H:i') }} · {{ $a->user?->name ?? 'System' }} · {{ $a->type }}</span><br>
                        {{ $a->body }}
                    </li>
                @empty
                    <li class="muted">No activity yet.</li>
                @endforelse
            </ul>
        </div>

        {{-- Edit all fields --}}
        <div class="card">
            <h2 style="margin:0 0 8px">✎ Edit</h2>
            <form method="POST" action="{{ route('outreach.update', $p) }}" class="ox-form">
                @csrf @method('PUT')
                <div class="grid grid-2" style="gap:10px">
                    @foreach([
                        'company' => 'Company', 'town' => 'Town', 'segment' => 'Segment', 'website' => 'Website',
                        'contact_name' => 'Contact name', 'contact_role' => 'Contact role',
                        'contact_email' => 'Contact email', 'contact_phone' => 'Contact phone',
                        'linkedin_url' => 'LinkedIn URL', 'source_url' => 'Source URL',
                    ] as $f => $label)
                        <div class="field"><label>{{ $label }}</label>
                            <input name="{{ $f }}" value="{{ old($f, $p->{$f}) }}" @if($f === 'company') required @endif @if($f === 'contact_email') type="email" @endif>
                        </div>
                    @endforeach
                    <div class="field"><label>Fit (1–5)</label>
                        <select name="fit_score">
                            <option value="">—</option>
                            @foreach([5,4,3,2,1] as $n)<option value="{{ $n }}" @selected((int) old('fit_score', $p->fit_score) === $n)>{{ $n }}</option>@endforeach
                        </select>
                    </div>
                    <div class="field"><label>Status</label>
                        <select name="status" @disabled($dnc)>
                            @foreach(\App\Models\Prospect::STATUSES as $k => $label)
                                <option value="{{ $k }}" @selected(old('status', $p->status) === $k)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @if($dnc)<input type="hidden" name="status" value="do_not_contact">@endif
                    </div>
                    <div class="field"><label>Owner</label>
                        <select name="owner_id">
                            <option value="">Unassigned</option>
                            @foreach($owners as $o)<option value="{{ $o->id }}" @selected((int) old('owner_id', $p->owner_id) === $o->id)>{{ $o->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="field"><label>Next action</label>
                        <input type="date" name="next_action_at" value="{{ old('next_action_at', $p->next_action_at?->format('Y-m-d')) }}">
                    </div>
                </div>
                <div class="field"><label>Why they need us</label><textarea name="why_they_need_us">{{ old('why_they_need_us', $p->why_they_need_us) }}</textarea></div>
                <div class="field"><label>Angle</label><textarea name="angle">{{ old('angle', $p->angle) }}</textarea></div>
                <div class="field"><label>Email subject</label><input name="email_subject" value="{{ old('email_subject', $p->email_subject) }}"></div>
                <div class="field"><label>Email body</label><textarea name="email_body" style="min-height:220px">{{ old('email_body', $p->email_body) }}</textarea></div>
                <div class="field"><label>Notes</label><textarea name="notes">{{ old('notes', $p->notes) }}</textarea></div>
                <button class="btn btn-primary">Save</button>
            </form>
        </div>
    </div>

    <script>
        (function () {
            function copyText(text, btn) {
                var done = function () { var t = btn.textContent; btn.textContent = '✓ Copied'; setTimeout(function () { btn.textContent = t; }, 1500); };
                var fallback = function () {
                    var ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
                    document.body.appendChild(ta); ta.select();
                    try { document.execCommand('copy'); done(); } catch (e) {}
                    document.body.removeChild(ta);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done).catch(fallback);
                } else { fallback(); }
            }
            document.querySelectorAll('[data-copy]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var el = document.getElementById(btn.getAttribute('data-copy'));
                    if (el) copyText(el.textContent, btn);
                });
            });
        })();
    </script>
@endsection
