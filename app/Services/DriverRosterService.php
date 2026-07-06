<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\CoverDriver;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Illuminate\Support\Str;

/**
 * Keeps the drivers directory (CoverDriver) in step with real, assignable driver
 * accounts (User + DriverProfile + Vehicle) so a directory driver can be given
 * jobs on the dispatch board — while staying the lightweight roster the office
 * edits. Also normalises UK phone numbers to +44 for the WhatsApp reminder.
 */
class DriverRosterService
{
    /** UK number → +44 (e.g. "07785 729671" → "+44 7785 729671"). Leaves +… as-is. */
    public function normalizePhone(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }
        $p = trim($phone);
        if (str_starts_with($p, '+')) {
            return $p;
        }
        if (str_starts_with($p, '0')) {
            return '+44 '.ltrim(substr($p, 1));
        }

        return '+44 '.$p;
    }

    /**
     * Create/refresh the assignable driver account behind a directory entry and
     * link it back. Idempotent. No login is needed — a random password is set and
     * never shared; the account exists only to be allocated jobs.
     */
    public function ensureUser(CoverDriver $driver): ?User
    {
        if (blank($driver->name)) {
            return null;
        }

        $email = $this->emailFor($driver);
        $user = User::firstOrNew(['email' => $email]);
        if (! $user->exists) {
            $user->password = Str::password(20);
            $user->email_verified_at = now();
        }
        $user->fill([
            'name' => $driver->name,
            'phone' => $driver->phone,
            'role' => UserRole::Driver->value,
            'is_active' => (bool) $driver->is_active,
        ])->save();

        $vehicleId = null;
        if (filled($driver->vehicle_reg)) {
            [$colour, $make, $model] = $this->splitVehicle($driver->vehicle);
            $vehicle = Vehicle::firstOrNew(['registration' => strtoupper($driver->vehicle_reg)]);
            if (! $vehicle->vehicle_type_id) {
                $vehicle->vehicle_type_id = $this->defaultVehicleTypeId();
            }
            $vehicle->fill(['colour' => $colour, 'make' => $make, 'model' => $model, 'is_active' => true])->save();
            $vehicleId = $vehicle->id;
        }

        $profile = DriverProfile::firstOrNew(['user_id' => $user->id]);
        $profile->fill(['callsign' => $driver->name, 'is_third_party' => true, 'is_available' => true]);
        if ($vehicleId) {
            $profile->default_vehicle_id = $vehicleId;
        }
        $profile->save();

        if ($driver->user_id !== $user->id) {
            $driver->forceFill(['user_id' => $user->id])->save();
        }

        return $user;
    }

    private function emailFor(CoverDriver $driver): string
    {
        if ($driver->user_id && ($u = User::find($driver->user_id))) {
            return $u->email;
        }

        return Str::slug($driver->name.'-'.($driver->vehicle_reg ?: $driver->id)).'@cet-drivers.local';
    }

    /** "Black Mercedes V Class" → [colour, make, model]. */
    private function splitVehicle(?string $vehicle): array
    {
        $parts = preg_split('/\s+/', trim((string) $vehicle), 3);

        return [$parts[0] ?? null, $parts[1] ?? null, $parts[2] ?? null];
    }

    private function defaultVehicleTypeId(): ?int
    {
        return VehicleType::where('slug', 'executive')->value('id') ?? VehicleType::min('id');
    }
}
