<?php

namespace App\Http\Controllers;

use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\Setting;
use App\Services\Outreach\OutreachBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Read-only reports for Claude (Nov 2026). Locked by a secret key a super admin
 * creates on Settings → Claude access; only its SHA-256 hash is stored. Outreach
 * only: company contacts, never customer bookings, phone numbers or money. Nothing
 * here changes or sends anything.
 */
class ClaudeReportController extends Controller
{
    public const HASH_KEY = 'claude_report_token_hash';

    public static function tokenMatches(?string $token): bool
    {
        $hash = Setting::get(self::HASH_KEY);

        return filled($hash) && filled($token) && hash_equals((string) $hash, hash('sha256', $token));
    }

    public function outreach(Request $request, OutreachBatch $batch): JsonResponse
    {
        abort_unless(self::tokenMatches($request->bearerToken()), 404);

        $tz = config('app.timezone', 'Europe/London');
        $now = Carbon::now($tz);
        $start = $now->copy()->startOfDay()->utc();

        $queued = collect($batch->queued())->map(function ($q) {
            $p = Prospect::find($q['id'] ?? 0);

            return [
                'company' => $p?->company, 'to' => $p?->contact_email,
                'stage' => ($q['status'] ?? $p?->status) === 'new' ? 'First email' : 'Follow-up',
                'send_at' => $q['at'] ?? null,
            ];
        })->values();

        $candidates = $batch->candidates()->map(function (Prospect $p) use ($batch) {
            $mail = $batch->compose($p);

            return [
                'company' => $p->company, 'to' => $p->contact_email, 'cc' => $p->cc_email ?: null,
                'stage' => $mail['stage'] ?? null, 'subject' => $mail['subject'] ?? null,
                'fit_score' => $p->fit_score,
            ];
        })->values();

        $sentToday = ProspectActivity::with('prospect:id,company,contact_email')
            ->where('type', 'emailed')->where('body', 'like', OutreachBatch::SENT_PREFIX.'%')
            ->where('created_at', '>=', $start)->orderBy('created_at')->get()
            ->map(fn ($a) => [
                'company' => $a->prospect?->company, 'to' => $a->prospect?->contact_email,
                'at' => $a->created_at?->timezone($tz)->format('H:i'), 'note' => $a->body,
            ])->values();

        $replies = Prospect::whereNotNull('replied_at')->where('replied_at', '>=', now()->subDays(14))
            ->orderByDesc('replied_at')->get(['company', 'status', 'replied_at', 'contact_email'])
            ->map(fn ($p) => [
                'company' => $p->company, 'status' => $p->status, 'from' => $p->contact_email,
                'replied' => $p->replied_at?->timezone($tz)->format('D j M H:i'),
            ])->values();

        $dueNext = [];
        for ($d = 0, $day = $now->copy(); $d < 7; $d++, $day->addDay()) {
            $n = Prospect::whereIn('status', ['emailed', 'follow_up_1'])
                ->whereDate('next_action_at', $day->toDateString())->count();
            if ($n) {
                $dueNext[$day->format('D j M')] = $n;
            }
        }

        return response()->json([
            'generated_at' => $now->toIso8601String(),
            'today' => [
                'date' => $now->format('D j M Y'),
                'weekend' => OutreachBatch::isWeekend($now),
                'daily_limit' => OutreachBatch::limit(),
                'max_follow_ups' => OutreachBatch::maxFollowUps(),
                'sent' => $batch->sentToday(),
                'follow_ups_sent' => $batch->followUpsSentToday(),
                'remaining' => $batch->remainingToday(),
            ],
            'sent_today' => $sentToday,
            'queued' => $queued,
            'batch_waiting_for_approval' => $candidates,
            'replies_last_14_days' => $replies,
            'follow_ups_due_next_7_days' => $dueNext,
            // The same list as the dashboard's "Follow-ups due today" (today or overdue, not closed).
            'follow_ups_due_or_overdue' => Prospect::followUpsDue()->orderBy('next_action_at')->get()
                ->map(fn ($p) => [
                    'company' => $p->company, 'status' => $p->status, 'to' => $p->contact_email,
                    'due' => $p->next_action_at?->format('D j M'),
                ])->values(),
            'pipeline' => Prospect::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
