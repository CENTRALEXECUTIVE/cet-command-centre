<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin onboarding of drivers: create their login + driver profile (and,
 * optionally, their vehicle) in one step, then send them to their documents
 * page to add compliance files.
 */
class DriverController extends Controller
{
    public function create(): View
    {
        return view('admin.drivers.create', ['vehicleTypes' => VehicleType::orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // Email is the login, but it's OPTIONAL — a cover / third-party driver the
            // office dispatches and messages on their phone doesn't need to log in.
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['nullable', 'string', 'min:8', 'max:72'],
            'callsign' => ['nullable', 'string', 'max:40'],
            'is_third_party' => ['nullable', 'boolean'],
            // Optional vehicle.
            'registration' => ['nullable', 'string', 'max:16'],
            'make' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'colour' => ['nullable', 'string', 'max:40'],
            'year' => ['nullable', 'integer', 'min:1990', 'max:2100'],
            'vehicle_type_id' => ['nullable', Rule::exists('vehicle_types', 'id')],
        ]);

        // Already on CET? If this registration is already a driver's vehicle, this
        // person is on the system (often added via the driver directory) — don't
        // create a duplicate (the plate is unique, so a second insert 500s). Take the
        // office straight to that driver's record to add/update their documents.
        if (! empty($data['registration'])) {
            $reg = strtoupper(trim($data['registration']));
            $existingVehicle = Vehicle::where('registration', $reg)->first();
            $owner = $existingVehicle
                ? User::where('role', UserRole::Driver->value)
                    ->whereHas('driverProfile', fn ($q) => $q->where('default_vehicle_id', $existingVehicle->id))
                    ->first()
                : null;
            if ($owner) {
                return redirect()->route('driver-documents.show', $owner)
                    ->with('status', "{$owner->name} ({$reg}) is already on CET — no need to add them again. Add or update their documents below.");
            }
        }

        // No email given → the driver has no app login (dispatched/messaged on their
        // phone). Synthesise a unique, non-routable placeholder so the account can
        // exist without a real inbox — keeps the not-null/unique column happy with no
        // schema change. "@no-login.cet" is deliberately unroutable so nothing mails it.
        $hasLogin = filled($data['email'] ?? null);
        $email = $hasLogin ? $data['email'] : 'driver-'.Str::lower(Str::random(12)).'@no-login.cet';

        // Use the given password, or generate a shareable one to show once.
        $plainPassword = $data['password'] ?? Str::password(12);

        // Create the login, vehicle and profile atomically — if anything fails, no
        // half-made driver is left behind (the earlier 500 created the user but not
        // the profile, orphaning the account).
        $user = DB::transaction(function () use ($data, $email, $plainPassword) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $email,
                'phone' => $data['phone'] ?? null,
                'password' => $plainPassword, // hashed by the model cast
                'role' => UserRole::Driver->value,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            $vehicleId = null;
            if (! empty($data['registration'])) {
                // Registration is unique — a plate that's already in the system (a
                // re-submit, or that car added before) must REUSE the existing vehicle,
                // not blow up with a duplicate-key 500. Match on the plate and refresh
                // the details given (blank fields never wipe what's already stored).
                $vehicle = Vehicle::updateOrCreate(
                    ['registration' => strtoupper(trim($data['registration']))],
                    array_filter([
                        'vehicle_type_id' => $data['vehicle_type_id'] ?? VehicleType::where('slug', 'executive')->value('id'),
                        'make' => $data['make'] ?? null,
                        'model' => $data['model'] ?? null,
                        'colour' => $data['colour'] ?? null,
                        'year' => $data['year'] ?? null,
                        'is_active' => true,
                    ], fn ($v) => $v !== null),
                );
                $vehicleId = $vehicle->id;
            }

            DriverProfile::create([
                'user_id' => $user->id,
                'callsign' => $data['callsign'] ?? null,
                'is_third_party' => (bool) ($data['is_third_party'] ?? false),
                'default_vehicle_id' => $vehicleId,
                'is_available' => true,
            ]);

            return $user;
        });

        $loginLine = $hasLogin
            ? "Login: {$user->email} · password: {$plainPassword} (share it, then they can change it)."
            : 'No email login — dispatch and message them on their phone. (Add an email later if they need the driver app.)';

        return redirect()
            ->route('driver-documents.show', $user)
            ->with('status', "Driver {$user->name} created. {$loginLine} Now add their documents below.");
    }

    public function edit(User $user): View
    {
        $user->load('driverProfile.defaultVehicle');

        return view('admin.drivers.edit', [
            'driver' => $user,
            'profile' => $user->driverProfile,
            'vehicle' => $user->driverProfile?->defaultVehicle,
            'vehicleTypes' => VehicleType::orderBy('name')->get(),
        ]);
    }

    /**
     * Admin can edit everything about a driver in one place: their login/account,
     * their compliance details (PHV badge, DBS, driving licence), and their
     * vehicle (registration and all the necessary info). The vehicle is created
     * on the fly if the driver doesn't have one yet and a registration is given.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            // Account.
            'name' => ['required', 'string', 'max:120'],
            // Optional — a driver with no app login keeps their placeholder; leaving
            // it blank doesn't wipe an existing login.
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:8', 'max:72'],
            // Driver profile / compliance.
            'callsign' => ['nullable', 'string', 'max:40'],
            'is_third_party' => ['nullable', 'boolean'],
            'is_available' => ['nullable', 'boolean'],
            'phv_badge_number' => ['nullable', 'string', 'max:60'],
            'phv_badge_expiry' => ['nullable', 'date'],
            'dbs_status' => ['nullable', 'string', 'max:60'],
            'dbs_issue_date' => ['nullable', 'date'],
            'dbs_expiry' => ['nullable', 'date'],
            'driving_licence_number' => ['nullable', 'string', 'max:60'],
            'driving_licence_expiry' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Vehicle.
            'vehicle_type_id' => ['nullable', Rule::exists('vehicle_types', 'id')],
            'registration' => ['nullable', 'string', 'max:16'],
            'make' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'colour' => ['nullable', 'string', 'max:40'],
            'year' => ['nullable', 'integer', 'min:1990', 'max:2100'],
            'mot_expiry' => ['nullable', 'date'],
            'insurance_expiry' => ['nullable', 'date'],
            'phv_licence_expiry' => ['nullable', 'date'],
            'compliance_test_date' => ['nullable', 'date'],
            'vehicle_notes' => ['nullable', 'string', 'max:2000'],
            'vehicle_active' => ['nullable', 'boolean'],
        ]);

        $user->fill([
            'name' => $data['name'],
            // Keep the existing login (real or placeholder) when the field is left blank.
            'email' => filled($data['email'] ?? null) ? $data['email'] : $user->email,
            'phone' => $data['phone'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        $profile = $user->driverProfile()->firstOrCreate(['user_id' => $user->id]);
        $profile->fill([
            'callsign' => $data['callsign'] ?? null,
            'is_third_party' => (bool) ($data['is_third_party'] ?? false),
            'is_available' => (bool) ($data['is_available'] ?? false),
            'phv_badge_number' => $data['phv_badge_number'] ?? null,
            'phv_badge_expiry' => $data['phv_badge_expiry'] ?? null,
            'dbs_status' => $data['dbs_status'] ?? null,
            'dbs_issue_date' => $data['dbs_issue_date'] ?? null,
            'dbs_expiry' => $data['dbs_expiry'] ?? null,
            'driving_licence_number' => $data['driving_licence_number'] ?? null,
            'driving_licence_expiry' => $data['driving_licence_expiry'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        // Update the driver's vehicle in place, or create one if they don't have
        // a vehicle yet and a registration has been provided.
        $vehicle = $profile->defaultVehicle;
        if ($vehicle || ! empty($data['registration'])) {
            $vehicleData = [
                'vehicle_type_id' => $data['vehicle_type_id']
                    ?? $vehicle?->vehicle_type_id
                    ?? VehicleType::where('slug', 'executive')->value('id'),
                'registration' => ! empty($data['registration']) ? strtoupper($data['registration']) : $vehicle?->registration,
                'make' => $data['make'] ?? null,
                'model' => $data['model'] ?? null,
                'colour' => $data['colour'] ?? null,
                'year' => $data['year'] ?? null,
                'mot_expiry' => $data['mot_expiry'] ?? null,
                'insurance_expiry' => $data['insurance_expiry'] ?? null,
                'phv_licence_expiry' => $data['phv_licence_expiry'] ?? null,
                'compliance_test_date' => $data['compliance_test_date'] ?? null,
                'notes' => $data['vehicle_notes'] ?? null,
                'is_active' => (bool) ($data['vehicle_active'] ?? true),
            ];

            // Registration is unique. Whether creating or editing, a plate that
            // already belongs to ANOTHER vehicle must reuse that row rather than
            // crash on the unique key.
            $reg = $vehicleData['registration'] ? strtoupper(trim($vehicleData['registration'])) : null;
            $vehicleData['registration'] = $reg;
            $clash = $reg
                ? Vehicle::where('registration', $reg)->when($vehicle, fn ($q) => $q->where('id', '!=', $vehicle->id))->first()
                : null;

            if ($clash) {
                // The plate is already on file — update that car and point the driver at it.
                $clash->update(collect($vehicleData)->except('registration')->all());
                $profile->default_vehicle_id = $clash->id;
            } elseif ($vehicle) {
                $vehicle->update($vehicleData);
            } else {
                $vehicle = Vehicle::create($vehicleData);
                $profile->default_vehicle_id = $vehicle->id;
            }
        }

        $profile->save();

        return redirect()->route('driver-documents.show', $user)->with('status', "{$user->name} updated.");
    }
}
