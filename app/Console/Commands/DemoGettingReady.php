<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Console\Command;

/**
 * Create a throwaway job so you can SEE the driver "Getting ready" checkpoint
 * button on a real screen without waiting for a live pickup. The job is
 * assigned to the given director, Allocated, with a pickup ~25 min out — inside
 * the ~30-min prompt window — so the gold "🟢 Getting ready" card shows the
 * moment you open it.
 *
 *   php artisan cet:demo-getting-ready                          # assigns to the main admin
 *   php artisan cet:demo-getting-ready abdi@example.com         # assign to a specific user
 *   php artisan cet:demo-getting-ready --minutes=25             # pickup this many minutes out
 *
 * Open it at Driver app → My jobs (or /driver/jobs/{id}, printed below).
 * It is NOT a real ETO booking, never touches the calendar, and is removed by
 * `php artisan cet:remove-demo`. Refuses to run in production.
 */
class DemoGettingReady extends Command
{
    protected $signature = 'cet:demo-getting-ready {email? : Director/driver to assign it to} {--minutes=25 : Minutes until pickup}';

    protected $description = 'Create a demo job that shows the "Getting ready" checkpoint button now (staging only)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production — this creates test data. Run it on staging.');

            return self::FAILURE;
        }

        $email = $this->argument('email') ?: config('cet.ops_email', 'admin@centralexecutivetransfers.co.uk');
        $driver = User::where('email', $email)->first();
        if (! $driver) {
            $this->error("No user found with email {$email}. Pass one of your /users emails, e.g. cet:demo-getting-ready you@example.com");

            return self::FAILURE;
        }

        $minutes = max(1, (int) $this->option('minutes'));

        $vehicleType = VehicleType::query()->first()
            ?? VehicleType::create(['name' => 'Executive', 'slug' => 'executive', 'passenger_capacity' => 4, 'affects_rotation' => true]);

        $customer = Customer::firstOrCreate(
            ['email' => 'demo.getting-ready@cet.local'],
            ['name' => 'Demo Passenger', 'phone' => '+447000000000']
        );

        $booking = Booking::create([
            'reference' => Booking::generateReference(),
            'customer_id' => $customer->id,
            'vehicle_type_id' => $vehicleType->id,
            'driver_id' => $driver->id,
            'journey_type' => 'one_way',
            'status' => BookingStatus::Allocated->value,
            'pickup_at' => now()->addMinutes($minutes),
            'pickup_address' => '1 Fargate, Sheffield S1 2HD',
            'destination_address' => 'Manchester Airport (MAN), M90 1QX',
            'passengers' => 2,
            'luggage' => 2,
            'payment_method' => PaymentMethod::Card->value,
            'source' => 'demo',
            // Lead time = now, so the "Getting ready" button shows the instant you open it.
            'meta' => ['demo' => true, 'lead_time' => now()->toIso8601String()],
        ]);

        $this->newLine();
        $this->info('✅ Demo job created — the "Getting ready" button will show on it now.');
        $this->line('   Ref:      '.$booking->reference);
        $this->line('   Driver:   '.$driver->name.'  ('.$driver->email.')');
        $this->line('   Pickup:   '.$booking->pickup_at->format('D d M, H:i').'  ('.$minutes.' min out)');
        $this->line('   Open at:  '.route('driver.job', $booking));
        $this->newLine();
        $this->line('   Sign in as this driver, go to <fg=cyan>Driver app → My jobs</>, tap the job.');
        $this->line('   Clean up afterwards with <fg=cyan>php artisan cet:remove-demo</>.');
        $this->newLine();

        return self::SUCCESS;
    }
}
