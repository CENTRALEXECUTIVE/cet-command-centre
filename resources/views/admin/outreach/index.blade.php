@extends('layouts.app')
@section('title', 'Outreach')

@section('content')
    @include('admin.outreach._style')

    <div class="list-head" style="align-items:center">
        <div class="form-hero" style="flex:1;margin-bottom:0">
            <div class="form-hero-glow"></div>
            <div class="fh-eyebrow">Sales · Outreach</div>
            <div class="fh-title">Outreach</div>
            <div class="fh-sub">Business prospects and follow-ups. Emails open in your own mail app — nothing is sent from here.</div>
        </div>
    </div>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08)">{{ $errors->first() }}</div>@endif

    {{-- Stats --}}
    <div class="deck ox-stats" style="grid-template-columns:repeat(auto-fit,minmax(130px,1fr));margin-bottom:18px">
        <div class="kpi"><div class="kpi-ico">🏢</div><div class="kpi-n">{{ $stats['total'] }}</div><div class="kpi-l">Prospects</div></div>
        <div class="kpi"><div class="kpi-ico">✉</div><div class="kpi-n">{{ $stats['emailed_week'] }}</div><div class="kpi-l">Emailed this week</div></div>
        <div class="kpi"><div class="kpi-ico">💬</div><div class="kpi-n">{{ $stats['replies'] }}</div><div class="kpi-l">Replies</div></div>
        <div class="kpi"><div class="kpi-ico">📅</div><div class="kpi-n">{{ $stats['meetings'] }}</div><div class="kpi-l">Meetings / proposals</div></div>
        <div class="kpi ok"><div class="kpi-ico">✅</div><div class="kpi-n">{{ $stats['accounts'] }}</div><div class="kpi-l">Accounts opened</div></div>
        <div class="kpi"><div class="kpi-ico">📈</div><div class="kpi-n">{{ $stats['reply_rate'] }}%</div><div class="kpi-l">Reply rate ({{ $stats['contacted'] }} contacted)</div></div>
    </div>

    {{-- Follow-ups due today --}}
    <div class="card ox-due" id="due">
        <h2 style="margin:0 0 4px">⏰ Follow-ups due today <span class="badge" style="background:#FBBA2A;color:#000">{{ $due->count() }}</span></h2>
        <p class="muted" style="margin:0 0 10px;font-size:13px">Next action today or overdue, and not closed.</p>
        @forelse($due as $d)
            <a href="{{ route('outreach.show', $d) }}" class="ox-due-row">
                <div class="ox-due-main">
                    <strong>{{ $d->company }}</strong>
                    <span class="muted">{{ $d->contact_name ?: $d->contact_role }}{{ $d->town ? ' · '.$d->town : '' }}</span>
                </div>
                <div class="ox-due-side">
                    @include('admin.outreach._badge', ['status' => $d->status])
                    <span class="ox-when {{ $d->next_action_at->lt(today()) ? 'late' : '' }}">
                        {{ $d->next_action_at->isToday() ? 'Today' : $d->next_action_at->format('D j M') }}
                    </span>
                    <span class="ox-action">
                        @if($d->suggestCloseNoReply()) Close — no reply?
                        @elseif($d->status === 'emailed') Send day-4 follow-up
                        @elseif($d->status === 'follow_up_1') Send day-10 follow-up
                        @elseif($d->status === 'new') Send first email
                        @elseif($d->status === 'replied') Reply to them
                        @else Follow up
                        @endif
                    </span>
                    @if($d->owner)<span class="muted" style="font-size:12px">{{ $d->owner->name }}</span>@endif
                </div>
            </a>
        @empty
            <p class="muted" style="margin:0">Nothing due — nice.</p>
        @endforelse
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('outreach.index') }}" class="card ox-filters">
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search company, contact, town…">
        <select name="status">
            <option value="">All statuses</option>
            <option value="open" @selected(($filters['status'] ?? '') === 'open')>Open (not closed)</option>
            @foreach(\App\Models\Prospect::STATUSES as $k => $label)
                <option value="{{ $k }}" @selected(($filters['status'] ?? '') === $k)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="segment">
            <option value="">All segments</option>
            @foreach($segments as $s)<option value="{{ $s }}" @selected(($filters['segment'] ?? '') === $s)>{{ $s }}</option>@endforeach
        </select>
        <select name="owner">
            <option value="">Any owner</option>
            <option value="none" @selected(($filters['owner'] ?? '') === 'none')>Unassigned</option>
            @foreach($owners as $o)<option value="{{ $o->id }}" @selected((string)($filters['owner'] ?? '') === (string)$o->id)>{{ $o->name }}</option>@endforeach
        </select>
        <select name="fit">
            <option value="">Any fit</option>
            @foreach([5,4,3,2] as $f)<option value="{{ $f }}" @selected((int)($filters['fit'] ?? 0) === $f)>Fit {{ $f }}{{ $f < 5 ? '+' : '' }}</option>@endforeach
        </select>
        <button class="btn btn-primary">Filter</button>
        @if(array_filter($filters))<a href="{{ route('outreach.index') }}" class="btn btn-ghost">Clear</a>@endif
    </form>

    {{-- List --}}
    <div class="card">
        <div class="table-scroll">
            <table class="table-modern table-cards">
                <thead><tr><th>Company</th><th>Contact</th><th>Fit</th><th>Status</th><th>Next action</th><th>Owner</th></tr></thead>
                <tbody>
                @forelse($prospects as $p)
                    <tr style="{{ $p->isClosed() ? 'opacity:.6' : '' }}">
                        <td data-label="Company"><a href="{{ route('outreach.show', $p) }}"><strong>{{ $p->company }}</strong></a><br><span class="muted" style="font-size:12px">{{ $p->segment }}{{ $p->town ? ' · '.$p->town : '' }}</span></td>
                        <td data-label="Contact" style="font-size:13px">{{ $p->contact_name ?: '—' }}<br><span class="muted">{{ \Illuminate\Support\Str::limit($p->contact_role, 40) }}</span></td>
                        <td data-label="Fit">{{ $p->fit_score ? str_repeat('★', $p->fit_score) : '—' }}</td>
                        <td data-label="Status">@include('admin.outreach._badge', ['status' => $p->status])</td>
                        <td data-label="Next action" style="font-size:13px">{{ $p->next_action_at?->format('D j M') ?? '—' }}</td>
                        <td data-label="Owner" style="font-size:13px">{{ $p->owner?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No prospects yet — import a CSV below.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $prospects->links() }}
    </div>

    {{-- Import --}}
    <div class="card">
        <h2 style="margin:0 0 6px">⬆ Import CSV</h2>
        <p class="muted" style="margin:0 0 10px;font-size:13px">
            Columns: <span class="mono" style="font-size:12px">company, segment, town, website, why_they_need_us, contact_role, contact_name, contact_email, contact_phone, linkedin_url, fit_score, angle, source_url, email_subject, email_body</span>.
            Duplicates (same company + town) are skipped — their status and notes are never overwritten, and do-not-contact prospects are never re-opened. New prospects are owned by you. The file is read, not stored.
        </p>
        <form method="POST" action="{{ route('outreach.import') }}" enctype="multipart/form-data" class="ox-import">
            @csrf
            <input type="file" name="file" accept=".csv,text/csv" required>
            <button class="btn btn-primary">Import prospects</button>
        </form>
    </div>
@endsection
