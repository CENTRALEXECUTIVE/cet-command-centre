<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\VehicleType;
use App\Services\Pricing\DistanceService;
use App\Services\Pricing\FreeRoamPricer;
use App\Services\Pricing\QuoteService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * PRICING ACCURACY SWEEP — guards against the class of misquote that cost us a job:
 * a far-away address matched to a LOCAL zone and handed the cheap fixed airport fare
 * (which skips the out-of-area "drag" charge). Kidderminster (DY11) → Manchester
 * Airport was quoted the Sheffield £105 instead of ~£330; every DN postcode (Grimsby,
 * Scunthorpe, Goole…) got the Doncaster fare; "Sheffield Road, Birmingham" got the
 * Sheffield fare.
 *
 * Rule (confirmed by the directors): inside the free radius no drag; beyond it EVERY
 * mile from base to the pickup is charged at the per-mile rate (£1), rounded to £5.
 *
 * Distances are stubbed with realistic road miles from Sheffield, so this runs offline.
 */
class PricingAccuracySweepTest extends TestCase
{
    use RefreshDatabase;

    /** Approx. road miles from the Sheffield base, keyed by a needle in the address. */
    private const BASE_MILES = [
        // ---- home catchment (local fixed fares) ----
        'Sheffield S1' => 1, 'Mosborough' => 8, 'Rotherham S60' => 7, 'Barnsley S70' => 15,
        'Chesterfield S40' => 13, 'Worksop S80' => 20, 'Doncaster DN1' => 20, 'Bawtry DN10' => 25,
        'Mansfield NG18' => 26, 'Matlock DE4' => 24, 'Meadowhall' => 4,
        // ---- out of area ----
        'Kidderminster' => 114, 'Grimsby' => 70, 'Scunthorpe' => 45, 'Goole' => 38,
        'Gainsborough' => 36, 'Immingham' => 65, 'Nottingham' => 40, 'Leeds LS1' => 38,
        'York' => 55, 'Derby DE1' => 37, 'Lincoln' => 48, 'Hull' => 65, 'Huddersfield' => 27,
        'Birmingham' => 85, 'Rotherham Road, Leeds' => 38, 'Avensys' => 114,
    ];

    /** Executive fixed fare to Manchester Airport for each home-catchment address. */
    private const LOCAL_TO_MANCHESTER = [
        'Sheffield S1 2HH' => 105.0, 'Mosborough, Sheffield S20 5AA' => 105.0,
        'Rotherham S60 1AA' => 105.0, 'Barnsley S70 1AA' => 105.0, 'Chesterfield S40 1AA' => 110.0,
        'Doncaster DN1 1AA' => 140.0, 'Bawtry DN10 6AA' => 140.0, 'Worksop S80 1AA' => 125.0,
        'Mansfield NG18 1AA' => 150.0, 'Matlock DE4 3AA' => 150.0, 'Meadowhall Centre' => 105.0,
    ];

    /** Far addresses that must NEVER get a local fixed fare. */
    private const OUT_OF_AREA = [
        'Avensys Training Academy, Frederick Road, Kidderminster, UK, DY11 7RA',
        'Grimsby DN32 9AA', 'Scunthorpe DN15 6AA', 'Goole DN14 5AA', 'Gainsborough DN21 1AA',
        'Immingham DN40 1AA', 'Nottingham NG1 1AA', 'Leeds LS1 1AA', 'York YO1 7AA',
        'Derby DE1 1AA', 'Lincoln LN1 1AA', 'Hull HU1 1AA', 'Huddersfield HD1 1AA',
        // no postcode / misleading text
        'Sheffield Road, Birmingham', 'Rotherham Road, Leeds',
        'Flat S3, High Street, Kidderminster', 'Avensys Training Academy, Frederick Road',
        // lower-case, no space
        'avensys training academy, kidderminster dy117ra',
    ];

    private const AIRPORTS = [
        'Manchester Airport', 'Heathrow Airport', 'East Midlands Airport',
        'Leeds Bradford Airport', 'Birmingham Airport', 'Gatwick Airport',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
        $this->stubDistances(tripMiles: 90.0);
    }

    private function stubDistances(float $tripMiles): void
    {
        $base = (string) config('cet.base.address');
        $distance = \Mockery::mock(DistanceService::class);
        $distance->shouldReceive('resolve')->andReturnUsing(function (string $from, string $to) use ($base, $tripMiles) {
            if ($from === $base) {
                foreach (self::BASE_MILES as $needle => $miles) {
                    if (stripos($to, $needle) !== false) {
                        return ['miles' => (float) $miles, 'minutes' => 60, 'source' => 'google'];
                    }
                }
                // Airports used as a pickup, and anything unlisted, count as nearby.
                return ['miles' => 40.0, 'minutes' => 60, 'source' => 'google'];
            }

            return ['miles' => $tripMiles, 'minutes' => 120, 'source' => 'google'];
        });
        $this->app->instance(DistanceService::class, $distance);
    }

    private function miles(string $address): float
    {
        foreach (self::BASE_MILES as $needle => $miles) {
            if (stripos($address, $needle) !== false) {
                return (float) $miles;
            }
        }
        $this->fail("No stub distance for: {$address}");
    }

    private function exec(): VehicleType
    {
        return VehicleType::where('slug', 'executive')->firstOrFail();
    }

    public function test_every_home_catchment_address_gets_its_exact_fixed_airport_fare(): void
    {
        $quotes = app(QuoteService::class);
        foreach (self::LOCAL_TO_MANCHESTER as $address => $fare) {
            $q = $quotes->quote($address, 'Manchester Airport', $this->exec());
            $this->assertTrue($q['fixed'], "{$address} → Manchester Airport should be a FIXED fare");
            $this->assertEqualsWithDelta($fare, $q['price'], 0.01, "{$address} → Manchester Airport fare");
        }
    }

    public function test_no_out_of_area_address_ever_gets_a_local_fixed_fare_to_any_airport(): void
    {
        $quotes = app(QuoteService::class);
        foreach (self::OUT_OF_AREA as $address) {
            foreach (self::AIRPORTS as $airport) {
                $q = $quotes->quote($address, $airport, $this->exec());
                $this->assertFalse($q['fixed'], "MISQUOTE: {$address} → {$airport} got a local fixed fare (£{$q['price']})");
                $this->assertStringContainsString('out-of-area', $q['basis'], "{$address} → {$airport} must show the out-of-area charge");
            }
        }
    }

    public function test_a_far_journey_costs_the_same_in_both_directions(): void
    {
        $quotes = app(QuoteService::class);
        foreach (self::OUT_OF_AREA as $address) {
            $there = $quotes->quote($address, 'Manchester Airport', $this->exec());
            $back = $quotes->quote('Manchester Airport', $address, $this->exec());
            $this->assertEqualsWithDelta($there['price'], $back['price'], 0.01, "{$address}: there £{$there['price']} vs back £{$back['price']}");
        }
    }

    public function test_arrivals_are_priced_the_same_way_as_departures(): void
    {
        $quotes = app(QuoteService::class);
        foreach (self::OUT_OF_AREA as $address) {
            $q = $quotes->quote('Manchester Airport', $address, $this->exec());
            $this->assertFalse($q['fixed'], "MISQUOTE: Manchester Airport → {$address} got a local fixed fare");
        }
        foreach (self::LOCAL_TO_MANCHESTER as $address => $fare) {
            $q = $quotes->quote('Manchester Airport', $address, $this->exec());
            $this->assertTrue($q['fixed'], "Manchester Airport → {$address} should be FIXED");
            $this->assertEqualsWithDelta($fare, $q['price'], 0.01, "Manchester Airport → {$address} fare");
        }
    }

    public function test_the_drag_charges_every_mile_from_base_to_a_far_pickup(): void
    {
        $quotes = app(QuoteService::class);
        $pricer = app(FreeRoamPricer::class);
        foreach (self::OUT_OF_AREA as $address) {
            $q = $quotes->quote($address, 'Manchester Airport', $this->exec());
            // Drag to the FAR end of the journey (same price both ways): the far address or
            // the airport (stubbed at 40 mi), whichever is further. Every mile, £1, to £5.
            $miles = max($this->miles($address), 40.0);
            $this->assertEqualsWithDelta($pricer->deadMileageCharge($miles), $q['dead_mileage'], 0.01, "drag for {$address}");
            $this->assertEqualsWithDelta($q['dead_mileage'], round($miles / 5) * 5, 0.01, "{$address}: drag must be the WHOLE {$miles} mi");
            $this->assertEqualsWithDelta($pricer->price('executive', 90.0) + $q['dead_mileage'], $q['price'], 0.01, "total for {$address}");
        }
    }

    public function test_the_drag_rule_boundaries(): void
    {
        $p = app(FreeRoamPricer::class);
        $this->assertSame(0.0, $p->deadMileageCharge(15.0), 'inside the 15-mile radius: no drag');
        $this->assertSame(15.0, $p->deadMileageCharge(15.1), 'just past: the WHOLE distance is charged');
        $this->assertSame(40.0, $p->deadMileageCharge(40.0));
        $this->assertSame(115.0, $p->deadMileageCharge(113.9), 'the Kidderminster run: 113.9 mi → £115');
        $this->assertSame(0.0, $p->deadMileageCharge(null), 'unknown distance: no drag, never an error');
    }

    public function test_every_priced_vehicle_charges_the_drag_on_an_out_of_area_job(): void
    {
        $quotes = app(QuoteService::class);
        foreach (VehicleType::where('is_active', true)->get() as $vt) {
            $q = $quotes->quote('Grimsby DN32 9AA', 'Manchester Airport', $vt);
            if ($q['price'] === null) {
                continue; // price on request (e.g. Rolls Royce) — the office quotes it
            }
            $this->assertFalse($q['fixed'], "{$vt->name}: Grimsby → Manchester must not be a fixed fare");
            $this->assertEqualsWithDelta(70.0, $q['dead_mileage'], 0.01, "{$vt->name}: 70 mi drag");
        }
    }

    public function test_the_real_kidderminster_job_prices_330_end_to_end_on_the_customer_booking(): void
    {
        Mail::fake();
        $this->stubDistances(tripMiles: 91.7);
        // Use the staging-measured 113.9 mi for this one.
        $base = (string) config('cet.base.address');
        $distance = \Mockery::mock(DistanceService::class);
        $distance->shouldReceive('resolve')->andReturnUsing(fn (string $from, string $to) => [
            'miles' => $from === $base ? 113.9 : 91.7, 'minutes' => 120, 'source' => 'google',
        ]);
        $this->app->instance(DistanceService::class, $distance);

        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Avensys Training Academy, Frederick Road, Kidderminster, UK',
            'pickup_postcode' => 'DY11 7RA',
            'destination_address' => 'Premier Inn Manchester Airport (M56/J6) Runger Lane South hotel, Wilmslow Road, Manchester, UK, M90 5DL',
            'pickup_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $this->exec()->id,
            'passengers' => 1, 'hand_luggage' => 1,
            'customer_name' => 'Dane Limosnero', 'customer_phone' => '07624460475',
            'payment_method' => 'card',
        ])->assertOk();

        $booking = Booking::firstWhere('source', 'web');
        // £215 trip + £115 drag — what the customer is actually asked to pay.
        $this->assertEqualsWithDelta(330.0, (float) $booking->quoted_price, 0.01);
    }

    public function test_quoting_never_breaks_when_maps_is_down(): void
    {
        $this->mapsDown();
        $quotes = app(QuoteService::class);

        // Local fixed fares (full in-area postcode) still work off the zone rules alone.
        $q = $quotes->quote('Sheffield S1 2HH', 'Manchester Airport', $this->exec());
        $this->assertTrue($q['fixed']);
        $this->assertEqualsWithDelta(105.0, $q['price'], 0.01);

        // Anything that needs a distance is NEVER guessed — it goes to the office.
        foreach ([
            ['Grimsby DN32 9AA', 'Manchester Airport'],            // far postcode → needs distance
            ['Sheffield Road, Birmingham', 'Manchester Airport'],  // no postcode → zone is a guess
            ['Meadowhall Centre', 'Manchester Airport'],           // no postcode → can't confirm it's local
            ['Sheffield S1 2HH', 'Leeds LS1 1AA'],                 // ordinary free-roam journey
        ] as [$from, $to]) {
            $r = $quotes->quote($from, $to, $this->exec());
            $this->assertNull($r['price'], "{$from} → {$to}: Maps down must send it to the office, not guess £{$r['price']}");
            $this->assertTrue($r['unmeasured'] ?? false);
        }
    }

    /** Google is set up but every lookup fails — DistanceService then returns its 10-mile 'estimate'. */
    private function mapsDown(): void
    {
        $distance = \Mockery::mock(DistanceService::class);
        $distance->shouldReceive('resolve')->andReturn(['miles' => 10.0, 'minutes' => 25, 'source' => 'estimate']);
        $distance->shouldReceive('configured')->andReturn(true);
        $this->app->instance(DistanceService::class, $distance);
    }

    public function test_postcodes_that_merely_start_with_s_are_not_sheffield_even_with_maps_down(): void
    {
        $this->mapsDown(); // no distance backstop — the postcode rules alone must be right
        $quotes = app(QuoteService::class);
        foreach (['Stoke-on-Trent ST4 1AA', 'Southampton SO14 2AA', 'Swansea SA1 1AA', 'London SE1 7PB', 'Stockport SK1 1AA', 'Salisbury SP1 1AA'] as $address) {
            $q = $quotes->quote($address, 'Manchester Airport', $this->exec());
            $this->assertFalse($q['fixed'] ?? false, "MISQUOTE: {$address} got the Sheffield fixed fare (£{$q['price']})");
        }
        // Real Sheffield postcodes still do.
        foreach (['Sheffield S1 2HH', 'Sheffield S10 2TN', 'Sheffield S35 2PH'] as $address) {
            $this->assertTrue($quotes->quote($address, 'Manchester Airport', $this->exec())['fixed'], "{$address} should be fixed");
        }
    }

    public function test_a_customer_booking_with_maps_down_goes_to_the_office_not_a_guessed_price(): void
    {
        Mail::fake();
        $this->mapsDown();

        $this->post(route('widget.book.store'), [
            'pickup_address' => 'Avensys Training Academy, Frederick Road, Kidderminster, UK',
            'pickup_postcode' => 'DY11 7RA',
            'destination_address' => 'Manchester Airport',
            'pickup_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'vehicle_type_id' => $this->exec()->id,
            'passengers' => 1,
            'customer_name' => 'Far Away', 'customer_phone' => '07700900777', 'customer_email' => 'far@example.com',
        ])->assertOk()->assertSee('Quote request received');

        $booking = Booking::firstWhere('source', 'web');
        $this->assertNull($booking->quoted_price, 'no guessed price is stored or charged');
        $this->assertTrue((bool) ($booking->meta['quote_request'] ?? false), 'the office is asked to price it');
    }

    public function test_without_any_maps_key_dev_quoting_still_estimates(): void
    {
        // No Maps key at all (local dev / tests): nothing to "fail", keep the estimate.
        $distance = \Mockery::mock(DistanceService::class);
        $distance->shouldReceive('resolve')->andReturn(['miles' => 10.0, 'minutes' => 25, 'source' => 'estimate']);
        $distance->shouldReceive('configured')->andReturn(false);
        $this->app->instance(DistanceService::class, $distance);

        $q = app(QuoteService::class)->quote('Sheffield S1 2HH', 'Leeds LS1 1AA', $this->exec());
        $this->assertNotNull($q['price']);
    }

    /**
     * Sheffield → London (outside the core Central London fixed-fare postcodes) was quoted
     * £685 V-Class = 165 mi + 166 mi "out-of-area" (directors, 11 Nov 2026). A journey that
     * starts or ends at home never carries drag; only jobs with BOTH ends away do.
     */
    public function test_a_long_run_from_home_carries_no_drag_but_away_to_away_still_does(): void
    {
        $this->stubDistances(tripMiles: 165.0);
        $quotes = app(QuoteService::class);

        $q = $quotes->quote('Sheffield S1 2HH', 'Canary Wharf, London E14 5AB', $this->exec());
        $this->assertFalse($q['fixed']);
        $this->assertSame(0.0, (float) ($q['dead_mileage'] ?? 0), 'Sheffield → London: no out-of-area charge');
        $this->assertStringNotContainsString('out-of-area', $q['basis']);

        $back = $quotes->quote('Canary Wharf, London E14 5AB', 'Sheffield S1 2HH', $this->exec());
        $this->assertSame(0.0, (float) ($back['dead_mileage'] ?? 0), 'London → Sheffield: no out-of-area charge');
        $this->assertEqualsWithDelta($q['price'], $back['price'], 0.01, 'same price both ways');

        $away = $quotes->quote('Avensys Training Academy, Frederick Road, Kidderminster, UK, DY11 7RA', 'Manchester Airport', $this->exec());
        $this->assertGreaterThan(0, (float) ($away['dead_mileage'] ?? 0), 'Kidderminster → MAN keeps its drag');
    }
}
