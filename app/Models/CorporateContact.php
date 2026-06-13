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

    public function corporateAccount(): BelongsTo
    {
        return $this->belongsTo(CorporateAccount::class);
    }
}
