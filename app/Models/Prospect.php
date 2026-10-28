<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A B2B outreach prospect (Outreach page, /outreach). Emails are drafted here
 * but SENT BY A DIRECTOR from their own mailbox via a mailto: link — the server
 * never sends anything to a prospect.
 *
 * Do-not-contact is permanent: once set it can't be changed back from the app,
 * the email buttons disappear, and a CSV re-import never re-opens it.
 */
class Prospect extends Model
{
    public const STATUSES = [
        'new' => 'New',
        'emailed' => 'Emailed',
        'follow_up_1' => 'Follow-up 1 sent',
        'follow_up_2' => 'Follow-up 2 sent',
        'replied' => 'Replied',
        'meeting' => 'Meeting booked',
        'proposal' => 'Proposal sent',
        'account_opened' => 'Account opened',
        'not_interested' => 'Not interested',
        'do_not_contact' => 'Do not contact',
    ];

    /** Finished — never in the follow-ups-due list. */
    public const CLOSED = ['account_opened', 'not_interested', 'do_not_contact'];

    /** Counted as "they replied" for the reply rate. */
    public const REPLIED = ['replied', 'meeting', 'proposal', 'account_opened'];

    /**
     * The send sequence: current status => [status after this send, days until
     * the next action]. First email → day-4 follow-up → day-10 follow-up → after
     * 8 more quiet days the prospect is flagged to close as "no reply".
     */
    public const SEQUENCE = [
        'new' => ['emailed', 4],
        'emailed' => ['follow_up_1', 6],
        'follow_up_1' => ['follow_up_2', 8],
    ];

    protected $fillable = [
        'company', 'segment', 'town', 'website', 'why_they_need_us', 'contact_role',
        'contact_name', 'contact_email', 'contact_phone', 'linkedin_url', 'fit_score',
        'angle', 'source_url', 'email_subject', 'email_body', 'status', 'owner_id',
        'next_action_at', 'last_contacted_at', 'replied_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'fit_score' => 'integer',
            'next_action_at' => 'date',
            'last_contacted_at' => 'datetime',
            'replied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Prospect $p) {
            $p->dedupe_key = static::dedupeKey((string) $p->company, $p->town);
        });
    }

    /** lower(company) | lower(town), whitespace-collapsed — the uniqueness key. */
    public static function dedupeKey(string $company, ?string $town): string
    {
        $norm = fn (?string $s) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $s)));

        return mb_substr($norm($company).'|'.$norm($town), 0, 255);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ProspectActivity::class)->latest('id');
    }

    public function log(string $type, ?string $body = null, ?int $userId = null): ProspectActivity
    {
        return $this->activities()->create([
            'type' => $type,
            'body' => $body,
            'user_id' => $userId ?? auth()->id(),
        ]);
    }

    /** next_action_at on or before today, and not closed. */
    public function scopeFollowUpsDue(Builder $q): Builder
    {
        return $q->whereNotNull('next_action_at')
            ->whereDate('next_action_at', '<=', Carbon::today()->toDateString())
            ->whereNotIn('status', self::CLOSED);
    }

    public function isDoNotContact(): bool
    {
        return $this->status === 'do_not_contact';
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED, true);
    }

    /** Email buttons are shown only with an address and never for do-not-contact. */
    public function canEmail(): bool
    {
        return ! $this->isDoNotContact() && filled($this->contact_email);
    }

    /** [next status, days] for "Mark emailed", or null when the sequence is done. */
    public function nextSend(): ?array
    {
        return self::SEQUENCE[$this->status] ?? null;
    }

    /** Label for the "Mark emailed" button at this stage. */
    public function markEmailedLabel(): ?string
    {
        return match ($this->status) {
            'new' => 'Mark emailed',
            'emailed' => 'Mark day-4 follow-up sent',
            'follow_up_1' => 'Mark day-10 follow-up sent',
            default => null,
        };
    }

    /** Both follow-ups sent, quiet for 8 days → suggest closing as no reply. */
    public function suggestCloseNoReply(): bool
    {
        return $this->status === 'follow_up_2'
            && $this->next_action_at
            && $this->next_action_at->lte(Carbon::today());
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst(str_replace('_', ' ', (string) $this->status));
    }

    /** "Prof Ben Morgan (CEO …)" → "Ben"; blank when unknown. */
    public function firstName(): string
    {
        $name = trim((string) preg_replace('/\(.*?\)/', '', (string) $this->contact_name));
        $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        while ($parts && preg_match('/^(mr|mrs|ms|miss|dr|prof|sir|dame)\.?$/i', $parts[0])) {
            array_shift($parts);
        }

        return $parts[0] ?? '';
    }
}
