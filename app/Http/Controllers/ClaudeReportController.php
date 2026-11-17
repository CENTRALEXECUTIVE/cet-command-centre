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
 * creates on Settings → Claude access; only its SHA-256 hash is stored. Nothing here
 * changes or sends anything. Outreach: company contacts. Booking lookup (directors,
 * 13 Nov 2026): ONE booking by its exact reference, with the minimum customer detail —
 * name and masked email, never phone numbers or addresses.
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

    /** One booking by exact reference: status, payment, email history (masked), online account. */
    public function booking(Request $request): JsonResponse
    {
        abort_unless(self::tokenMatches($request->bearerToken()), 404);

        $ref = strtoupper(trim((string) $request->query('ref', '')));
        abort_if($ref === '', 422, 'ref is required');

        $b = \App\Models\Booking::withTrashed()->with('customer', 'vehicleType')
            ->where('reference', $ref)->orWhere('external_reference', $ref)->first();
        abort_unless($b, 404);

        $tz = config('app.timezone', 'Europe/London');
        $c = $b->customer;
        $linked = $b->linked_booking_id ? \App\Models\Booking::withTrashed()->find($b->linked_booking_id) : null;

        $emails = collect(\App\Support\BookingEmailLog::entries($b))->map(fn (array $e) => [
            'at' => $e['at'] ?? null,
            'type' => $e['type'] ?? null,
            'audience' => $e['audience'] ?? null,
            'to' => self::maskEmail((string) ($e['to'] ?? '')),
            'sent' => (bool) ($e['ok'] ?? false),
            'error' => isset($e['error']) ? \Illuminate\Support\Str::limit((string) $e['error'], 120) : null,
        ])->values();

        return response()->json([
            'reference' => $b->reference,
            'external_reference' => $b->external_reference,
            'deleted' => $b->trashed(),
            'status' => $b->status?->value ?? $b->status,
            'source' => $b->source,
            'is_return_leg' => (bool) $b->is_return_leg,
            'linked_reference' => $linked?->reference,
            'pickup_at' => $b->pickup_at?->copy()->timezone($tz)->format('D j M Y H:i'),
            'vehicle' => $b->vehicleType?->name,
            'payment' => [
                'method' => $b->payment_method?->value ?? $b->payment_method,
                'status' => $b->payment_status?->value ?? $b->payment_status,
                'quoted_price' => $b->quoted_price,
                'fare_paid' => $b->fareIsPaid(),
            ],
            'customer' => $c ? [
                'name' => $c->name,
                'email' => self::maskEmail((string) $c->email),
                'online_account' => $c->hasOnlineAccount(),
                'customer_since' => $c->created_at?->copy()->timezone($tz)->toDateString(),
            ] : null,
            'emails' => [
                'sent' => $emails->where('sent', true)->count(),
                'failed' => $emails->where('sent', false)->count(),
                'history' => $emails,
            ],
        ]);
    }

    /**
     * Where bookings come from (directors, 17 Nov 2026) — COUNTS ONLY, no names or
     * contact details. Week by week: bookings, how they came in, new vs returning
     * customers, how they heard about us, and campaign/link tags.
     */
    public function sources(Request $request): JsonResponse
    {
        abort_unless(self::tokenMatches($request->bearerToken()), 404);

        $tz = config('app.timezone', 'Europe/London');
        $weeksBack = max(1, min(26, (int) $request->query('weeks', 12)));
        $start = Carbon::now($tz)->startOfWeek()->subWeeks($weeksBack - 1);

        $bookings = \App\Models\Booking::query()
            ->where('created_at', '>=', $start->copy()->utc())
            ->where('is_return_leg', false)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->get(['id', 'customer_id', 'source', 'source_system', 'meta', 'quoted_price', 'created_at']);

        // A customer's very first booking (ever) = a NEW customer.
        $firstIds = \App\Models\Booking::query()
            ->whereIn('customer_id', $bookings->pluck('customer_id')->filter()->unique())
            ->where('is_return_leg', false)
            ->selectRaw('customer_id, min(id) as first_id')->groupBy('customer_id')
            ->pluck('first_id', 'customer_id');

        $weeks = [];
        $totals = ['heard' => [], 'utm_source' => [], 'channel' => [], 'new' => 0, 'returning' => 0, 'google_ads_click' => 0];
        foreach ($bookings as $b) {
            $wk = $b->created_at->copy()->timezone($tz)->startOfWeek()->toDateString();
            $weeks[$wk] ??= ['week_of' => $wk, 'bookings' => 0, 'new_customers' => 0, 'returning' => 0, 'channels' => [], 'heard' => []];
            $w = &$weeks[$wk];
            $w['bookings']++;

            $channel = $b->meta['enquiry_source'] ?? null;
            if (! is_string($channel) || $channel === '') {
                $channel = match (true) {
                    $b->source === 'web' => 'website',
                    in_array($b->source_system, ['eto'], true) => 'eto_import',
                    default => (string) ($b->source ?: 'other'),
                };
            }
            $w['channels'][$channel] = ($w['channels'][$channel] ?? 0) + 1;
            $totals['channel'][$channel] = ($totals['channel'][$channel] ?? 0) + 1;

            $isNew = $b->customer_id && (int) ($firstIds[$b->customer_id] ?? 0) === (int) $b->id;
            $isNew ? $w['new_customers']++ : $w['returning']++;
            $isNew ? $totals['new']++ : $totals['returning']++;

            $heard = \App\Models\Booking::heardAboutLabel($b->heardAboutKey());
            $w['heard'][$heard] = ($w['heard'][$heard] ?? 0) + 1;
            $totals['heard'][$heard] = ($totals['heard'][$heard] ?? 0) + 1;

            $m = (array) ($b->meta['marketing'] ?? []);
            if (filled($m['gclid'] ?? null) || filled($m['gbraid'] ?? null) || filled($m['wbraid'] ?? null)) {
                $totals['google_ads_click']++;
            }
            if (filled($m['utm_source'] ?? null)) {
                $u = mb_strtolower((string) $m['utm_source']).(filled($m['utm_medium'] ?? null) ? ' / '.mb_strtolower((string) $m['utm_medium']) : '');
                $totals['utm_source'][$u] = ($totals['utm_source'][$u] ?? 0) + 1;
            }
            unset($w);
        }
        ksort($weeks);
        foreach (['heard', 'utm_source', 'channel'] as $k) {
            arsort($totals[$k]);
        }

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'from' => $start->toDateString(),
            'ads_paused_on' => (string) config('cet.ads_paused_on', '2026-11-04'),
            'note' => 'Counts only. Return legs, cancelled and no-show excluded. Week = when the booking came in.',
            'weeks' => array_values($weeks),
            'totals' => $totals,
        ]);
    }

    /** jarthur@aegisenergy.uk → ja•••@aegisenergy.uk */
    public static function maskEmail(string $email): ?string
    {
        if (! str_contains($email, '@')) {
            return $email === '' ? null : '•••';
        }
        [$user, $domain] = explode('@', $email, 2);

        return mb_substr($user, 0, 2).'•••@'.$domain;
    }
}
