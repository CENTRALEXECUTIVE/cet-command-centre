<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a prospect's Outreach history (imported, emailed, status, note, edited). */
class ProspectActivity extends Model
{
    protected $fillable = ['prospect_id', 'user_id', 'type', 'body'];

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
