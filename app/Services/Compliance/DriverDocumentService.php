<?php

namespace App\Services\Compliance;

use App\Models\DriverDocument;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Shared logic for the driver + admin "documents" views: builds the days-left
 * card rows, and — on approval — pushes the confirmed expiry back onto the
 * driver/vehicle records so the existing compliance reminders keep working.
 */
class DriverDocumentService
{
    /**
     * One display row per required document type for a driver, in order. Uses the
     * uploaded document's expiry if present, else the expiry already on the
     * driver/vehicle record, so cards are populated before any upload.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rowsFor(User $driver): array
    {
        $profile = $driver->driverProfile;
        $vehicle = $profile?->defaultVehicle;
        $documents = $driver->driverDocuments()->get()->keyBy('type');

        $rows = [];
        foreach (DriverDocument::TYPES as $type => $meta) {
            $doc = $documents->get($type);
            $expiry = $doc?->expiry_date ?? $this->existingExpiry($type, $profile, $vehicle);
            $days = $expiry ? (int) Carbon::today()->diffInDays($expiry, false) : null;

            $status = match (true) {
                $doc?->status === 'rejected' => 'rejected',
                $doc && $doc->status === 'pending' => 'pending',
                $days === null => $doc ? 'valid' : 'missing',
                $days < 0 => 'expired',
                $days <= DriverDocument::EXPIRING_SOON_DAYS => 'expiring',
                default => 'valid',
            };

            $rows[] = [
                'type' => $type,
                'label' => $meta['label'],
                'subject' => $meta['subject'],
                'expiry' => $expiry,
                'days' => $days,
                'status' => $status,
                'hasFile' => (bool) $doc?->file_path,
                'document' => $doc?->file_path ? $doc : null,
            ];
        }

        return $rows;
    }

    /** A one-line health summary for a driver, for the admin list. */
    public function summaryFor(User $driver): array
    {
        $counts = ['missing' => 0, 'pending' => 0, 'rejected' => 0, 'expired' => 0, 'expiring' => 0, 'valid' => 0];
        foreach ($this->rowsFor($driver) as $row) {
            $counts[$row['status']]++;
        }

        return $counts;
    }

    /** Push an approved document's expiry onto the driver/vehicle record. */
    public function syncExpiryToRecords(DriverDocument $document): void
    {
        if (! $document->expiry_date) {
            return;
        }
        $profile = $document->user->driverProfile;
        $vehicle = $profile?->defaultVehicle;

        match ($document->type) {
            'badge' => $profile?->forceFill(['phv_badge_expiry' => $document->expiry_date])->save(),
            'dbs' => $profile?->forceFill(['dbs_expiry' => $document->expiry_date])->save(),
            'driving_licence' => $profile?->forceFill(['driving_licence_expiry' => $document->expiry_date])->save(),
            'plate' => $vehicle?->forceFill(['phv_licence_expiry' => $document->expiry_date])->save(),
            'mot' => $vehicle?->forceFill(['mot_expiry' => $document->expiry_date])->save(),
            'insurance' => $vehicle?->forceFill(['insurance_expiry' => $document->expiry_date])->save(),
            default => null,
        };
    }

    private function existingExpiry(string $type, $profile, $vehicle): ?Carbon
    {
        return match ($type) {
            'plate' => $vehicle?->phv_licence_expiry,
            'mot' => $vehicle?->mot_expiry,
            'insurance' => $vehicle?->insurance_expiry,
            'badge' => $profile?->phv_badge_expiry,
            'driving_licence' => $profile?->driving_licence_expiry,
            'dbs' => $profile?->dbs_expiry,
            default => null,
        };
    }
}
