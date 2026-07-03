<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class DriverDocument extends Model
{
    protected $fillable = [
        'user_id', 'type', 'file_path', 'file_name', 'mime', 'size',
        'expiry_date', 'status', 'reviewed_by', 'reviewed_at', 'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * The required documents, in display order. `subject` says whether it's a
     * driver or vehicle document; `expiry` whether it carries a renewal date.
     *
     * @var array<string, array{label: string, subject: string, expiry: bool}>
     */
    public const TYPES = [
        'plate' => ['label' => 'PHV Plate', 'subject' => 'vehicle', 'expiry' => true],
        'mot' => ['label' => 'MOT', 'subject' => 'vehicle', 'expiry' => true],
        'insurance' => ['label' => 'Motor Insurance', 'subject' => 'vehicle', 'expiry' => true],
        'badge' => ['label' => 'PHV Badge', 'subject' => 'driver', 'expiry' => true],
        'driving_licence' => ['label' => 'Driving Licence', 'subject' => 'driver', 'expiry' => true],
        'dbs' => ['label' => 'DBS Certificate', 'subject' => 'driver', 'expiry' => true],
    ];

    /** Days below which a valid document is flagged "expiring soon" (amber). */
    public const EXPIRING_SOON_DAYS = 30;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function label(): string
    {
        return self::TYPES[$this->type]['label'] ?? ucfirst(str_replace('_', ' ', $this->type));
    }

    /** Whole days until expiry (negative if already expired); null if no date. */
    public function daysLeft(): ?int
    {
        if (! $this->expiry_date) {
            return null;
        }

        return (int) Carbon::today()->diffInDays($this->expiry_date, false);
    }

    /**
     * Card status: 'missing' (no file), 'pending'/'rejected' (review state),
     * 'expired' (past), 'expiring' (within 30 days), or 'valid'.
     */
    public function cardStatus(): string
    {
        if (! $this->file_path) {
            return 'missing';
        }
        if ($this->status === 'rejected') {
            return 'rejected';
        }
        if ($this->status === 'pending') {
            return 'pending';
        }

        $days = $this->daysLeft();
        if ($days === null) {
            return 'valid';
        }
        if ($days < 0) {
            return 'expired';
        }

        return $days <= self::EXPIRING_SOON_DAYS ? 'expiring' : 'valid';
    }
}
