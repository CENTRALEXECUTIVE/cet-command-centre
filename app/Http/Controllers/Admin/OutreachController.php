<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\ImportProspects;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\User;
use App\Services\Outreach\OutreachEmailComposer;
use App\Services\Outreach\ProspectImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Outreach — B2B prospecting for the directors (/outreach, admin only).
 *
 * The server NEVER emails a prospect. "Open email" is a mailto: link that opens
 * the director's own mail app with the draft filled in; "Mark emailed" just
 * records that they sent it and schedules the next follow-up.
 */
class OutreachController extends Controller
{
    /** Quick status buttons on the prospect page. */
    public const QUICK = ['replied', 'meeting', 'account_opened', 'not_interested', 'do_not_contact'];

    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $q = Prospect::query()->with('owner');

        if (($status = $request->query('status')) && isset(Prospect::STATUSES[$status])) {
            $q->where('status', $status);
        } elseif ($request->query('status') === 'open') {
            $q->whereNotIn('status', Prospect::CLOSED);
        }
        if ($segment = $request->query('segment')) {
            $q->where('segment', $segment);
        }
        if ($owner = $request->query('owner')) {
            $owner === 'none' ? $q->whereNull('owner_id') : $q->where('owner_id', (int) $owner);
        }
        if ($fit = (int) $request->query('fit')) {
            $q->where('fit_score', '>=', $fit);
        }
        if ($term = trim((string) $request->query('q'))) {
            $like = '%'.mb_strtolower($term).'%';
            $q->where(function ($w) use ($like) {
                foreach (['company', 'contact_name', 'contact_email', 'town', 'segment', 'contact_role'] as $col) {
                    $w->orWhereRaw("lower($col) like ?", [$like]);
                }
            });
        }

        $closed = "'".implode("','", Prospect::CLOSED)."'";
        $prospects = $q->orderByRaw("case when status in ($closed) then 1 else 0 end")
            ->orderByDesc('fit_score')->orderBy('company')
            ->paginate(50)->withQueryString();

        $weekStart = Carbon::now()->startOfWeek();
        $contacted = Prospect::whereNotNull('last_contacted_at')->count();
        $replies = Prospect::whereNotNull('replied_at')->count();

        return view('admin.outreach.index', [
            'prospects' => $prospects,
            'due' => Prospect::followUpsDue()->with('owner')->orderBy('next_action_at')->orderByDesc('fit_score')->limit(100)->get(),
            'stats' => [
                'total' => Prospect::count(),
                'emailed_week' => ProspectActivity::where('type', 'emailed')->where('created_at', '>=', $weekStart)
                    ->distinct()->count('prospect_id'),
                'replies' => $replies,
                'meetings' => Prospect::whereIn('status', ['meeting', 'proposal'])->count(),
                'accounts' => Prospect::where('status', 'account_opened')->count(),
                'contacted' => $contacted,
                'reply_rate' => $contacted ? (int) round($replies / $contacted * 100) : 0,
            ],
            'segments' => Prospect::whereNotNull('segment')->distinct()->orderBy('segment')->pluck('segment'),
            'owners' => $this->owners(),
            'filters' => $request->only(['status', 'segment', 'owner', 'fit', 'q']),
        ]);
    }

    public function show(Request $request, Prospect $prospect, OutreachEmailComposer $composer): View
    {
        abort_unless($request->user()->isAdmin(), 403);
        $prospect->load(['owner', 'activities.user']);

        $first = $composer->firstEmail($prospect, $request->user());
        $followUps = $composer->followUps($prospect, $request->user());

        return view('admin.outreach.show', [
            'p' => $prospect,
            'first' => $first + ['mailto' => $composer->mailto($prospect, $first['subject'], $first['body'])],
            'followUps' => array_map(fn ($f) => $f + ['mailto' => $composer->mailto($prospect, $f['subject'], $f['body'])], $followUps),
            'owners' => $this->owners(),
            'quick' => self::QUICK,
        ]);
    }

    public function update(Request $request, Prospect $prospect): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'company' => ['required', 'string', 'max:200'],
            'segment' => ['nullable', 'string', 'max:120'],
            'town' => ['nullable', 'string', 'max:200'],
            'website' => ['nullable', 'string', 'max:500'],
            'why_they_need_us' => ['nullable', 'string', 'max:5000'],
            'contact_role' => ['nullable', 'string', 'max:200'],
            'contact_name' => ['nullable', 'string', 'max:200'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:60'],
            'linkedin_url' => ['nullable', 'string', 'max:500'],
            'fit_score' => ['nullable', 'integer', 'between:1,5'],
            'angle' => ['nullable', 'string', 'max:5000'],
            'source_url' => ['nullable', 'string', 'max:1000'],
            'email_subject' => ['nullable', 'string', 'max:300'],
            'email_body' => ['nullable', 'string', 'max:20000'],
            'status' => ['required', Rule::in(array_keys(Prospect::STATUSES))],
            'owner_id' => ['nullable', Rule::exists('users', 'id')->where('role', UserRole::Admin->value)],
            'next_action_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:20000'],
        ]);

        $key = Prospect::dedupeKey($data['company'], $data['town'] ?? null);
        if (Prospect::where('dedupe_key', $key)->whereKeyNot($prospect->id)->exists()) {
            throw ValidationException::withMessages(['company' => 'Another prospect already has this company and town.']);
        }

        // Do-not-contact is permanent — the status can't be changed back.
        $newStatus = $prospect->isDoNotContact() ? 'do_not_contact' : $data['status'];
        unset($data['status']);

        $prospect->fill($data);
        $changed = array_keys($prospect->getDirty());
        $prospect->save();
        if ($changed) {
            $prospect->log('edited', 'Edited: '.implode(', ', array_diff($changed, ['dedupe_key'])));
        }
        if ($newStatus !== $prospect->status) {
            $this->changeStatus($prospect, $newStatus, keepNextAction: true);
        }

        return redirect()->route('outreach.show', $prospect)->with('status', 'Saved.');
    }

    /** Record that the director sent the next email in the sequence. Sends nothing. */
    public function markEmailed(Request $request, Prospect $prospect): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        if ($prospect->isDoNotContact()) {
            return back()->withErrors(['status' => 'This prospect is do-not-contact — never email them.']);
        }
        if (! $next = $prospect->nextSend()) {
            return back()->withErrors(['status' => 'Nothing to mark — the email sequence is finished at "'.$prospect->statusLabel().'".']);
        }

        [$to, $days] = $next;
        $label = $prospect->markEmailedLabel();
        $nextDate = Carbon::today()->addDays($days);

        $prospect->forceFill([
            'status' => $to,
            'last_contacted_at' => now(),
            'next_action_at' => $nextDate->toDateString(),
            'owner_id' => $prospect->owner_id ?? $request->user()->id, // whoever sends owns the follow-ups
        ])->save();

        $what = match ($to) {
            'emailed' => 'First email sent. Day-4 follow-up due '.$nextDate->format('D j M').'.',
            'follow_up_1' => 'Day-4 follow-up sent. Day-10 follow-up due '.$nextDate->format('D j M').'.',
            default => 'Day-10 follow-up sent. If no reply by '.$nextDate->format('D j M').', close as no reply.',
        };
        $prospect->log('emailed', $what);

        return back()->with('status', $label.': '.$what);
    }

    public function setStatus(Request $request, Prospect $prospect): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['status' => ['required', Rule::in([...self::QUICK, 'closed_no_reply'])]]);

        if ($prospect->isDoNotContact()) {
            return back()->withErrors(['status' => 'This prospect is do-not-contact — that is permanent.']);
        }

        if ($data['status'] === 'closed_no_reply') {
            $this->changeStatus($prospect, 'not_interested', note: 'Closed — no reply after the full email sequence.');
        } else {
            $this->changeStatus($prospect, $data['status']);
        }

        return back()->with('status', 'Status: '.$prospect->statusLabel().'.');
    }

    public function addNote(Request $request, Prospect $prospect): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $prospect->log('note', $data['body']);

        return back()->with('status', 'Note added.');
    }

    public function import(Request $request, ProspectImporter $importer): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $ext = strtolower((string) $request->file('file')?->getClientOriginalExtension());
        if (in_array($ext, ['xlsx', 'xls'], true)) {
            throw ValidationException::withMessages(['file' => 'That\'s an Excel file — use File → Save As → CSV, then upload the .csv.']);
        }
        $request->validate(['file' => ['required', 'file', 'extensions:csv,txt', 'max:10240']]);

        // Read straight from the temp upload — never stored.
        $s = $importer->import($request->file('file')->getRealPath(), $request->user()->id, $request->user()->id);

        $msg = ImportProspects::summary($s);
        if ($s['errors']) {
            $msg .= ' '.implode(' ', array_slice($s['errors'], 0, 5));
        }

        return redirect()->route('outreach.index')->with('status', $msg);
    }

    private function changeStatus(Prospect $p, string $to, ?string $note = null, bool $keepNextAction = false): void
    {
        $from = $p->status;
        $p->status = $to;

        if (in_array($to, Prospect::REPLIED, true) && ! $p->replied_at) {
            $p->replied_at = now();
        }
        if (! $keepNextAction) {
            // Replied → answer them today; meeting/closed → the old follow-up date no longer applies.
            $p->next_action_at = $to === 'replied' ? Carbon::today()->toDateString() : null;
        }
        if (in_array($to, Prospect::CLOSED, true)) {
            $p->next_action_at = null;
        }
        $p->save();

        $p->log('status', (Prospect::STATUSES[$from] ?? $from).' → '.$p->statusLabel().($note ? ' · '.$note : ''));
    }

    private function owners()
    {
        return User::where('role', UserRole::Admin->value)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }
}
