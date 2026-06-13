<?php

namespace App\Services;

use App\Models\ComplianceItem;
use App\Models\DriverProfile;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Support\Carbon;

/**
 * Fleet & driver compliance tracker.
 *
 * Syncs renewable items (MOT, insurance, PHV vehicle licence, compliance test,
 * PHV badge, DBS) from the vehicle and driver records into compliance_items,
 * classifies each as valid / due soon / expired, and sends automated WhatsApp
 * renewal reminders to the operations number.
 */
class ComplianceService
{
    public function __construct(private readonly WhatsAppService $whatsApp) {}

    /** Pull current dates from vehicles and drivers into compliance_items. */
    public function sync(): int
    {
        $count = 0;

        foreach (Vehicle::where('is_active', true)->get() as $vehicle) {
            $items = [
                'mot' => $vehicle->mot_expiry,
                'insurance' => $vehicle->insurance_expiry,
                'phv_vehicle' => $vehicle->phv_licence_expiry,
                'compliance_test' => $vehicle->compliance_test_date,
            ];
            foreach ($items as $type => $due) {
                if ($due) {
                    $this->upsert('vehicle', $vehicle->id, $type, $due, $vehicle->registration);
                    $count++;
                }
            }
        }

        foreach (DriverProfile::with('user')->get() as $profile) {
            $items = [
                'phv_badge' => $profile->phv_badge_expiry,
                'dbs' => $profile->dbs_expiry,
            ];
            foreach ($items as $type => $due) {
                if ($due) {
                    $this->upsert('driver', $profile->user_id, $type, $due, $profile->user?->name);
                    $count++;
                }
            }
        }

        $this->refreshStatuses();

        return $count;
    }

    private function upsert(string $subjectType, int $subjectId, string $type, Carbon|string $dueOn, ?string $reference): void
    {
        ComplianceItem::updateOrCreate(
            ['subject_type' => $subjectType, 'subject_id' => $subjectId, 'item_type' => $type],
            ['due_on' => $dueOn, 'reference' => $reference],
        );
    }

    /** Recompute valid / due_soon / expired for every item. */
    public function refreshStatuses(): void
    {
        $warnDays = (int) config('cet.compliance_warn_days', 30);

        foreach (ComplianceItem::all() as $item) {
            $item->update(['status' => $this->classify($item->due_on, $warnDays)]);
        }
    }

    public function classify(Carbon $dueOn, int $warnDays): string
    {
        if ($dueOn->isPast()) {
            return 'expired';
        }
        if ($dueOn->lte(now()->addDays($warnDays))) {
            return 'due_soon';
        }

        return 'valid';
    }

    /**
     * Send a WhatsApp reminder for each due-soon / expired item not reminded in
     * the last 7 days. Returns the number of reminders sent.
     */
    public function sendDueReminders(): int
    {
        $to = $this->opsNumber();
        if (blank($to)) {
            return 0;
        }

        $items = ComplianceItem::whereIn('status', ['due_soon', 'expired'])
            ->where(fn ($q) => $q->whereNull('reminder_sent_at')->orWhere('reminder_sent_at', '<', now()->subDays(7)))
            ->get();

        foreach ($items as $item) {
            $what = strtoupper(str_replace('_', ' ', $item->item_type));
            $state = $item->status === 'expired' ? 'has EXPIRED' : 'is due';
            $body = "CET compliance alert: {$what} for {$item->reference} {$state} on {$item->due_on->format('d/m/Y')}.";

            $this->whatsApp->send($to, $body, ['type' => 'custom']);
            $item->update(['reminder_sent_at' => now()]);
        }

        return $items->count();
    }

    private function opsNumber(): ?string
    {
        return config('cet.ops_whatsapp')
            ?: Setting::get('ops_whatsapp')
            ?: User::whereNotNull('phone')->whereHas('driverProfile', fn ($q) => $q->where('is_third_party', false))->value('phone');
    }
}
