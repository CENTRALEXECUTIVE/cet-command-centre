<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A call answered by the AI phone agent (see App\Services\Voice\PhoneAgent). */
class PhoneCall extends Model
{
    protected $fillable = [
        'call_sid', 'from_number', 'status', 'transcript', 'booking_fields', 'quotes',
        'message', 'summary', 'needs_action', 'booking_id', 'reviewed_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'transcript' => 'array',
            'booking_fields' => 'array',
            'quotes' => 'array',
            'needs_action' => 'boolean',
            'reviewed_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** Append one line to the transcript: role = caller | agent | system. */
    public function say(string $role, string $text): void
    {
        $t = $this->transcript ?? [];
        $t[] = ['role' => $role, 'text' => $text, 'at' => now()->toIso8601String()];
        $this->transcript = $t;
    }

    public function minutes(): ?int
    {
        return $this->ended_at ? max(1, (int) ceil($this->created_at->diffInSeconds($this->ended_at) / 60)) : null;
    }

    /** Every booking detail the caller gave, labelled, for the call page. */
    public const BOOKING_LABELS = [
        'lead_name' => 'Passenger', 'contact_no' => 'Contact', 'email' => 'Email', 'booked_by' => 'Booked by',
        'pickup_at' => 'Pickup time', 'pickup_address' => 'Pickup', 'destination_address' => 'Drop-off',
        'flight_number' => 'Flight', 'flight_landing_at' => 'Flight lands',
        'return_pickup_at' => 'Return pickup time', 'return_pickup_address' => 'Return pickup', 'return_flight_number' => 'Return flight',
        'passengers' => 'Passengers', 'suitcases' => 'Large cases', 'hand_luggage' => 'Hand luggage', 'child_seats' => 'Child seats',
        'vehicle' => 'Vehicle', 'payment' => 'Payment', 'price' => 'Quoted',
        'vat_invoice' => 'VAT invoice', 'company_name' => 'Company', 'company_vat_number' => 'VAT number', 'company_address' => 'Company address',
        'notes' => 'Notes',
    ];

    /**
     * The fields to hand to Paste a booking. It only understands one leg, so the
     * return, VAT details, landing time and child seats go into the notes, labelled —
     * nothing the caller said is dropped (27 Nov 2026).
     */
    public function intakeFields(): array
    {
        $f = (array) ($this->booking_fields ?? []);
        $known = ['lead_name', 'contact_no', 'email', 'booked_by', 'pickup_at', 'pickup_address', 'destination_address',
            'flight_number', 'passengers', 'suitcases', 'hand_luggage', 'vehicle', 'payment', 'price'];
        $out = array_intersect_key($f, array_flip($known));
        $extra = [];
        foreach ($f as $k => $v) {
            if (in_array($k, $known, true) || $k === 'notes' || ! is_scalar($v) || trim((string) $v) === '') {
                continue;
            }
            $extra[] = (self::BOOKING_LABELS[$k] ?? ucfirst(str_replace('_', ' ', $k))).': '.(is_bool($v) ? ($v ? 'Yes' : 'No') : $v);
        }
        if (filled($f['return_pickup_at'] ?? null)) {
            array_unshift($extra, '⚠ RETURN JOURNEY WANTED — add the return leg after confirming');
        }
        $out['notes'] = trim(implode("\n", array_filter([$f['notes'] ?? null, implode("\n", $extra)])));

        return array_filter($out, fn ($v) => $v !== null && $v !== '');
    }
}
