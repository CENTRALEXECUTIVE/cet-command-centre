<?php

namespace App\Services\Compliance;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Decides whether a driver may be given a job. A driver is BLOCKED if any legally
 * required document has expired (driver: PHV badge, DBS, driving licence;
 * vehicle: MOT, insurance, PHV plate). Expiry dates come from the driver/vehicle
 * records, which the document verification keeps current. Missing dates are a
 * warning, not a hard block, so a data gap doesn't ground the whole fleet.
 */
class DriverComplianceService
{
    /**
     * Expired required items for a driver, each with its label and expiry date.
     * Empty when nothing has expired.
     *
     * @return array<int, array{label: string, expired_on: Carbon}>
     */
    public function blockers(User $driver): array
    {
        $today = Carbon::today();
        $blockers = [];

        foreach ($this->requiredDates($driver) as $label => $date) {
            if ($date !== null && $date->lt($today)) {
                $blockers[] = ['label' => $label, 'expired_on' => $date];
            }
        }

        return $blockers;
    }

    public function isCompliant(User $driver): bool
    {
        return $this->blockers($driver) === [];
    }

    /** A short reason string for a blocked allocation, e.g. "MOT expired 3 days ago". */
    public function blockReason(User $driver): ?string
    {
        $blockers = $this->blockers($driver);
        if ($blockers === []) {
            return null;
        }

        return collect($blockers)
            ->map(fn ($b) => $b['label'].' expired '.$b['expired_on']->diffForHumans())
            ->implode('; ');
    }

    /**
     * @return array<string, Carbon|null>
     */
    private function requiredDates(User $driver): array
    {
        $profile = $driver->driverProfile;
        $vehicle = $profile?->defaultVehicle;

        return [
            'Driving Licence' => $profile?->driving_licence_expiry,
            'PHV Badge' => $profile?->phv_badge_expiry,
            'DBS' => $profile?->dbs_expiry,
            'MOT' => $vehicle?->mot_expiry,
            'Motor Insurance' => $vehicle?->insurance_expiry,
            'PHV Plate' => $vehicle?->phv_licence_expiry,
        ];
    }
}
