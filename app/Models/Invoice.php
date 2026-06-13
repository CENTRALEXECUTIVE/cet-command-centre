<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'invoice_number', 'corporate_account_id', 'period_start', 'period_end',
        'subtotal', 'vat_amount', 'total', 'status', 'issued_at', 'due_at', 'paid_at', 'pdf_path',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'issued_at' => 'date',
            'due_at' => 'date',
            'paid_at' => 'date',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function corporateAccount(): BelongsTo
    {
        return $this->belongsTo(CorporateAccount::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
