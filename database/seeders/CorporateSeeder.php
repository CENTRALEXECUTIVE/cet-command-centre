<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\CorporateAccount;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds the three corporate accounts, their named contacts, and a portal login
 * per account (Phase 4 portal foundation).
 */
class CorporateSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('CET_SEED_PASSWORD', 'ChangeMe!2026');

        $accounts = [
            [
                'name' => 'JELD-WEN',
                'account_code' => 'JELDWEN',
                'contacts' => [
                    ['name' => 'Jackie Donoghue', 'is_primary' => true],
                    ['name' => 'Claire Green'],
                ],
                'login_email' => 'bookings@jeld-wen.example',
            ],
            [
                'name' => 'LB Foster',
                'account_code' => 'LBFOSTER',
                'contacts' => [
                    ['name' => 'Abi Atkin', 'is_primary' => true],
                ],
                'login_email' => 'bookings@lbfoster.example',
            ],
            [
                'name' => 'Forged Solutions Group',
                'account_code' => 'FORGED',
                'contacts' => [
                    ['name' => 'Nicola Wright', 'is_primary' => true],
                ],
                'login_email' => 'bookings@forgedsolutions.example',
            ],
        ];

        foreach ($accounts as $data) {
            $account = CorporateAccount::updateOrCreate(
                ['account_code' => $data['account_code']],
                [
                    'name' => $data['name'],
                    'slug' => Str::slug($data['name']),
                    'cost_code_required' => true,
                    'payment_terms_days' => 30,
                    'is_active' => true,
                ]
            );

            foreach ($data['contacts'] as $contact) {
                $account->contacts()->updateOrCreate(
                    ['name' => $contact['name']],
                    ['is_primary' => $contact['is_primary'] ?? false]
                );
            }

            // Portal login for the account.
            $user = User::updateOrCreate(
                ['email' => $data['login_email']],
                [
                    'name' => $data['name'].' Bookings',
                    'password' => Hash::make($password),
                    'role' => UserRole::CorporateClient->value,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            $account->users()->syncWithoutDetaching([
                $user->id => ['can_view_all_account_bookings' => true],
            ]);
        }
    }
}
