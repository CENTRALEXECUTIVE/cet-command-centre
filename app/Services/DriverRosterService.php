<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Booking;
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

        // Prefer a REAL matching driver (rotation drivers etc.) so "Abdi"/"Majid"
        // attach to them instead of duplicating; else the already-linked account;
        // else a new no-login account.
        $linked = $driver->user_id ? User::find($driver->user_id) : null;
        $canonical = $this->findExistingDriver($driver->name);
        $user = $canonical ?: $linked;

        if (! $user) {
            $user = new User(['email' => $this->emailFor($driver)]);
            $user->password = Str::password(20);
            $user->email_verified_at = now();
            $user->name = $driver->name;
        }

        // Clean up a now-redundant duplicate: if we switched to a real driver and
        // the old linked account was a throwaway (@cet-drivers.local) with no
        // jobs, remove it so it stops showing twice in the list.
        if ($linked && $canonical && $linked->id !== $canonical->id
            && $this->isSynthetic($linked) && ! Booking::where('driver_id', $linked->id)->exists()) {
            optional($linked->driverProfile)->delete();
            $linked->delete();
        }

        $user->fill([
            'phone' => $driver->phone ?: $user->phone,
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
        return Str::slug($driver->name.'-'.($driver->vehicle_reg ?: $driver->id)).'@cet-drivers.local';
    }

    /**
     * An existing driver that plainly matches this name — by login local-part
     * ("abdi@…"), callsign, or first name — so the roster attaches to it rather
     * than creating a duplicate. Null when there's no clear match.
     */
    private function isSynthetic(User $user): bool
    {
        return str_ends_with((string) $user->email, '@cet-drivers.local');
    }

    /**
     * Remove throwaway driver accounts left over from an earlier sync that no
     * directory entry points to any more (e.g. a duplicate "Abdi" once its
     * directory row was re-attached to the real Abdirazak). Only deletes ones
     * with no jobs.
     */
    public function pruneOrphanSyntheticDrivers(): int
    {
        $linkedIds = CoverDriver::whereNotNull('user_id')->pluck('user_id')->all();

        $orphans = User::where('email', 'like', '%@cet-drivers.local')
            ->whereNotIn('id', $linkedIds)
            ->get();

        $removed = 0;
        foreach ($orphans as $orphan) {
            if (Booking::where('driver_id', $orphan->id)->exists()) {
                continue;
            }
            optional($orphan->driverProfile)->delete();
            $orphan->delete();
            $removed++;
        }

        return $removed;
    }

    private function findExistingDriver(string $name): ?User
    {
        $lower = Str::lower(trim($name));

        return User::where('role', UserRole::Driver->value)
            ->where('email', 'not like', '%@cet-drivers.local') // real drivers only
            ->whereHas('driverProfile')
            ->with('driverProfile')
            ->get()
            ->first(function (User $u) use ($lower) {
                $local = Str::lower(Str::before((string) $u->email, '@'));
                $callsign = Str::lower((string) ($u->driverProfile?->callsign ?? ''));
                $first = Str::lower(Str::before((string) $u->name, ' '));

                return $local === $lower || $callsign === $lower || $first === $lower;
            });
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
