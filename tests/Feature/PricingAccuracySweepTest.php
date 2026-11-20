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
        'Chesterfield S40' => 13, 'Dinnington' => 13, 'Kiveton' => 11, 'Worksop S80' => 20, 'Doncaster DN1' => 20, 'Bawtry DN10' => 25, 'DN7 6AA' => 25, 'DN8 5AA' => 27, 'S62 6AA' => 9, 'S66 8AA' => 10, 'S72 8AA' => 17, 'S75 5AA' => 16, 'S73 0AA' => 15, 'S71 4AA' => 17,
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
        'Rotherham S60 1AA' => 105.0, 'Barnsley S70 1AA' => 105.0, 'Chesterfield S40 1AA' => 105.0,
        'Meadowhall Centre' => 105.0,
    ];

    /** Towns on the mileage guide, never a fixed fare (directors, 11 Nov 2026; Doncaster back on fixed 20 Nov). */
    private const MILEAGE_TOWNS = [
        'Worksop S80 1AA', 'Mansfield NG18 1AA',
        'Matlock DE4 3AA', 'Bakewell DE45 1AA',
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

    public function test_worksop_mansfield_matlock_and_bakewell_are_priced_by_mileage(): void
    {
        $quotes = app(QuoteService::class);
        foreach (self::MILEAGE_TOWNS as $address) {
            foreach (['Manchester Airport', 'Heathrow Airport', 'Humberside Airport', 'Newcastle Airport'] as $airport) {
                $q = $quotes->quote($address, $airport, $this->exec());
                $this->assertFalse($q['fixed'], "{$address} → {$airport} must be mileage-priced");
                $this->assertNotNull($q['price'], "{$address} → {$airport} should still be priced");
            }
        }
    }

    public function test_a_job_just_outside_the_radius_only_pays_drag_for_the_extra_miles(): void
    {
        // Worksop (20 mi) → Heathrow (170 mi trip): £350 mileage + £5 drag (5 mi × £1 in the
        // £1 band, 20 Nov 2026) = £355, not £515.
        $this->stubDistances(170);
        $q = app(QuoteService::class)->quote('Worksop S80 1AA', 'Heathrow Airport', $this->exec());
        $this->assertFalse($q['fixed']);
        $this->assertEqualsWithDelta(5.0, (float) $q['dead_mileage'], 0.01);
        $this->assertEqualsWithDelta(355.0, (float) $q['price'], 0.01);
        // Same both ways.
        $back = app(QuoteService::class)->quote('Heathrow Airport', 'Worksop S80 1AA', $this->exec());
        $this->assertEqualsWithDelta(355.0, (float) $back['price'], 0.01);
    }

    public function test_an_address_with_no_postcode_never_gets_a_blind_sheffield_fare(): void
    {
        // "Huddersfield" (no postcode, ~27 mi) used to fall to the Sheffield default: £290 to Heathrow.
        $this->stubDistances(180);
        $q = app(QuoteService::class)->quote('Huddersfield', 'Heathrow Airport', $this->exec());
        $this->assertFalse($q['fixed']);
        $this->assertGreaterThan(350, (float) $q['price']); // mileage + drag, never the £290 Sheffield fare
    }

    public function test_s25_and_s26_pay_more_to_manchester_airport_only(): void
    {
        $quotes = app(QuoteService::class);
        foreach (['Dinnington, Sheffield S25 2AA', 'Kiveton Park, Sheffield S26 5AA'] as $address) {
            $this->assertEqualsWithDelta(125.0, $quotes->quote($address, 'Manchester Airport', $this->exec())['price'], 0.01, $address);
            $this->assertEqualsWithDelta(180.0, $quotes->quote($address, 'Manchester Airport', $this->vehicle('minibus-8-xl'))['price'], 0.01, $address);
            $this->assertEqualsWithDelta(190.0, $quotes->quote($address, 'Manchester Airport', $this->vehicle('v-class'))['price'], 0.01, $address);
            $this->assertEqualsWithDelta(165.0, $quotes->quote($address, 'Manchester Airport', $this->vehicle('minibus-8'))['price'], 0.01, $address);
            // Manchester only — every other route keeps the normal fare.
            $this->assertEqualsWithDelta(290.0, $quotes->quote($address, 'Heathrow Airport', $this->exec())['price'], 0.01, $address);
            $this->assertEqualsWithDelta(105.0, $quotes->quote($address, 'Leeds Bradford Airport', $this->exec())['price'], 0.01, $address);
            // Same on the way back.
            $this->assertEqualsWithDelta(125.0, $quotes->quote('Manchester Airport', $address, $this->exec())['price'], 0.01, $address);
        }
        // Neighbouring Sheffield districts are unchanged.
        $this->assertEqualsWithDelta(105.0, $quotes->quote('Mosborough, Sheffield S20 5AA', 'Manchester Airport', $this->exec())['price'], 0.01);
        $this->assertEqualsWithDelta(105.0, $quotes->quote('Sheffield S1 2HH', 'Manchester Airport', $this->exec())['price'], 0.01);
    }

    private function vehicle(string $slug): \App\Models\VehicleType
    {
        return \App\Models\VehicleType::where('slug', $slug)->firstOrFail();
    }

    public function test_oban_is_not_glasgow_airport(): void
    {
        $q = app(QuoteService::class)->quote('Sheffield S1 2HH', 'Oban PA34 4AA', $this->exec());
        $this->assertFalse($q['fixed'], 'PA34 (Oban) must not match Glasgow Airport PA3');
        $this->assertTrue(app(QuoteService::class)->quote('Sheffield S1 2HH', 'Glasgow Airport PA3 2SW', $this->exec())['fixed']);
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
            // Both ends away: drag to the FAR end (same price both ways) — the far address or
            // the airport (stubbed at 40 mi). But when the nearer end is within 35 mi
            // (Huddersfield 27), only those extra miles are dragged (directors, 11 Nov 2026).
            // Every mile, £1, to £5.
            $near = min($this->miles($address), 40.0);
            $miles = $near <= 35 ? $near : max($this->miles($address), 40.0);
            $this->assertEqualsWithDelta($pricer->deadMileageCharge($miles), $q['dead_mileage'], 0.01, "drag for {$address}");
            $banded = $miles <= 15 ? 0 : (min($miles, 30) - 15) + max(0, $miles - 30) * 2;
            $this->assertEqualsWithDelta($q['dead_mileage'], round($banded / 5) * 5, 0.01, "{$address}: drag in bands over {$miles} mi");
            $this->assertEqualsWithDelta($pricer->price('executive', 90.0) + $q['dead_mileage'], $q['price'], 0.01, "total for {$address}");
        }
    }

    public function test_the_drag_rule_boundaries(): void
    {
        $p = app(FreeRoamPricer::class);
        $this->assertSame(0.0, $p->deadMileageCharge(15.0), 'inside the 15-mile radius: no drag');
        $this->assertSame(0.0, $p->deadMileageCharge(15.1), 'just past: only the extra 0.1 mi (rounds to £0)');
        $this->assertSame(15.0, $p->deadMileageCharge(30.0), 'miles 15–30 at £1');
        $this->assertSame(35.0, $p->deadMileageCharge(40.0), '15 × £1 + 10 × £2');
        $this->assertSame(185.0, $p->deadMileageCharge(113.9), 'the Kidderminster run: 15 + 83.9 × 2 → £185');
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
            $this->assertEqualsWithDelta(95.0, $q['dead_mileage'], 0.01, "{$vt->name}: 70 mi drag in bands (15 + 40×2)");
        }
    }

    public function test_the_real_kidderminster_job_is_a_quote_request_on_the_website_with_a_400_guide(): void
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
        // Far from home at BOTH ends (113.9 mi drag ≥ 40): the website takes it as a quote
        // request — no price, no payment — and keeps £215 + £185 = £400 as the office's guide
        // (directors, 20 Nov 2026).
        $this->assertNull($booking->quoted_price);
        $this->assertTrue((bool) $booking->meta['quote_request']);
        $this->assertEqualsWithDelta(400.0, (float) $booking->meta['office_quote_guide'], 0.01);

        // The website's price cards say "On request"; the admin form still prices it at £400.
        $opts = $this->postJson(route('widget.prices'), [
            'pickup' => 'Avensys Training Academy, Frederick Road, Kidderminster, DY11 7RA',
            'destination' => 'Premier Inn Manchester Airport, M90 5DL',
        ])->json('options');
        $exec = collect($opts)->firstWhere('id', $this->exec()->id);
        $this->assertTrue($exec['poa']);
        $this->assertSame('On request', $exec['formatted']);
        $q = app(QuoteService::class)->quote('Avensys Training Academy, Frederick Road, Kidderminster, DY11 7RA', 'Premier Inn Manchester Airport, M90 5DL', $this->exec());
        $this->assertTrue($q['office_quote']);
        $this->assertEqualsWithDelta(400.0, (float) $q['price'], 0.01);
    }

    public function test_a_job_with_less_than_40_miles_of_drag_is_still_priced_and_paid_online(): void
    {
        // Worksop → Heathrow: near-end drag only (≈20 mi) — priced as normal.
        $q = app(QuoteService::class)->quote('Worksop S80 1AA', 'Heathrow Airport', $this->exec());
        $this->assertFalse($q['office_quote']);
        $this->assertNotNull($q['price']);
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

        $q = $quotes->quote('Sheffield S1 2HH', 'Cardiff CF10 1AA', $this->exec());
        $this->assertFalse($q['fixed']);
        $this->assertSame(0.0, (float) ($q['dead_mileage'] ?? 0), 'Sheffield → Cardiff: no out-of-area charge');
        $this->assertStringNotContainsString('out-of-area', $q['basis']);

        $back = $quotes->quote('Cardiff CF10 1AA', 'Sheffield S1 2HH', $this->exec());
        $this->assertSame(0.0, (float) ($back['dead_mileage'] ?? 0), 'Cardiff → Sheffield: no out-of-area charge');
        $this->assertEqualsWithDelta($q['price'], $back['price'], 0.01, 'same price both ways');

        $away = $quotes->quote('Avensys Training Academy, Frederick Road, Kidderminster, UK, DY11 7RA', 'Manchester Airport', $this->exec());
        $this->assertGreaterThan(0, (float) ($away['dead_mileage'] ?? 0), 'Kidderminster → MAN keeps its drag');
    }

    /** All of London = ETO Central London fare + £20 built in: V-Class £470, exec £320 (final, 11 Nov 2026). */
    public function test_anywhere_in_london_gets_the_london_fixed_fare(): void
    {
        $this->stubDistances(tripMiles: 165.0);
        $quotes = app(QuoteService::class);
        $v = VehicleType::where('slug', 'v-class')->firstOrFail();

        foreach ([
            'Westminster, London SW1A 1AA' => 470.0, 'Oxford Street, London W1D 1AA' => 470.0,
            'Canary Wharf, London E14 5AB' => 470.0, 'Wembley Stadium, London HA9 0WS' => 470.0,
            'Croydon CR0 1AA' => 470.0, 'Richmond, London TW9 1AA' => 470.0, 'Stratford, London E20 1EJ' => 470.0,
            'Tottenham, London N17 0AP' => 470.0,
        ] as $london => $fare) {
            foreach ([['Sheffield S1 2HH', $london], [$london, 'Sheffield S1 2HH']] as [$a, $b]) {
                $q = $quotes->quote($a, $b, $v);
                $this->assertTrue($q['fixed'], "{$a} → {$b} should be a London fixed fare");
                $this->assertEqualsWithDelta($fare, $q['price'], 0.01, "{$a} → {$b} V-Class");
            }
        }
        $this->assertEqualsWithDelta(320.0, $quotes->quote('Sheffield S1 2HH', 'Canary Wharf, London E14 5AB', $this->exec())['price'], 0.01, 'Executive £300 + £20');
        $core = $quotes->quote('Sheffield S1 2HH', 'Westminster, London SW1A 1AA', $this->exec());
        $this->assertEqualsWithDelta(320.0, $core['price'], 0.01, 'core central: £20 built in');
        $this->assertSame('Fixed price', $core['basis'], 'the £20 is never itemised');

        // Heathrow keeps its own fare; a Sheffield "London Road" is not London; a Surrey KT address isn't either.
        $this->assertEqualsWithDelta(290.0, $quotes->quote('Sheffield S1 2HH', 'Heathrow Airport TW6 1QG', $this->exec())['price'], 0.01);
        $this->assertNotSame(450.0, (float) $quotes->quote('Sheffield S1 2HH', 'London Road, Sheffield S2 4LA', $v)['price']);
        $this->assertFalse($quotes->quote('Sheffield S1 2HH', 'Kingston upon Thames, Surrey KT1 1AA', $v)['fixed']);
    }

    public function test_doncaster_dn1_to_dn12_is_back_on_fixed_fares(): void
    {
        // Directors, 20 Nov 2026: "Doncaster fixed pricing is right. Activate it".
        $quotes = app(QuoteService::class);
        foreach (['Doncaster DN1 1AA', 'Bawtry DN10 6AA'] as $address) {
            $q = $quotes->quote($address, 'Heathrow Airport', $this->exec());
            $this->assertTrue($q['fixed'], "{$address} → Heathrow should be fixed");
            $this->assertEqualsWithDelta(320.0, (float) $q['price'], 0.01);
            $this->assertEqualsWithDelta(140.0, (float) $quotes->quote($address, 'Manchester Airport', $this->exec())['price'], 0.01);
        }
        // Grimsby/Scunthorpe/Goole (DN14+) stay out of area.
        $this->assertFalse($quotes->quote('Grimsby DN32 9AA', 'Heathrow Airport', $this->exec())['fixed']);
    }

    public function test_liverpool_airport_fares_and_the_deep_zones(): void
    {
        // Directors, 20 Nov 2026 — Executive to Liverpool John Lennon.
        $quotes = app(QuoteService::class);
        $expect = [
            'Sheffield S1 2HH' => 170.0,
            'Mosborough, Sheffield S20 5AA' => 175.0,
            'Rotherham S60 1AA' => 170.0,
            'Rawmarsh, Rotherham S62 6AA' => 175.0,   // Deep Rotherham
            'Maltby, Rotherham S66 8AA' => 175.0,     // Deep Rotherham
            'Barnsley S70 1AA' => 170.0,
            'Cudworth, Barnsley S72 8AA' => 175.0,    // Deep Barnsley
            'Darton, Barnsley S75 5AA' => 175.0,      // Deep Barnsley
            'Wombwell, Barnsley S73 0AA' => 170.0,
            'Royston, Barnsley S71 4AA' => 175.0,     // Deep Barnsley
        ];
        foreach ($expect as $address => $fare) {
            $q = $quotes->quote($address, 'Liverpool John Lennon Airport', $this->exec());
            $this->assertTrue($q['fixed'], "{$address} → Liverpool should be fixed");
            $this->assertEqualsWithDelta($fare, (float) $q['price'], 0.01, "{$address} → Liverpool");
        }
        // Same fare from the airport back home; the deep zones keep the base town's fare elsewhere.
        $this->assertEqualsWithDelta(175.0, (float) $quotes->quote('Liverpool John Lennon Airport', 'Maltby, Rotherham S66 8AA', $this->exec())['price'], 0.01);
        $this->assertEqualsWithDelta(105.0, (float) $quotes->quote('Maltby, Rotherham S66 8AA', 'Manchester Airport', $this->exec())['price'], 0.01);
        $this->assertEqualsWithDelta(105.0, (float) $quotes->quote('Royston, Barnsley S71 4AA', 'Manchester Airport', $this->exec())['price'], 0.01);
        // Bigger vehicles went up by the same £ as Executive (Sheffield V-Class £215 → £235).
        $v = VehicleType::where('slug', 'v-class')->firstOrFail();
        $this->assertEqualsWithDelta(235.0, (float) $quotes->quote('Sheffield S1 2HH', 'Liverpool John Lennon Airport', $v)['price'], 0.01);
    }

    public function test_deep_doncaster_is_the_doncaster_fare_plus_ten(): void
    {
        // Directors, 20 Nov 2026: DN7 (Hatfield) is too far for the town fare.
        $quotes = app(QuoteService::class);
        foreach (['Hatfield, Doncaster DN7 6AA', 'Thorne, Doncaster DN8 5AA'] as $address) {
            $this->assertEqualsWithDelta(330.0, (float) $quotes->quote($address, 'Heathrow Airport', $this->exec())['price'], 0.01, $address);
            $this->assertEqualsWithDelta(150.0, (float) $quotes->quote($address, 'Manchester Airport', $this->exec())['price'], 0.01, $address);
            $this->assertEqualsWithDelta(150.0, (float) $quotes->quote('Manchester Airport', $address, $this->exec())['price'], 0.01, $address);
        }
        $v = VehicleType::where('slug', 'v-class')->firstOrFail();
        $this->assertEqualsWithDelta(460.0, (float) $quotes->quote('Hatfield, Doncaster DN7 6AA', 'Heathrow Airport', $v)['price'], 0.01);
        // Town Doncaster unchanged.
        $this->assertEqualsWithDelta(320.0, (float) $quotes->quote('Doncaster DN1 1AA', 'Heathrow Airport', $this->exec())['price'], 0.01);
    }
}
