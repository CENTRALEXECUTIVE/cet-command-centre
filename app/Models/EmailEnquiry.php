<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailEnquiry extends Model
{
    protected $fillable = [
        'graph_message_id', 'from_email', 'from_name', 'subject', 'body',
        'extracted', 'quote_amount', 'quote_basis', 'draft_reply', 'status',
        'customer_id', 'replied_at',
    ];

    protected function casts(): array
    {
        return [
            'extracted' => 'array',
            'quote_amount' => 'decimal:2',
            'replied_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** A mailto: link that opens the operator's mail client with the reply ready. */
    public function mailtoLink(): ?string
    {
        if (blank($this->from_email)) {
            return null;
        }
        $subject = 'Re: '.($this->subject ?: 'Your enquiry');

        return 'mailto:'.rawurlencode($this->from_email)
            .'?subject='.rawurlencode($subject)
            .'&body='.rawurlencode((string) $this->draft_reply);
    }
}
