<?php

namespace App\Console\Commands;

use App\Http\Controllers\Widget\BookingWidgetController;
use App\Models\Booking;
use App\Models\BookingAttempt;
use App\Models\VehicleType;
use App\Services\Watchdog\AdminAlerts;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * A TEST CUSTOMER that books through the real website booking form every 15 minutes
 * (directors, 27 Nov 2026 — JELD-WEN twice failed to book and nobody knew). It runs the
 * SAME code a customer's press runs: prices, every check, saving the booking (and the
 * return leg), the booking log. Then it UNDOES everything: the whole run is inside a
 * database transaction that is rolled back, and emails/alerts are switched off for the
 * run — so no test booking, email or alert ever reaches anyone.
 *
 * If any step fails, both directors get a critical alert (at most once an hour for the
 * same problem). Also run straight after every deploy (cet:auto-deploy).
 */
class BookingHealthCheck extends Command
{
    protected $signature = 'cet:booking-health-check {--quiet-ok : print nothing when all is well}';

    protected $description = 'Book a test journey through the real website form (fully undone); alert the directors if it fails';

    public function handle(AdminAlerts $alerts): int
    {
        $problems = $this->check();
        Cache::put('booking_health_last', ['at' => now()->toIso8601String(), 'problems' => $problems], now()->addDay());

        if ($problems === []) {
            if (! $this->option('quiet-ok')) {
                $this->info('Booking health check: the website booking form works (test booking made and undone).');
            }

            return self::SUCCESS;
        }
        foreach ($problems as $p) {
            $this->warn($p);
        }
        if (Cache::add('booking_health_alert:'.md5(implode('|', $problems)), 1, now()->addHour())) {
            $alerts->notifyDirectors('price_watch',
                '🚨 Website booking form is FAILING — customers cannot book',
                'The test customer could not book: '.mb_substr(implode(' · ', $problems), 0, 400).'. Take bookings by phone/email until fixed.',
                'critical');
        }

        return self::FAILURE;
    }

    /** @return list<string> problems found (empty = all good) */
    public function check(): array
    {
        $exec = VehicleType::where('slug', 'executive')->first();
        if (! $exec) {
            return ['No Executive vehicle type found'];
        }
        $problems = [];
        $realAlerts = app(AdminAlerts::class);
        $mailer = Mail::getFacadeRoot();
        $day = Carbon::now(config('app.timezone'))->addDays(21);
        $tests = [
            'one-way MAN → JELD-WEN (VAT)' => [
                'journey_type' => 'one_way',
                'pickup_address' => 'Manchester Airport T2, Manchester M90 1QX, UK', 'pickup_postcode' => 'M90 1QX',
                'destination_address' => 'JELD-WEN UK, Shepcote Lane, Sheffield S9 1DZ, UK', 'destination_postcode' => 'S9 1DZ',
                'pickup_at' => $day->copy()->setTime(11, 10)->format('Y-m-d\TH:i'),
                'flight_number' => 'LH940', 'flight_landing_at' => $day->copy()->setTime(11, 10)->format('Y-m-d\TH:i'),
                'vat_invoice' => '1', 'business_name' => 'Health Check Ltd', 'business_address' => '1 Test Street, Sheffield S1 2HH', 'business_vat_number' => 'GB000000000',
                'payment_method' => 'card',
            ],
            'return Sheffield ⇄ MAN (card)' => [
                'journey_type' => 'return',
                'pickup_address' => 'Sheffield S1 2HH, UK', 'pickup_postcode' => 'S1 2HH',
                'destination_address' => 'Manchester Airport T2, Manchester M90 1QX, UK', 'destination_postcode' => 'M90 1QX',
                'pickup_at' => $day->copy()->setTime(6, 0)->format('Y-m-d\TH:i'),
                'return_pickup_at' => $day->copy()->addDays(3)->setTime(18, 30)->format('Y-m-d\TH:i'),
                'flight_number' => 'BA1234', 'flight_landing_at' => $day->copy()->addDays(3)->setTime(18, 30)->format('Y-m-d\TH:i'),
                'payment_method' => 'card',
            ],
        ];

        foreach ($tests as $label => $fields) {
            DB::beginTransaction();
            // Switch off every way the run could reach a person.
            Mail::fake();
            app()->instance(AdminAlerts::class, new class(app(\App\Services\Push\WebPushService::class)) extends AdminAlerts
            {
                public function notify(string $prefType, string $title, string $body, string $severity = 'info', ?Booking $booking = null): void {}

                public function notifyDirectors(string $prefType, string $title, string $body, string $severity = 'info', ?Booking $booking = null, ?string $fragment = null): void {}
            });
            try {
                $before = Booking::max('id') ?? 0;
                $controller = app(BookingWidgetController::class);

                // 1) Prices — must show a real Executive price, not "On request".
                $priceReq = $this->request('/widget/book/prices', [
                    'pickup' => $fields['pickup_address'], 'destination' => $fields['destination_address'],
                    'pickup_at' => $fields['pickup_at'], 'journey_type' => $fields['journey_type'],
                    'return_pickup_at' => $fields['return_pickup_at'] ?? null,
                ]);
                $options = collect(($controller->prices($priceReq)->getData(true))['options'] ?? []);
                $execOpt = $options->firstWhere('id', $exec->id);
                if (! $execOpt || ! empty($execOpt['poa'])) {
                    $problems[] = "{$label}: Executive shows \"On request\" instead of a price";
                }

                // 2) Book it — exactly like a customer pressing Book.
                $resp = $controller->store($this->request('/widget/book', $fields + [
                    'vehicle_type_id' => $exec->id, 'passengers' => 1, 'suitcases' => 1,
                    'customer_first_name' => 'Health', 'customer_surname' => 'Check',
                    'customer_phone' => '07700900000', 'customer_email' => 'health-check@example.com',
                    'accept_terms' => '1', 'accept_privacy' => '1',
                    'submission_token' => 'health-'.uniqid('', true),
                ]));
                app(\App\Support\AfterResponse::class)->drain(); // its emails go to the fake mailer

                $made = Booking::where('id', '>', $before)->orderBy('id')->get();
                $outbound = $made->firstWhere('is_return_leg', false);
                if ($resp->getStatusCode() !== 200) {
                    $problems[] = "{$label}: the form answered with error {$resp->getStatusCode()}";
                }
                if (! $outbound) {
                    $problems[] = "{$label}: pressing Book saved NO booking";
                } else {
                    if ($fields['journey_type'] === 'return' && $made->where('is_return_leg', true)->isEmpty()) {
                        $problems[] = "{$label}: the return leg was not saved";
                    }
                    if ((float) ($outbound->quoted_price ?? 0) <= 0 && empty($outbound->meta['quote_request'])) {
                        $problems[] = "{$label}: booking saved with no price";
                    }
                    if (! empty($outbound->meta['quote_request'])) {
                        $problems[] = "{$label}: became a quote request instead of a priced booking";
                    }
                    if (! BookingAttempt::where('booking_id', $outbound->id)->exists()) {
                        $problems[] = "{$label}: the booking log did not record it";
                    }
                }
            } catch (\Illuminate\Validation\ValidationException $e) {
                $problems[] = "{$label}: refused — ".mb_substr(collect($e->errors())->flatten()->implode(' '), 0, 200);
            } catch (\Throwable $e) {
                $problems[] = "{$label}: crashed — ".mb_substr($e->getMessage(), 0, 200);
            } finally {
                DB::rollBack(); // undo EVERYTHING: bookings, customer, log rows, alerts feed
                app(\App\Support\AfterResponse::class)->drain();
                Mail::swap($mailer);
                app()->instance(AdminAlerts::class, $realAlerts);
            }
        }

        return $problems;
    }

    private function request(string $uri, array $data): Request
    {
        $r = Request::create($uri, 'POST', array_filter($data, fn ($v) => $v !== null));
        $r->setLaravelSession(app('session')->driver('array'));
        app()->instance('request', $r);

        return $r;
    }
}
