<?php

namespace App\Console\Commands;

use App\Models\VehicleType;
use App\Services\Pricing\DistanceService;
use App\Services\Pricing\TripPricer;
use App\Services\Watchdog\AdminAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * 24/7 website price watch (directors, 26 Nov 2026 — a JELD-WEN Manchester → Sheffield
 * return showed "On request" and became a quote request). Every 15 minutes it prices our
 * bread-and-butter journeys through the SAME live engine the website uses and tells both
 * directors at once if any comes back "On request", at the wrong fixed fare, or if
 * Google Maps has stopped answering. Read-only: nothing is booked or saved.
 */
class PriceWatch extends Command
{
    protected $signature = 'cet:price-watch {--quiet-ok : print nothing when all is well}';

    protected $description = 'Check the website prices standard journeys correctly; alert the directors if not';

    /** [airport text, local text, expected Executive fare per leg]. Google-style wording. */
    public const CHECKS = [
        ['Manchester Airport (MAN), Manchester M90 1QX, UK', 'Sheffield, UK', 105],
        ['Manchester Airport (MAN), Manchester M90 1QX, UK', 'JELD-WEN UK, Shepcote Lane, Sheffield, UK', 105],
        ['Manchester Airport (MAN), Manchester M90 1QX, UK', 'Courtyard by Marriott Sheffield, Cambridge Street, Sheffield, UK', 105],
        ['Manchester Airport Terminal 2, Manchester M90 3RA, UK', 'Shepcote Lane, Sheffield S9 1DZ, UK', 105],
        ['Manchester Airport (MAN), Manchester M90 1QX, UK', 'Rotherham, UK', 105],
        ['Manchester Airport (MAN), Manchester M90 1QX, UK', 'Barnsley, UK', 105],
        ['Leeds Bradford Airport (LBA), Leeds LS19 7TU, UK', 'Sheffield, UK', 105],
        ['East Midlands Airport (EMA), Derby DE74 2SA, UK', 'Sheffield S1 2HH, UK', 105],
    ];

    public function handle(TripPricer $pricer, DistanceService $distance, AdminAlerts $alerts): int
    {
        $exec = VehicleType::where('slug', 'executive')->first();
        if (! $exec) {
            return self::SUCCESS;
        }
        $when = Carbon::now(config('app.timezone'))->addDays(14)->setTime(11, 0);
        $problems = [];

        foreach (self::CHECKS as [$airport, $local, $expected]) {
            foreach ([[$airport, $local], [$local, $airport]] as [$from, $to]) {
                try {
                    $r = $pricer->price($exec, ['pickup' => $from, 'destination' => $to, 'pickup_at' => $when, 'journey_type' => 'one_way']);
                    $fare = $r['out']['base'] ?? null;
                    if ($r['poa'] || $r['total'] === null) {
                        $problems[] = "\"On request\": {$from} → {$to}";
                    } elseif ($fare !== null && abs((float) $fare - $expected) > 0.01) {
                        $problems[] = "£{$fare} not £{$expected}: {$from} → {$to}";
                    }
                } catch (\Throwable $e) {
                    $problems[] = "Error pricing {$from} → {$to}: ".mb_substr($e->getMessage(), 0, 120);
                }
            }
        }

        // Google Maps answering? (Addresses without a postcode lean on it for distance.)
        if ($distance->configured()) {
            try {
                $d = $distance->resolve('Sheffield S1 2HH, UK', 'Rotherham S60 1AA, UK');
                if (($d['source'] ?? null) === 'estimate') {
                    $problems[] = 'Google Maps is not answering distance checks — check the Maps key/billing.';
                }
            } catch (\Throwable $e) {
                $problems[] = 'Google Maps check failed: '.mb_substr($e->getMessage(), 0, 120);
            }
        }

        Cache::put('price_watch_last', ['at' => now()->toIso8601String(), 'problems' => $problems], now()->addDay());

        if ($problems === []) {
            if (! $this->option('quiet-ok')) {
                $this->info('Price watch: all '.(count(self::CHECKS) * 2).' journeys priced correctly.');
            }

            return self::SUCCESS;
        }

        foreach ($problems as $p) {
            $this->warn($p);
        }
        // At most once an hour for the same set of problems.
        $key = 'price_watch_alert:'.md5(implode('|', $problems));
        if (Cache::add($key, 1, now()->addHour())) {
            $alerts->notifyDirectors('price_watch',
                '🚨 Website prices wrong — customers may not be able to book',
                count($problems).' problem(s): '.mb_substr(implode(' · ', $problems), 0, 400),
                'critical');
        }

        return self::FAILURE;
    }
}
