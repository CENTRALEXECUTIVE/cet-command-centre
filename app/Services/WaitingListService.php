<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\WaitingListEntry;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Support\Str;

/**
 * Waiting list: when a booking is cancelled, any customer waiting for that date
 * (and matching vehicle type, if specified) is automatically notified by
 * WhatsApp that availability has opened — so cancellations get refilled.
 */
class WaitingListService
{
    public function __construct(private readonly WhatsAppService $whatsApp) {}

    /**
     * Notify everyone waiting for the cancelled booking's date/vehicle type.
     * Returns the number of customers notified.
     */
    public function notifyForCancellation(Booking $cancelled): int
    {
        $entries = WaitingListEntry::with('customer')
            ->where('status', 'waiting')
            ->whereDate('desired_date', $cancelled->pickup_at->toDateString())
            ->where(fn ($q) => $q->whereNull('vehicle_type_id')->orWhere('vehicle_type_id', $cancelled->vehicle_type_id))
            ->get();

        $notified = 0;
        foreach ($entries as $entry) {
            $phone = $entry->customer?->phone;
            if (blank($phone)) {
                continue;
            }

            $name = Str::before($entry->customer->name ?? 'there', ' ');
            $body = "Good news {$name} — a Central Executive Transfers slot has opened on "
                ."{$entry->desired_date->format('D d M')}"
                .($entry->desired_time_window ? " ({$entry->desired_time_window})" : '')
                .'. Reply or call to book it before someone else does.';

            $this->whatsApp->send($phone, $body, ['type' => 'waiting_list', 'customer' => $entry->customer]);
            $entry->update(['status' => 'notified', 'notified_at' => now()]);
            $notified++;
        }

        return $notified;
    }
}
