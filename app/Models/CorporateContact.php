<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorporateContact extends Model
{
    protected $fillable = [
        'corporate_account_id', 'name', 'email', 'phone', 'job_title', 'is_primary',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Adding a booker (contact) to a business must roll their bookings up on
        // the Review page immediately — bust the cached name→account lookup.
        $forget = fn () => \Illuminate\Support\Facades\Cache::forget('corporate_name_map');
        static::saved($forget);
        static::deleted($forget);
    }

    public function corporateAccount(): BelongsTo
    {
        return $this->belongsTo(CorporateAccount::class);
    }
}
