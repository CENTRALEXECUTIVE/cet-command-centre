<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Models\Airport;
use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\Customer;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Models\WaitingListEntry;
use App\Services\ComplianceService;
use App\Services\Marketing\AdsSyncService;
use App\Services\Payments\InvoiceService;
use App\Services\Pricing\AiPricingEngine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Populates a STAGING/TEST instance with realistic data so every screen can be
 * walked through (despatch board, driver app, reports, invoices, compliance,
 * ads, quotes, waiting list). Run after the base seeders:
 *
 *   php artisan db:seed --class=Database\\Seeders\\DemoSeeder
 *
 * Refuses to run in production to protect live data.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('DemoSeeder will not run in production.');

            return;
        }

        $exec = VehicleType::where('slug', 'executive')->first();
        $vclass = VehicleType::where('slug', 'v-class')->first();
        $abdi = User::where('email', 'abdi@centralexecutivetransfers.co.uk')->first();
        $maj = User::where('email', 'maj@centralexecutivetransfers.co.uk')->first();

        // A third-party driver (for non-rotation work).
        $haseeb = User::updateOrCreate(
            ['email' => 'haseeb@thirdparty.example'],
            ['name' => 'Haseeb Muhammed', 'password' => Hash::make('ChangeMe!2026'),
                'role' => 'driver', 'phone' => '07700900090', 'is_active' => true, 'email_verified_at' => now()],
        );
        DriverProfile::updateOrCreate(['user_id' => $haseeb->id], ['is_third_party' => true, 'is_available' => true]);

        // Fleet vehicles with compliance dates (valid / due-soon / expired).
        $v1 = Vehicle::updateOrCreate(['registration' => 'KR21 UJB'], [
            'vehicle_type_id' => $exec->id, 'make' => 'Mercedes', 'model' => 'E-Class', 'colour' => 'Black',
            'mot_expiry' => now()->addDays(20), 'insurance_expiry' => now()->addMonths(5),
            'phv_licence_expiry' => now()->subDays(3), 'compliance_test_date' => now()->addMonths(2), 'is_active' => true,
        ]);
        Vehicle::updateOrCreate(['registration' => 'CET 1'], [
            'vehicle_type_id' => $vclass->id, 'make' => 'Mercedes', 'model' => 'V-Class', 'colour' => 'Black',
            'mot_expiry' => now()->addMonths(8), 'insurance_expiry' => now()->addDays(12),
            'phv_licence_expiry' => now()->addMonths(9), 'is_active' => true,
        ]);
        DriverProfile::where('user_id', $abdi->id)->update([
            'default_vehicle_id' => $v1->id, 'phv_badge_expiry' => now()->addDays(25), 'dbs_expiry' => now()->addMonths(7),
        ]);
        app(ComplianceService::class)->sync();

        // Customers + a spread of bookings.
        $airports = ['MAN', 'LHR', 'LBA', 'BHX', 'STN'];
        $names = ['James Watson', 'Geoff Bowen', 'Sarah Hughes', 'Priya Patel', 'Tom Clarke', 'Anna Webb',
            'David Reed', 'Mark Field', 'Lucy Hall', 'Omar Khan', 'Helen Carr', 'Raj Singh'];

        // Past completed jobs (last 30 days) for revenue reports.
        foreach (range(1, 24) as $i) {
            $this->booking($exec, $abdi, BookingStatus::Complete, $names[$i % count($names)],
                now()->subDays(rand(1, 28))->setTime(rand(6, 21), 0), $airports[$i % 5], rand(90, 320));
        }

        // Today — across the despatch lifecycle.
        $today = [
            [BookingStatus::Pending, null, 'Priya Patel', 'MAN'],
            [BookingStatus::Pending, null, 'David Reed', null],
            [BookingStatus::Allocated, $maj, 'Tom Clarke', 'LBA'],
            [BookingStatus::Accepted, $abdi, 'James Watson', 'MAN'],
            [BookingStatus::EnRoute, $abdi, 'Geoff Bowen', 'LHR'],
            [BookingStatus::Collected, $maj, 'Sarah Hughes', 'BHX'],
            [BookingStatus::Complete, $abdi, 'Anna Webb', 'LHR'],
        ];
        foreach ($today as $i => [$status, $driver, $name, $apt]) {
            $this->booking($exec, $driver, $status, $name, now()->setTime(7 + $i * 2, 0), $apt, rand(80, 260));
        }

        // Future pending/allocated.
        foreach (range(1, 6) as $i) {
            $this->booking($i % 2 ? $exec : $vclass, $i % 2 ? $abdi : $haseeb,
                $i % 2 ? BookingStatus::Allocated : BookingStatus::Pending,
                $names[$i], now()->addDays($i)->setTime(9 + $i, 30), $airports[$i % 5], rand(90, 300));
        }

        // A corporate account booking + generated invoice.
        $account = CorporateAccount::where('account_code', 'JELDWEN')->first();
        if ($account) {
            $account->update(['billing_email' => 'accounts@jeld-wen.example']);
            $b = $this->booking($exec, $abdi, BookingStatus::Complete, 'Jackie Donoghue',
                now()->subMonthNoOverflow()->startOfMonth()->addDays(4)->setTime(8, 0), 'MAN', 140);
            $b->update(['corporate_account_id' => $account->id, 'payment_method' => 'account', 'cost_code' => 'CC-2026']);
            app(InvoiceService::class)->generateForAccount(
                $account, now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()
            );
        }

        // 30 days of ad metrics for the Google Ads dashboard.
        $ads = app(AdsSyncService::class);
        foreach (range(0, 29) as $d) {
            $spend = rand(30, 120);
            $ads->upsertDaily(['date' => now()->subDays($d)->toDateString(), 'campaign' => 'CET Airport Transfers',
                'spend' => $spend, 'revenue' => $spend * (rand(25, 60) / 10), 'conversions' => rand(0, 4),
                'jobs' => rand(0, 3), 'clicks' => rand(20, 120), 'impressions' => rand(500, 4000)]);
        }

        // A sample AI quote + a waiting-list entry.
        app(AiPricingEngine::class)->quote([
            'vehicle_type_id' => $exec->id, 'pickup_address' => 'Sheffield S1 2GA',
            'destination_address' => 'Manchester Airport', 'pickup_at' => now()->addDays(3)->format('Y-m-d 09:00'),
            'distance_miles' => 42, 'duration_minutes' => 55,
        ]);
        $waiter = Customer::firstOrCreate(['phone' => '07700900345'], ['name' => 'Eleanor Shaw']);
        WaitingListEntry::updateOrCreate(
            ['customer_id' => $waiter->id, 'desired_date' => now()->addDays(2)->toDateString()],
            ['vehicle_type_id' => $exec->id, 'desired_time_window' => 'morning', 'status' => 'waiting']
        );

        $this->command->info('Demo data seeded: '.Booking::count().' bookings, '.Customer::count().' customers.');
    }

    private function booking(VehicleType $vt, ?User $driver, BookingStatus $status, string $name, $pickupAt, ?string $apt, int $price): Booking
    {
        $customer = Customer::firstOrCreate(['phone' => '0770'.str_pad((string) (crc32($name) % 1000000), 6, '0', STR_PAD_LEFT)], ['name' => $name]);
        $airport = $apt ? Airport::where('code', $apt)->first() : null;

        return Booking::create([
            'reference' => Booking::generateReference(),
            'customer_id' => $customer->id,
            'vehicle_type_id' => $vt->id,
            'airport_id' => $airport?->id,
            'driver_id' => $driver?->id,
            'pickup_at' => $pickupAt,
            'pickup_address' => $apt ? "{$apt} Airport, Terminal 2" : '12 Fargate, Sheffield S1 2GA',
            'destination_address' => $apt ? 'Radisson Blu Hotel, Pinstone Street, Sheffield' : 'Manchester Airport (MAN)',
            'passengers' => rand(1, min(4, $vt->passenger_capacity)),
            'status' => $status->value,
            'quoted_price' => $price,
            'final_price' => $status === BookingStatus::Complete ? $price : null,
            'payment_method' => rand(0, 1) ? 'card' : 'cash',
            'flight_number' => $apt ? 'BA'.rand(100, 999) : null,
            'source' => 'phone',
        ]);
    }
}
