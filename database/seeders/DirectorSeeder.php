<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the two directors, Abdi and Maj. They log in as administrators (full
 * control) but also drive the Executive saloon, so each gets a non-third-party
 * driver profile — that is what the rotation engine keys on. Abdi is created
 * first, giving him the lower id and therefore the "goes first" default.
 */
class DirectorSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('CET_SEED_PASSWORD', 'ChangeMe!2026');

        $abdi = User::updateOrCreate(
            ['email' => 'abdi@centralexecutivetransfers.co.uk'],
            [
                'name' => 'Abdirazak Hassan',
                'password' => Hash::make($password),
                'role' => UserRole::Admin->value,
                'phone' => null,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $maj = User::updateOrCreate(
            ['email' => 'maj@centralexecutivetransfers.co.uk'],
            [
                'name' => 'Majid Ali',
                'password' => Hash::make($password),
                'role' => UserRole::Admin->value,
                'phone' => null,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        foreach ([$abdi, $maj] as $director) {
            DriverProfile::updateOrCreate(
                ['user_id' => $director->id],
                ['is_third_party' => false, 'is_available' => true]
            );
        }
    }
}
