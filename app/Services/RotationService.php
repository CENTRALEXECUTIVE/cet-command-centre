<?php

namespace App\Services;

use App\Models\Airport;
use App\Models\Booking;
use App\Models\RotationLog;
use App\Models\RotationState;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The driver rotation engine.
 *
 * Implements the company's rotation rules exactly:
 *  - Abdi and Maj rotate on Executive saloon jobs ONLY (vehicle types where
 *    affects_rotation = true). Minibus, V Class, Estate, Rolls Royce and
 *    third-party jobs never touch the rotation.
 *  - Rotation is tracked per airport, per (rotation-affecting) vehicle type.
 *  - Same customer booking outbound + return → same driver for both, the
 *    pointer advances ONCE only.
 *  - One-way bookings → normal rotation applies independently.
 *  - When a substitute covers an unavailable driver, the original driver keeps
 *    their rotation position (the pointer is NOT advanced by the substitution).
 *  - Any airport not yet in the system → ABDI goes first.
 */
class RotationService
{
    /**
     * A read-only snapshot of the rotation for the admin screen: the ordered
     * rotation drivers, and for every airport × rotation vehicle type who is up
     * next and when the pointer last moved. Nothing here advances anything.
     *
     * @return array{drivers: Collection<int, User>, rows: array<int, array<string, mixed>>}
     */
    public function overview(): array
    {
        $drivers = $this->rotationDrivers();
        $first = $drivers->first();
        $vehicleTypes = VehicleType::where('affects_rotation', true)->orderBy('name')->get();
        $airports = Airport::where('is_active', true)->orderByDesc('is_general_pool')->orderBy('name')->get();

        $states = RotationState::with('nextDriver')->get()
            ->keyBy(fn ($s) => $s->airport_id.'-'.$s->vehicle_type_id);

        $rows = [];
        foreach ($airports as $airport) {
            foreach ($vehicleTypes as $type) {
                $state = $states->get($airport->id.'-'.$type->id);
                // No pointer yet → the default first driver (ABDI) is up next.
                $next = $state?->nextDriver ?? $first;
                $rows[] = [
                    'airport' => $airport,
                    'vehicle_type' => $type,
                    'next' => $next,
                    'last_advanced_at' => $state?->last_advanced_at,
                    'seeded' => (bool) $state,
                ];
            }
        }

        return ['drivers' => $drivers, 'rows' => $rows];
    }

    /**
     * The ordered rotation drivers (Abdi, then Maj), public for display.
     *
     * @return Collection<int, User>
     */
    public function order(): Collection
    {
        return $this->rotationDrivers();
    }

    /**
     * Determine the driver who should take a job for the given airport and
     * vehicle type WITHOUT advancing the pointer. Returns null when the vehicle
     * type does not affect rotation (third-party / non-saloon work).
     */
    public function nextDriverFor(?Airport $airport, VehicleType $vehicleType): ?User
    {
        if (! $vehicleType->affects_rotation) {
            return null;
        }

        $airport ??= $this->generalPool();

        if (! $airport) {
            return $this->defaultFirstDriver();
        }

        $state = RotationState::firstOrNew([
            'airport_id' => $airport->id,
            'vehicle_type_id' => $vehicleType->id,
        ]);

        // Airport not yet seeded for this vehicle type → ABDI goes first.
        if (! $state->exists || ! $state->next_driver_id) {
            return $this->defaultFirstDriver();
        }

        return $state->nextDriver;
    }

    /**
     * Allocate a driver to a booking and advance rotation according to the rules.
     * Safe to call inside a transaction. Returns the allocated driver (or null
     * for non-rotation vehicle types — those are allocated manually/third-party).
     */
    public function allocate(Booking $booking): ?User
    {
        $vehicleType = $booking->vehicleType;

        if (! $vehicleType || ! $vehicleType->affects_rotation) {
            return null; // Non-saloon work does not use the rotation engine.
        }

        return DB::transaction(function () use ($booking, $vehicleType) {
            // Return leg of a paired booking inherits the outbound driver and
            // does NOT advance the pointer (rotation already moved once).
            if ($booking->is_return_leg && $booking->linkedBooking?->driver_id) {
                $driver = $booking->linkedBooking->driver;
                $this->assignDriver($booking, $driver, advancedRotation: false);
                $this->log($booking, null, $driver, 'paired_return_no_advance');

                return $driver;
            }

            $airport = $booking->airport ?? $this->generalPool();
            $driver = $this->nextDriverFor($airport, $vehicleType);

            if (! $driver) {
                return null;
            }

            $this->assignDriver($booking, $driver, advancedRotation: true);
            $this->advancePointer($airport, $vehicleType, $driver, $booking);

            return $driver;
        });
    }

    /**
     * Record that a substitute covered a job. The original driver retains their
     * rotation position, so the pointer is untouched — we only re-assign the
     * booking and log the substitution.
     */
    public function substitute(Booking $booking, User $substitute): void
    {
        $original = $booking->driver;
        $booking->forceFill(['driver_id' => $substitute->id])->save();
        $this->log($booking, $original, $substitute, 'substitution_no_advance');
    }

    /** Advance the pointer to the OTHER rotation driver. */
    protected function advancePointer(Airport $airport, VehicleType $vehicleType, User $currentDriver, Booking $booking): void
    {
        $next = $this->otherRotationDriver($currentDriver);

        $state = RotationState::firstOrNew([
            'airport_id' => $airport->id,
            'vehicle_type_id' => $vehicleType->id,
        ]);
        $state->next_driver_id = $next?->id;
        $state->last_advanced_at = now();
        $state->save();

        $this->log($booking, $currentDriver, $next, 'advance');
    }

    /**
     * Operator override: set who is UP NEXT for an airport × vehicle type,
     * e.g. to bring the pointers in line with the real-world order after a
     * migration. Logged as a manual override; nothing else moves.
     */
    public function setNextDriver(Airport $airport, VehicleType $vehicleType, User $driver): void
    {
        $state = RotationState::firstOrNew([
            'airport_id' => $airport->id,
            'vehicle_type_id' => $vehicleType->id,
        ]);
        $from = $state->nextDriver;
        $state->next_driver_id = $driver->id;
        $state->last_advanced_at = now();
        $state->save();

        RotationLog::create([
            'airport_id' => $airport->id,
            'vehicle_type_id' => $vehicleType->id,
            'booking_id' => null,
            'from_driver_id' => $from?->id,
            'to_driver_id' => $driver->id,
            'reason' => 'manual_override',
        ]);
    }

    protected function assignDriver(Booking $booking, ?User $driver, bool $advancedRotation): void
    {
        $booking->forceFill([
            'driver_id' => $driver?->id,
            'affected_rotation' => $advancedRotation,
        ])->save();
    }

    protected function log(Booking $booking, ?User $from, ?User $to, string $reason): void
    {
        RotationLog::create([
            'airport_id' => $booking->airport_id,
            'vehicle_type_id' => $booking->vehicle_type_id,
            'booking_id' => $booking->id,
            'from_driver_id' => $from?->id,
            'to_driver_id' => $to?->id,
            'reason' => $reason,
        ]);
    }

    /**
     * The two rotation drivers are Abdi and Maj. They log in as admins but also
     * drive, so we identify them by their non-third-party driver profile rather
     * than their login role. Ordered by id, so the first-created (Abdi) is the
     * default "goes first" driver. No hard-coded ids.
     *
     * @return Collection<int, User>
     */
    protected function rotationDrivers()
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('driverProfile', fn ($q) => $q->where('is_third_party', false))
            ->orderBy('id')
            ->get();
    }

    protected function otherRotationDriver(User $current)
    {
        return $this->rotationDrivers()->firstWhere('id', '!=', $current->id);
    }

    /** ABDI goes first — the first non-third-party rotation driver by id. */
    protected function defaultFirstDriver(): ?User
    {
        return $this->rotationDrivers()->first();
    }

    protected function generalPool(): ?Airport
    {
        return Airport::where('is_general_pool', true)->first();
    }
}
