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

        $isNew = ! $user || ! $user->exists;

        if ($isNew) {
            // Brand-new no-login driver account.
            $user = new User(['email' => $this->emailFor($driver)]);
            $user->name = $driver->name;
            $user->role = UserRole::Driver->value;
            $user->password = Str::password(20);
            $user->email_verified_at = now();
            $user->is_active = (bool) $driver->is_active;
            if (filled($driver->phone)) {
                $user->phone = $driver->phone;
            }
            $user->save();
        } elseif (blank($user->phone) && filled($driver->phone)) {
            // Existing account (may be an admin/director who also drives) — DON'T
            // touch their role, name, active flag or login; just fill a missing phone.
            $user->forceFill(['phone' => $driver->phone])->save();
        }

        // Clean up a now-redundant duplicate: if we switched to a real driver and
        // the old linked account was a throwaway (@cet-drivers.local) with no
        // jobs, remove it so it stops showing twice in the list.
        if ($linked && $user && $linked->id !== $user->id
            && $this->isSynthetic($linked) && ! Booking::where('driver_id', $linked->id)->exists()) {
            optional($linked->driverProfile)->delete();
            $linked->delete();
        }

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

        // Set the callsign + vehicle so reminders/dispatch show "Abdi (LR69 HHP)".
        $profile = DriverProfile::firstOrNew(['user_id' => $user->id]);
        if (! $profile->exists) {
            $profile->is_third_party = true;
            $profile->is_available = true;
        }
        $profile->callsign = $driver->name;
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
        if ($lower === '') {
            return null;
        }

        // Any real account with a driver profile — INCLUDING admin/directors who
        // also drive (Abdi, Majid) — so the roster attaches to them, not a clone.
        $candidates = User::where('email', 'not like', '%@cet-drivers.local')
            ->whereHas('driverProfile')
            ->with('driverProfile')
            ->get();

        // 1) An UNAMBIGUOUS match: the full name, the callsign, or the login
        //    local-part matches exactly. This is definitely the same person.
        $exact = $candidates->first(function (User $u) use ($lower) {
            $local = Str::lower(Str::before((string) $u->email, '@'));
            $callsign = Str::lower(trim((string) ($u->driverProfile?->callsign ?? '')));

            return Str::lower(trim((string) $u->name)) === $lower
                || ($callsign !== '' && $callsign === $lower)
                || (ctype_alpha($local) && $local === $lower);
        });
        if ($exact) {
            return $exact;
        }

        // 2) First-name-only match is allowed ONLY when the incoming name is a
        //    single word (no surname to tell people apart) AND exactly one driver
        //    has that first name. So "Hamza" attaches to the sole Hamza, but
        //    "Hamza Ali" and "Hamza Khan" stay as two different people.
        if (! str_contains($lower, ' ')) {
            $byFirst = $candidates->filter(
                fn (User $u) => Str::lower(Str::before(trim((string) $u->name), ' ')) === $lower
            );
            if ($byFirst->count() === 1) {
                return $byFirst->first();
            }
        }

        return null;
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
