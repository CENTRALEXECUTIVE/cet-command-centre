<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Set (or reset) a driver's login and print the credentials to hand to them —
 * so the office can get a driver onto the app quickly for testing or go-live.
 *
 *   php artisan cet:driver-login Maj
 *   php artisan cet:driver-login "Majid Ali" --password=drive1234
 */
class MakeDriverLogin extends Command
{
    protected $signature = 'cet:driver-login {name : driver name / callsign} {--password= : set a specific password}';

    protected $description = "Set a driver's login password and show the credentials";

    public function handle(): int
    {
        $needle = Str::lower(trim($this->argument('name')));

        $driver = User::where('role', UserRole::Driver->value)
            ->whereHas('driverProfile')
            ->with('driverProfile')
            ->get()
            ->first(function (User $u) use ($needle) {
                $local = Str::lower(Str::before((string) $u->email, '@'));
                $callsign = Str::lower((string) ($u->driverProfile?->callsign ?? ''));
                $first = Str::lower(Str::before((string) $u->name, ' '));

                return $callsign === $needle
                    || $first === $needle
                    || $local === $needle
                    || str_contains(Str::lower((string) $u->name), $needle);
            });

        if (! $driver) {
            $this->error("No driver matching \"{$this->argument('name')}\". Try their full name.");
            $this->line('Drivers: '.User::where('role', UserRole::Driver->value)->whereHas('driverProfile')->pluck('name')->join(', '));

            return self::FAILURE;
        }

        $password = $this->option('password') ?: Str::password(10, letters: true, numbers: true, symbols: false, spaces: false);
        $driver->forceFill(['password' => $password, 'is_active' => true])->save();

        $url = rtrim((string) config('app.url'), '/').'/login';

        $this->newLine();
        $this->info("Login ready for {$driver->name}:");
        $this->line("  Website:  {$url}");
        $this->line("  Email:    {$driver->email}");
        $this->line("  Password: {$password}");
        $this->newLine();
        $this->comment('Send those to the driver. They open the website, log in, then My Jobs.');

        return self::SUCCESS;
    }
}
