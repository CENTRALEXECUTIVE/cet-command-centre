<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use App\Models\Voucher;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\VatService;
use App\Services\Pricing\DistanceService;
use App\Services\Pricing\QuoteService;
use App\Support\AirportMatcher;
use App\Support\TimeSurcharges;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * CUSTOMER vs ADMIN PRICE PARITY SWEEP (Nov 2026, after the Kidderminster → MAN
 * misquote). For the same journey, vehicle and options it compares:
 *
 *   CUSTOMER  widget.prices `total` (the figure the widget JS shows on the card and
 *             in the Total band — the JS does no maths of its own), widget.book.store
 *             (quoted_price stored, EX-VAT, on BOTH legs) and the signed widget.pay
 *             link (the amount actually sent to Stripe/Square).
 *   ADMIN     pricing.strip (FareCalculator per leg, each leg on its own route, own
 *             drag, own pickup time), with VAT added per leg exactly as
 *             Booking::amountPayable() does; and, where it matters, bookings.store.
 *
 * Rules (directors, Nov 2026): local fixed fares only in the home catchment; else
 * distance + drag; a RETURN leg is priced on its own route with its own drag and its
 * own pickup time; extras (seats, stops, ribbons) on EACH leg; ribbons at the minibus
 * rate on minibus / V Class; a voucher comes off the WHOLE trip; the festive table
 * and the office night/date surcharges apply on both sides, on the standard fare.
 *
 * Distances are stubbed offline: straight-line × 1.25 road factor from real
 * coordinates, with the staging-measured Kidderminster figures pinned
 * (113.9 mi base→pickup, 91.7 mi pickup→Premier Inn MAN).
 */
class CustomerAdminPriceParityTest extends TestCase
{
    use RefreshDatabase;

    private const OUT_AT = '2026-11-17T15:30';

    private const RET_AT = '2026-11-20T11:00';

    private const KIDDY = 'Avensys Training Academy, Frederick Road, Kidderminster, UK, DY11 7RA';

    private const PREMIER_INN = 'Premier Inn Manchester Airport (M56/J6) Runger Lane South hotel, Wilmslow Road, Manchester, UK, M90 5DL';

    private const MAN = 'Manchester Airport, M90 1QX';

    private const LHR = 'Heathrow Airport Terminal 5, TW6 2GA';

    private const EMA = 'East Midlands Airport, DE74 2SA';

    private const LBA = 'Leeds Bradford Airport, LS19 7TU';

    private const BHX = 'Birmingham Airport, B26 3QJ';

    private const VIA = 'Via stop, Ecclesall Road, Sheffield S11 8PQ';

    /** Road miles = great-circle × this (a realistic UK road factor). */
    private const ROAD_FACTOR = 1.25;

    /** Sheffield base (config cet.base lat/lng). */
    private const BASE = [53.3811, -1.4701];

    /** address => [lat, lng] — every address the sweep uses, so no lookup is guessed. */
    private const PLACES = [
        // ---- home catchment (local fixed airport fares) ----
        'Sheffield S1 2HH' => [53.3781, -1.4697],
        'Rotherham S60 1QY' => [53.4302, -1.3568],
        'Chesterfield S40 1LA' => [53.2350, -1.4210],
        'Barnsley S70 2TA' => [53.5526, -1.4797],
        'Doncaster DN1 1QZ' => [53.5228, -1.1285],
        'Worksop S80 1AA' => [53.3010, -1.1240],
        'Mosborough, Sheffield S20 5AA' => [53.3260, -1.3640],
        // ---- near area (no fixed zone, inside/around the 35 mi backstop) ----
        'Huddersfield HD1 1JF' => [53.6458, -1.7850],
        'Leeds LS1 4DY' => [53.7960, -1.5480],
        'Nottingham NG1 5FW' => [52.9548, -1.1581],
        'Derby DE1 2FS' => [52.9225, -1.4746],
        // ---- far ----
        self::KIDDY => [52.3880, -2.2490],
        'Grimsby DN31 1AA' => [53.5675, -0.0802],
        'York YO1 7HH' => [53.9590, -1.0815],
        'Birmingham B1 1BB' => [52.4796, -1.9026],
        'Bristol BS1 4DJ' => [51.4545, -2.5879],
        'London SE1 7PB' => [51.5033, -0.1196],
        'Westminster, London SW1A 1AA' => [51.5010, -0.1416],
        // ---- airports / airport hotel ----
        self::MAN => [53.3588, -2.2727],
        self::PREMIER_INN => [53.3500, -2.2650],
        self::LHR => [51.4700, -0.4543],
        self::EMA => [52.8311, -1.3281],
        self::LBA => [53.8659, -1.6606],
        self::BHX => [52.4539, -1.7480],
    ];

    /** Measured distances that override the straight-line estimate. */
    private const BASE_OVERRIDE = [self::KIDDY => 113.9];

    private const TRIP_OVERRIDE = [self::KIDDY.'|'.self::PREMIER_INN => 91.7];

    /** One-way journeys swept (pickup, drop-off). Returns reverse each of them. */
    private const PAIRS = [
        // local catchment ↔ airports
        ['Sheffield S1 2HH', self::MAN], ['Rotherham S60 1QY', self::LBA], ['Chesterfield S40 1LA', self::EMA],
        ['Doncaster DN1 1QZ', self::LHR], ['Barnsley S70 2TA', self::BHX], ['Worksop S80 1AA', self::MAN],
        ['Mosborough, Sheffield S20 5AA', self::LBA],
        [self::MAN, 'Sheffield S1 2HH'], [self::EMA, 'Chesterfield S40 1LA'],
        // near area → airports
        ['Huddersfield HD1 1JF', self::MAN], ['Leeds LS1 4DY', self::MAN], ['Nottingham NG1 5FW', self::EMA],
        ['Derby DE1 2FS', self::BHX], ['Derby DE1 2FS', self::MAN],
        // far → airports, and arrivals to far
        [self::KIDDY, self::PREMIER_INN], ['Grimsby DN31 1AA', self::MAN], ['York YO1 7HH', self::LBA],
        ['Birmingham B1 1BB', self::MAN], ['Bristol BS1 4DJ', self::LHR], ['London SE1 7PB', self::MAN],
        [self::MAN, self::KIDDY], [self::LHR, 'York YO1 7HH'], [self::BHX, 'Grimsby DN31 1AA'],
        // free roam (no airport)
        ['Sheffield S1 2HH', 'Leeds LS1 4DY'], ['Sheffield S1 2HH', 'Nottingham NG1 5FW'],
        ['Rotherham S60 1QY', 'Derby DE1 2FS'], ['Leeds LS1 4DY', 'York YO1 7HH'],
        ['Grimsby DN31 1AA', 'Birmingham B1 1BB'], ['Bristol BS1 4DJ', 'London SE1 7PB'],
        ['Sheffield S1 2HH', 'London SE1 7PB'], ['Sheffield S1 2HH', 'Westminster, London SW1A 1AA'],
        ['Huddersfield HD1 1JF', 'Sheffield S1 2HH'], [self::KIDDY, 'Sheffield S1 2HH'],
        // airport → airport
        [self::MAN, self::LHR],
    ];

    /** Representative subset for the (slower) extras / VAT sweeps. */
    private const SUBSET = [
        ['Sheffield S1 2HH', self::MAN], [self::MAN, 'Sheffield S1 2HH'], ['Leeds LS1 4DY', self::MAN],
        [self::KIDDY, self::PREMIER_INN], ['Sheffield S1 2HH', 'Leeds LS1 4DY'], ['Grimsby DN31 1AA', 'Birmingham B1 1BB'],
    ];

    /** Addresses the stub was asked about but doesn't know (must stay empty). */
    private array $unknown = [];

    /** Amounts handed to the (mocked) card provider by widget.pay. */
    private array $charged = [];

    private array $stripCache = [];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 09:00:00'));
        $this->seed(VehicleTypeSeeder::class);
        config(['cet.vat_registered' => true, 'cet.vat_rate' => 0.20]);
        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->stubDistances();
        $this->stubGateway();
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        \Mockery::close();
        parent::tearDown();
    }

    // =====================================================================
    //  Sweeps
    // =====================================================================

    public function test_parity_one_way_every_area_every_vehicle_no_extras(): void
    {
        $bad = [];
        foreach (self::PAIRS as [$p, $d]) {
            foreach ($this->vehicles() as $vt) {
                $r = $this->compare($p, $d, 'one_way', $vt);
                if (! $r['match']) {
                    $bad[] = $this->row($r);
                }
            }
        }
        $this->assertSame([], $this->unknown, 'stub was asked for an unknown address');
        $this->assertSame([], $bad, "One-way customer vs admin differ:\n".implode("\n", $bad));
    }

    public function test_parity_return_every_area_every_vehicle_each_leg_on_its_own_route(): void
    {
        // Was 105 mismatches: the widget charged 2 × the outbound. Now the return leg is
        // priced on its OWN route + OWN drag (base → the return pickup), like admin.
        $bad = [];
        $q = app(QuoteService::class);
        foreach (self::PAIRS as [$p, $d]) {
            foreach ($this->vehicles() as $vt) {
                $r = $this->compare($p, $d, 'return', $vt);
                if (! $r['match']) {
                    $bad[] = $this->row($r);

                    continue;
                }
                // And it IS the rule: the return leg = the reverse journey's own quote
                // (+ £10 meet & greet when that leg is collected at an airport).
                $rev = $q->quote($d, $p, $vt)['price'];
                if ($rev !== null) {
                    $expected = $rev + (AirportMatcher::isAirport($d) ? 10.0 : 0.0);
                    if (! $this->same($expected, $r['cust_ret'])) {
                        $bad[] = $this->row($r)." || RULE return leg £{$expected}";
                    }
                }
            }
        }
        $this->assertSame([], $this->unknown, 'stub was asked for an unknown address');
        $this->assertSame([], $bad, "Returns differ:\n".implode("\n", $bad));
    }

    public function test_parity_one_way_extras_seats_stops_ribbons_and_meet_and_greet_every_vehicle(): void
    {
        $bad = [];
        foreach (self::SUBSET as [$p, $d]) {
            foreach (['executive', 'estate', 'v-class', 'minibus-8', 'minibus-8-xl'] as $slug) {
                foreach ([
                    ['child_seats' => 1, 'booster_seats' => 1],
                    ['infant_seats' => 2],
                    ['stops' => 2],
                    ['ribbons' => true],
                    ['child_seats' => 1, 'stops' => 1, 'ribbons' => true],
                ] as $ex) {
                    $r = $this->compare($p, $d, 'one_way', $this->vt($slug), $ex);
                    if (! $r['match']) {
                        $bad[] = $this->row($r);
                    }
                }
            }
        }
        $this->assertSame([], $bad, "One-way extras differ:\n".implode("\n", $bad));
    }

    public function test_parity_return_extras_are_charged_on_each_leg(): void
    {
        // Was 21 mismatches: the widget added extras once per trip. Directors: each leg.
        $bad = [];
        foreach ([['Sheffield S1 2HH', self::MAN], [self::MAN, 'Sheffield S1 2HH'], ['Sheffield S1 2HH', 'Leeds LS1 4DY'], [self::KIDDY, self::PREMIER_INN]] as [$p, $d]) {
            foreach (['executive', 'v-class', 'minibus-8'] as $slug) {
                foreach ([['child_seats' => 1], ['booster_seats' => 1, 'infant_seats' => 1], ['stops' => 1], ['ribbons' => true]] as $ex) {
                    $r = $this->compare($p, $d, 'return', $this->vt($slug), $ex);
                    if (! $r['match']) {
                        $bad[] = $this->row($r);
                    }
                }
            }
        }
        $this->assertSame([], $bad, "Return extras differ:\n".implode("\n", $bad));
    }

    public function test_parity_ribbons_use_the_minibus_rate_on_minibus_and_v_class(): void
    {
        foreach (['executive' => 30.0, 'estate' => 30.0, 'v-class' => 50.0, 'minibus-8' => 50.0, 'minibus-8-xl' => 50.0] as $slug => $rate) {
            $plain = $this->compare('Sheffield S1 2HH', self::MAN, 'one_way', $this->vt($slug));
            $r = $this->compare('Sheffield S1 2HH', self::MAN, 'one_way', $this->vt($slug), ['ribbons' => true]);
            $this->assertTrue($r['match'], $this->row($r));
            $this->assertEqualsWithDelta($plain['cust_shown'] + $rate, $r['cust_shown'], 0.01, "{$slug} ribbons £{$rate}");
        }
    }

    public function test_parity_meet_and_greet_on_each_leg_collected_at_an_airport(): void
    {
        $exec = $this->vt('executive');
        foreach ([
            ['Sheffield S1 2HH', self::MAN, 'return', 105 + 105 + 10],
            [self::MAN, 'Sheffield S1 2HH', 'return', 105 + 10 + 105],
            [self::MAN, 'Sheffield S1 2HH', 'one_way', 105 + 10],
            ['Sheffield S1 2HH', self::MAN, 'one_way', 105],
        ] as [$p, $d, $jt, $expected]) {
            $r = $this->compare($p, $d, $jt, $exec);
            $this->assertTrue($r['match'], $this->row($r));
            $this->assertEqualsWithDelta($expected, $r['cust_pay'], 0.01, $this->row($r));
            $this->assertEqualsWithDelta($expected, $r['cust_shown'], 0.01, $this->row($r));
        }

        // Airport → airport return: both legs are airport collections → both carry it.
        $r = $this->compare(self::MAN, self::LHR, 'return', $exec);
        $this->assertTrue($r['match'], $this->row($r));
    }

    public function test_parity_vat_invoice_shown_equals_pay_link_equals_admin_and_stored_is_net(): void
    {
        $bad = [];
        foreach (self::SUBSET as [$p, $d]) {
            foreach (['executive', 'v-class'] as $slug) {
                foreach (['one_way', 'return'] as $jt) {
                    foreach ([[], ['child_seats' => 1, 'ribbons' => true]] as $ex) {
                        $r = $this->compare($p, $d, $jt, $this->vt($slug), $ex, vat: true);
                        if (! $r['match']) {
                            $bad[] = $this->row($r);
                        }
                    }
                }
            }
        }
        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_parity_vouchers_come_off_the_whole_trip(): void
    {
        Voucher::create(['code' => 'FIVER', 'type' => 'fixed', 'value' => 5, 'is_active' => true]);
        Voucher::create(['code' => 'TEN', 'type' => 'percent', 'value' => 10, 'is_active' => true]);
        $exec = $this->vt('executive');

        foreach (['FIVER', 'TEN'] as $code) {
            foreach ([['Sheffield S1 2HH', self::MAN], [self::MAN, 'Sheffield S1 2HH'], [self::KIDDY, self::PREMIER_INN], ['Sheffield S1 2HH', 'Leeds LS1 4DY']] as [$p, $d]) {
                foreach (['one_way', 'return'] as $jt) {
                    foreach ([false, true] as $vat) {
                        $label = "{$code} {$p} → {$d} {$jt}".($vat ? ' VAT' : '');
                        $cust = $this->customer($p, $d, $jt, $exec, ['child_seats' => 1], $vat, $code);
                        $adm = $this->adminStore($p, $d, $jt, $exec, ['child_seats' => 1], $vat, $code);
                        $this->assertEqualsWithDelta($adm['pays'], $cust['pay'], 0.01, "{$label}: pay link £{$cust['pay']} vs admin £{$adm['pays']}");
                        $this->assertEqualsWithDelta($adm['pays'], $cust['shown'], 0.01, "{$label}: shown £{$cust['shown']} vs admin £{$adm['pays']}");
                        $this->assertEqualsWithDelta($adm['out'], $cust['out'], 0.01, "{$label}: outbound leg");
                        if ($jt === 'return') {
                            $this->assertEqualsWithDelta($adm['ret'], $cust['ret'], 0.01, "{$label}: return leg");
                        }
                    }
                }
            }
        }
    }

    /**
     * The directors' official 2026/27 festive table, on the standard fare, the SAME on
     * the customer side (shown, stored, pay link) and the admin strip — boundaries too.
     */
    public function test_parity_official_festive_table_sheffield_to_manchester_executive(): void
    {
        $exec = $this->vt('executive');
        foreach ([
            '2027-10-31T12:00' => 170.00,  // Halloween +60% (168 → rounded up to £5)
            '2026-12-20T12:00' => 105.00,  // 18–23 Dec normal
            '2026-12-24T12:00' => 135.00,  // Christmas Eve +25% (131.25 → £135)
            '2026-12-25T12:00' => 160.00,  // Christmas Day +50% (157.50 → £160)
            '2026-12-26T12:00' => 160.00,  // Boxing Day +50%
            '2026-12-27T12:00' => 105.00,  // 27–30 Dec normal
            '2026-12-31T17:00' => 135.00,  // NYE before 18:00 +25%
            '2026-12-31T17:59' => 135.00,
            '2026-12-31T18:00' => 160.00,  // NYE 18:00–23:59 +50%
            '2026-12-31T19:00' => 160.00,
            '2027-01-01T03:00' => 190.00,  // NYD 00:00–05:59 +80% (189 → £190)
            '2027-01-01T05:59' => 190.00,
            '2027-01-01T06:00' => 160.00,  // NYD 06:00 onwards +50%
            '2027-01-01T09:00' => 160.00,
            '2027-01-02T12:00' => 105.00,  // 2 Jan onwards normal
        ] as $when => $expected) {
            $r = $this->compare('Sheffield S1 2HH', self::MAN, 'one_way', $exec, [], false, null, $when);
            $this->assertTrue($r['match'], $this->row($r));
            $this->assertEqualsWithDelta($expected, $r['admin_pays'], 0.001, "admin {$when}");
            $this->assertEqualsWithDelta($expected, $r['cust_shown'], 0.001, "customer shown {$when}");
            $this->assertEqualsWithDelta($expected, $r['cust_pay'], 0.001, "customer pay link {$when}");
        }
    }

    public function test_parity_festive_on_the_standard_fare_only_never_on_extras_every_vehicle(): void
    {
        foreach ($this->vehicles() as $vt) {
            foreach ([['Sheffield S1 2HH', self::MAN], [self::KIDDY, self::PREMIER_INN], ['Sheffield S1 2HH', 'Leeds LS1 4DY']] as [$p, $d]) {
                $r = $this->compare($p, $d, 'one_way', $vt, ['child_seats' => 1, 'ribbons' => true], false, null, '2026-12-25T12:00');
                $this->assertTrue($r['match'], $this->row($r));
                if ($r['admin_pays'] === null) {
                    continue; // price on request
                }
                $fare = app(QuoteService::class)->quote($p, $d, $vt)['price'];
                $ribbon = in_array($vt->slug, ['v-class', 'minibus-8', 'minibus-8-xl'], true) ? 50.0 : 30.0;
                $this->assertEqualsWithDelta(ceil(round($fare * 1.5, 2) / 5) * 5 + 10 + $ribbon, $r['cust_pay'], 0.01, "{$vt->name} {$p}: +50% on the fare (rounded up to £5), extras unchanged");
            }
        }
    }

    public function test_parity_each_return_leg_uses_its_own_collection_time(): void
    {
        $exec = $this->vt('executive');
        // Out 24 Dec (+25%) → £131.25 → £135; back on Boxing Day (+50%) from the airport → £157.50 → £160 + £10 meet & greet.
        $r = $this->compare('Sheffield S1 2HH', self::MAN, 'return', $exec, [], false, null, '2026-12-24T10:00', '2026-12-26T15:00');
        $this->assertTrue($r['match'], $this->row($r));
        $this->assertEqualsWithDelta(135.00, $r['cust_out'], 0.001);
        $this->assertEqualsWithDelta(170.00, $r['cust_ret'], 0.001);
        $this->assertEqualsWithDelta(305.00, $r['cust_pay'], 0.001);

        // Out on Christmas Day, back on an ordinary day → only the outbound is uplifted.
        // No drag from home (directors, 11 Nov 2026): Sheffield → Leeds is the mileage fare
        // only, £105 per leg; Christmas +50% on the outbound → £160 (rounded to £5).
        $r = $this->compare('Sheffield S1 2HH', 'Leeds LS1 4DY', 'return', $exec, [], false, null, '2026-12-25T10:00', '2027-01-05T10:00');
        $this->assertTrue($r['match'], $this->row($r));
        $this->assertEqualsWithDelta(160.00, $r['cust_out'], 0.001);
        $this->assertEqualsWithDelta(105.00, $r['cust_ret'], 0.001);
    }

    public function test_parity_office_night_and_date_surcharges_apply_on_both_sides(): void
    {
        TimeSurcharges::save(['enabled' => true, 'from' => '22:00', 'to' => '06:00', 'type' => 'percent', 'value' => 20], [
            ['date' => '2026-11-18', 'label' => 'Test date uplift', 'type' => 'fixed', 'value' => 25],
            ['date' => '2026-12-25', 'label' => 'Office Xmas', 'type' => 'fixed', 'value' => 40],
        ]);
        $exec = $this->vt('executive');
        foreach ([
            ['2026-11-17T23:30', 'one_way', null, 130.0],          // night +20% (126 → £130)
            ['2026-11-18T12:00', 'one_way', null, 130.0],          // office date +£25
            ['2026-12-25T12:00', 'one_way', null, 160.0],          // festive wins over the office date (+£40 NOT added); 157.50 → £160
            ['2026-12-31T23:30', 'one_way', null, 180.0],          // NYE evening +50% + night +20%, both on £105 (178.50 → £180)
            ['2026-11-17T23:30', 'return', '2026-11-20T11:00', 245.0], // 130 out + (105 + 10 m&g) back at 11:00
        ] as [$when, $jt, $ret, $expected]) {
            $r = $this->compare('Sheffield S1 2HH', self::MAN, $jt, $exec, [], false, null, $when, $ret ?? self::RET_AT);
            $this->assertTrue($r['match'], $this->row($r));
            $this->assertEqualsWithDelta($expected, $r['cust_pay'], 0.001, $this->row($r));
        }
    }

    public function test_parity_the_thank_you_page_shows_what_the_pay_link_charges(): void
    {
        $exec = $this->vt('executive');
        foreach ([['return', false], ['return', true], ['one_way', true]] as [$jt, $vat]) {
            $res = $this->post(route('widget.book.store'), $this->widgetPayload('Sheffield S1 2HH', 'Leeds LS1 4DY', $jt, $exec, ['child_seats' => 1], $vat))->assertOk();
            $out = Booking::where('source', 'web')->where('is_return_leg', false)->latest('id')->first();
            $this->assertEqualsWithDelta($this->payFor($out), (float) $res->viewData('payAmount'), 0.001, "{$jt}".($vat ? ' VAT' : ''));
        }
    }

    public function test_parity_admin_stores_each_return_leg_at_its_own_quoted_fare(): void
    {
        // Kidderminster round trip through bookings.store: outbound £215 + £115 drag =
        // £330; return Premier Inn MAN → Kidderminster: SAME price both ways (drag to
        // the far end, Kidderminster, 113.9 mi → £115) = £215 + £115 + £10 airport
        // pickup = £340. The customer side stores the same two legs.
        $exec = $this->vt('executive');
        $adm = $this->adminStore(self::KIDDY, self::PREMIER_INN, 'return', $exec);
        $cust = $this->customer(self::KIDDY, self::PREMIER_INN, 'return', $exec);
        $this->assertEqualsWithDelta(330.0, $adm['out'], 0.01);
        $this->assertEqualsWithDelta(340.0, $adm['ret'], 0.01);
        $this->assertEqualsWithDelta(670.0, $adm['pays'], 0.01);
        $this->assertEqualsWithDelta(330.0, $cust['out'], 0.01);
        $this->assertEqualsWithDelta(340.0, $cust['ret'], 0.01);
        $this->assertEqualsWithDelta(670.0, $cust['shown'], 0.01);
        $this->assertEqualsWithDelta(670.0, $cust['pay'], 0.01);
    }

    public function test_via_stops_are_saved_on_both_legs_because_both_legs_are_charged(): void
    {
        $exec = $this->vt('executive');

        // Admin form: the return leg gets the stops too (reversed), like its price.
        $this->actingAs($this->admin)->post(route('bookings.store'), [
            'customer_name' => 'Stops', 'customer_phone' => '07700900333', 'vehicle_type_id' => $exec->id,
            'journey_type' => 'return', 'pickup_at' => self::OUT_AT, 'return_pickup_at' => self::RET_AT,
            'pickup_address' => 'Sheffield S1 2HH', 'destination_address' => 'Leeds LS1 4DY',
            'via_stops' => ['Stop A, Sheffield S2 4SU', 'Stop B, Barnsley S70 2TA'],
            'passengers' => 2, 'payment_method' => 'card', 'privacy_consent' => '1', 'quoted_price' => 265,
        ])->assertSessionHasNoErrors();
        $ret = Booking::where('is_return_leg', true)->latest('id')->firstOrFail();
        $out = Booking::where('is_return_leg', false)->latest('id')->firstOrFail();
        $this->assertSame(['Stop A, Sheffield S2 4SU', 'Stop B, Barnsley S70 2TA'], $out->viaStops());
        $this->assertSame(['Stop B, Barnsley S70 2TA', 'Stop A, Sheffield S2 4SU'], $ret->viaStops());

        // Website: the same.
        $this->post(route('widget.book.store'), array_merge(
            $this->widgetPayload('Sheffield S1 2HH', 'Leeds LS1 4DY', 'return', $exec),
            ['stops' => ['Stop A, Sheffield S2 4SU', 'Stop B, Barnsley S70 2TA']],
        ))->assertOk();
        $wret = Booking::where('source', 'web')->where('is_return_leg', true)->latest('id')->firstOrFail();
        $this->assertSame(['Stop B, Barnsley S70 2TA', 'Stop A, Sheffield S2 4SU'], $wret->viaStops());
    }

    public function test_parity_hourly_hire_is_never_auto_priced_for_the_customer(): void
    {
        $this->post(route('widget.book.store'), $this->widgetPayload('Sheffield S1 2HH', '', 'hourly', $this->vt('executive')) + ['hours' => 3])
            ->assertOk();
        $this->assertNull(Booking::where('source', 'web')->latest('id')->first()->quoted_price);
    }

    public function test_non_sheffield_s_postcodes_never_get_the_sheffield_fare_when_maps_cannot_measure(): void
    {
        $distance = \Mockery::mock(DistanceService::class);
        $distance->shouldReceive('resolve')->andReturn(['miles' => 10.0, 'minutes' => 25, 'source' => 'estimate']);
        $distance->shouldReceive('configured')->andReturn(true);
        $this->app->instance(DistanceService::class, $distance);

        foreach (['Stoke-on-Trent ST4 4EG', 'Southampton SO14 3TJ', 'Swansea SA1 1NW', 'London SE1 7PB'] as $far) {
            $q = app(QuoteService::class)->quote($far, self::MAN, $this->vt('executive'));
            $this->assertNull($q['price'], "{$far} → MAN with Maps unable to measure must go to the office, not £{$q['price']}");
        }
    }

    // =====================================================================
    //  Harness
    // =====================================================================

    /**
     * Price one journey both ways.
     *
     * @return array<string, mixed>
     */
    private function compare(string $p, string $d, string $jt, VehicleType $vt, array $ex = [], bool $vat = false, ?string $voucher = null, string $when = self::OUT_AT, string $retWhen = self::RET_AT): array
    {
        $cust = $this->customer($p, $d, $jt, $vt, $ex, $vat, $voucher, $when, $retWhen);
        $strip = $this->strip($p, $d, $jt, $vt, $ex, $when, $retWhen);
        $legs = $this->stripLegs($p, $d, $jt, $vt, $ex, $when, $retWhen);
        // What admin's pay link charges: amountPayable() per leg (net + VAT per leg).
        $payable = fn (?float $net) => $net === null ? 0.0 : ($vat ? app(VatService::class)->fromNet($net)['gross'] : $net);
        $adminPays = $strip === null ? null
            : round($legs ? $payable($legs[0]) + $payable($legs[1]) : $payable($strip), 2);

        return [
            'label' => sprintf('%s → %s | %s | %s%s%s | %s', $p, $d, $jt === 'return' ? 'return' : 'one-way', $vt->name,
                $ex ? ' | '.$this->exLabel($ex) : '', $vat ? ' | VAT' : '', $when.($jt === 'return' ? ' / '.$retWhen : '')),
            'cust_shown' => $cust['shown'],
            'cust_charged' => $cust['charged'],
            'cust_out' => $cust['out'],
            'cust_ret' => $cust['ret'],
            'cust_pay' => $cust['pay'],
            'admin_strip' => $strip,
            'admin_pays' => $adminPays,
            'admin_legs' => $legs,
            // Shown = pay link = admin's payable; stored (net) = admin net, per leg.
            'match' => $this->same($cust['shown'], $adminPays)
                && $this->same($cust['pay'], $adminPays)
                && $this->same($cust['charged'], $strip)
                && (! $legs || ($this->same($cust['out'], $legs[0]) && $this->same($cust['ret'], $legs[1]))),
        ];
    }

    /** @return array{shown: float|null, charged: float|null, out: float|null, ret: float|null, pay: float|null} */
    private function customer(string $p, string $d, string $jt, VehicleType $vt, array $ex = [], bool $vat = false, ?string $voucher = null, string $when = self::OUT_AT, string $retWhen = self::RET_AT): array
    {
        // 1) What the customer SEES: widget.prices `total` with every choice the widget
        //    JS sends (the JS displays this figure as-is — no client-side maths).
        $opt = collect($this->postJson(route('widget.prices'), array_filter([
            'pickup' => $p, 'destination' => $d, 'pickup_at' => $when,
            'journey_type' => $jt, 'return_pickup_at' => $jt === 'return' ? $retWhen : null,
            'child_seats' => $ex['child_seats'] ?? 0, 'booster_seats' => $ex['booster_seats'] ?? 0,
            'infant_seats' => $ex['infant_seats'] ?? 0, 'stops' => $ex['stops'] ?? 0,
            'ribbons' => ! empty($ex['ribbons']) ? 1 : 0, 'vat_invoice' => $vat ? 1 : 0,
            'voucher' => $voucher,
        ], fn ($v) => $v !== null))->assertOk()->json('options'))->firstWhere('id', $vt->id);
        $shown = ($opt['total'] ?? null) === null ? null : (float) $opt['total'];

        // 2) What is STORED on each leg, and 3) what the signed pay link charges.
        $before = (int) Booking::max('id');
        $this->post(route('widget.book.store'), $this->widgetPayload($p, $d, $jt, $vt, $ex, $vat, $voucher, $when, $retWhen))
            ->assertOk()->assertSessionHasNoErrors();
        $new = Booking::where('id', '>', $before)->orderBy('id')->get();
        $out = $new->firstWhere('is_return_leg', false);
        $ret = $new->firstWhere('is_return_leg', true);
        $charged = $out->quoted_price === null ? null
            : round((float) $out->quoted_price + (float) ($ret?->quoted_price ?? 0), 2);

        return [
            'shown' => $shown,
            'charged' => $charged,
            'out' => $out->quoted_price === null ? null : (float) $out->quoted_price,
            'ret' => $ret?->quoted_price === null ? null : (float) $ret->quoted_price,
            'pay' => $charged === null ? null : $this->payFor($out),
        ];
    }

    private function widgetPayload(string $p, string $d, string $jt, VehicleType $vt, array $ex = [], bool $vat = false, ?string $voucher = null, string $when = self::OUT_AT, string $retWhen = self::RET_AT): array
    {
        $airport = AirportMatcher::isAirport($p) || AirportMatcher::isAirport($d);

        return array_filter([
            'journey_type' => $jt,
            'pickup_address' => $p,
            'destination_address' => $d ?: null,
            'pickup_at' => $when,
            'return_pickup_at' => $jt === 'return' ? $retWhen : null,
            'vehicle_type_id' => $vt->id,
            'passengers' => 2, 'suitcases' => 0, 'hand_luggage' => 1,
            'flight_number' => $airport ? 'BA1234' : null,
            'flight_landing_at' => $airport ? $when : null,
            'customer_name' => 'Parity Sweep', 'customer_phone' => '07700900111', 'customer_email' => 'sweep@example.com',
            'payment_method' => 'card',
            'child_seats' => $ex['child_seats'] ?? null,
            'booster_seats' => $ex['booster_seats'] ?? null,
            'infant_seats' => $ex['infant_seats'] ?? null,
            'stops' => ! empty($ex['stops']) ? array_fill(0, (int) $ex['stops'], self::VIA) : null,
            'ribbons' => ! empty($ex['ribbons']) ? 1 : null,
            'vat_invoice' => $vat ? 1 : null,
            'business_name' => $vat ? 'Acme Ltd' : null,
            'business_address' => $vat ? '1 High St, Sheffield S1 1AA' : null,
            'business_vat_number' => $vat ? 'GB123456789' : null,
            'voucher' => $voucher,
        ], fn ($v) => $v !== null);
    }

    /** The amount the signed widget.pay link hands to the card provider. */
    private function payFor(Booking $booking): ?float
    {
        $this->charged = [];
        $this->get(URL::temporarySignedRoute('widget.pay', now()->addHour(), ['booking' => $booking->id]))->assertRedirect();

        return $this->charged ? round((float) end($this->charged), 2) : null;
    }

    private function stripOptions(string $p, string $d, string $jt, array $ex, string $when, string $retWhen): array
    {
        $body = [
            'pickup' => $p, 'destination' => $d, 'pickup_at' => $when, 'journey_type' => $jt,
            'return_pickup_at' => $jt === 'return' ? $retWhen : null,
            'stops' => (int) ($ex['stops'] ?? 0),
            // The office ticks meet & greet on an airport job (charged per airport-collection leg).
            'meet_greet' => $this->mgApplies($p, $d, $jt) ? 1 : 0,
            'child_seats' => (int) ($ex['child_seats'] ?? 0),
            'booster_seats' => (int) ($ex['booster_seats'] ?? 0),
            'infant_seats' => (int) ($ex['infant_seats'] ?? 0),
            'ribbons' => ! empty($ex['ribbons']) ? 1 : 0,
        ];
        $key = md5(json_encode($body));

        return $this->stripCache[$key] ??= $this->actingAs($this->admin)
            ->postJson(route('pricing.strip'), $body)->assertOk()->json('options');
    }

    /** Admin strip TOTAL (net, both legs) for this vehicle, null when POA. */
    private function strip(string $p, string $d, string $jt, VehicleType $vt, array $ex = [], string $when = self::OUT_AT, string $retWhen = self::RET_AT): ?float
    {
        $o = collect($this->stripOptions($p, $d, $jt, $ex, $when, $retWhen))->firstWhere('id', $vt->id);

        return $o['price'] === null ? null : (float) $o['price'];
    }

    private function stripLegs(string $p, string $d, string $jt, VehicleType $vt, array $ex, string $when, string $retWhen): ?array
    {
        if ($jt !== 'return') {
            return null;
        }
        $o = collect($this->stripOptions($p, $d, $jt, $ex, $when, $retWhen))->firstWhere('id', $vt->id);
        if ($o['price'] === null) {
            return null;
        }
        $legs = collect($o['breakdown'] ?? [])->pluck('amount', 'label');

        return [(float) $legs['Outbound leg'], (float) $legs['Return leg']];
    }

    /**
     * Admin end to end: take the strip's quote into bookings.store exactly as the form
     * does (quoted_price = total, leg_price_out/return from the breakdown), then read
     * what each leg stores and what each leg's amountPayable() would charge.
     *
     * @return array{out: float|null, ret: float|null, pays: float|null}
     */
    private function adminStore(string $p, string $d, string $jt, VehicleType $vt, array $ex = [], bool $vat = false, ?string $voucher = null): array
    {
        $total = $this->strip($p, $d, $jt, $vt, $ex);
        $legs = $this->stripLegs($p, $d, $jt, $vt, $ex, self::OUT_AT, self::RET_AT);
        $airport = AirportMatcher::isAirport($p) || AirportMatcher::isAirport($d);
        $before = (int) Booking::max('id');

        $this->actingAs($this->admin)->post(route('bookings.store'), array_filter([
            'customer_name' => 'Admin Sweep', 'customer_phone' => '07700900222', 'customer_email' => 'admin-sweep@example.com',
            'vehicle_type_id' => $vt->id, 'journey_type' => $jt,
            'pickup_at' => self::OUT_AT, 'return_pickup_at' => $jt === 'return' ? self::RET_AT : null,
            'pickup_address' => $p, 'destination_address' => $d,
            'passengers' => 2, 'payment_method' => 'card', 'privacy_consent' => '1',
            'flight_number' => $airport ? 'BA1234' : null,
            'meet_greet' => $this->mgApplies($p, $d, $jt) ? '1' : null,
            'child_seats' => $ex['child_seats'] ?? null,
            'quoted_price' => $total,
            'leg_price_out' => $legs[0] ?? null, 'leg_price_return' => $legs[1] ?? null,
            'vat_invoice' => $vat ? '1' : null, 'vat_company' => $vat ? 'Acme Ltd' : null,
            'voucher' => $voucher,
        ], fn ($v) => $v !== null))->assertSessionHasNoErrors()->assertRedirect();

        $new = Booking::where('id', '>', $before)->orderBy('id')->get();
        $out = $new->firstWhere('is_return_leg', false)->fresh();
        $ret = $new->firstWhere('is_return_leg', true)?->fresh();

        return [
            'out' => $out->quoted_price === null ? null : (float) $out->quoted_price,
            'ret' => $ret?->quoted_price === null ? null : (float) $ret->quoted_price,
            'pays' => round((float) $out->amountPayable() + (float) ($ret?->amountPayable() ?? 0), 2),
        ];
    }

    /** Meet & greet applies to the trip (charged per airport-collection leg by both sides). */
    private function mgApplies(string $p, string $d, string $jt): bool
    {
        return AirportMatcher::isAirport($p) || ($jt === 'return' && AirportMatcher::isAirport($d));
    }

    private function vehicles()
    {
        return VehicleType::where('is_active', true)->orderBy('sort_order')->get();
    }

    private function vt(string $slug): VehicleType
    {
        return VehicleType::where('slug', $slug)->firstOrFail();
    }

    private function same(?float $a, ?float $b): bool
    {
        return ($a === null && $b === null) || ($a !== null && $b !== null && abs($a - $b) < 0.005);
    }

    private function exLabel(array $ex): string
    {
        return collect($ex)->filter()->map(fn ($v, $k) => $v === true ? $k : "{$k}={$v}")->implode(', ');
    }

    private function row(array $r): string
    {
        $m = fn ($v) => $v === null ? 'POA' : '£'.number_format((float) $v, 2);
        $legs = $r['admin_legs'] ? sprintf(' (out %s + ret %s)', $m($r['admin_legs'][0]), $m($r['admin_legs'][1])) : '';
        $custLegs = $r['cust_ret'] !== null ? sprintf(' (out %s + ret %s)', $m($r['cust_out']), $m($r['cust_ret'])) : '';

        return sprintf('%s || CUSTOMER shown %s, stored %s%s, pay link %s || ADMIN strip %s%s, customer pays %s',
            $r['label'], $m($r['cust_shown']), $m($r['cust_charged']), $custLegs, $m($r['cust_pay']),
            $m($r['admin_strip']), $legs, $m($r['admin_pays']));
    }

    // ---------------------------------------------------------------------
    //  Stubs
    // ---------------------------------------------------------------------

    private function stubDistances(): void
    {
        $base = (string) config('cet.base.address');
        $distance = \Mockery::mock(DistanceService::class);
        $distance->shouldReceive('resolve')->andReturnUsing(function (string $from, string $to) use ($base) {
            $miles = $from === $base ? $this->baseMiles($to) : $this->tripMiles($from, $to);
            if ($miles === null) {
                return ['miles' => 10.0, 'minutes' => 25, 'source' => 'estimate'];
            }

            return ['miles' => $miles, 'minutes' => (int) round($miles * 1.3), 'source' => 'google'];
        });
        $distance->shouldReceive('configured')->andReturn(true);
        $this->app->instance(DistanceService::class, $distance);
    }

    private function stubGateway(): void
    {
        $gateway = \Mockery::mock(PaymentGateway::class)->shouldIgnoreMissing();
        $gateway->shouldReceive('enabled')->andReturn(true);
        $gateway->shouldReceive('enabledForBooking')->andReturn(true);
        $gateway->shouldReceive('createCheckoutUrl')->andReturnUsing(function (Booking $b, float $amount) {
            $this->charged[] = $amount;

            return 'https://pay.example.test/'.$b->id.'/'.count($this->charged);
        });
        $this->app->instance(PaymentGateway::class, $gateway);
    }

    private function baseMiles(string $to): ?float
    {
        if (isset(self::BASE_OVERRIDE[$to])) {
            return self::BASE_OVERRIDE[$to];
        }
        if (! isset(self::PLACES[$to])) {
            $this->unknown[] = 'base → '.$to;

            return null;
        }

        return $this->road(self::BASE, self::PLACES[$to]);
    }

    private function tripMiles(string $from, string $to): ?float
    {
        if (isset(self::TRIP_OVERRIDE[$from.'|'.$to])) {
            return self::TRIP_OVERRIDE[$from.'|'.$to];
        }
        if (isset(self::TRIP_OVERRIDE[$to.'|'.$from])) {
            return self::TRIP_OVERRIDE[$to.'|'.$from];
        }
        if (! isset(self::PLACES[$from], self::PLACES[$to])) {
            $this->unknown[] = $from.' → '.$to;

            return null;
        }

        return $this->road(self::PLACES[$from], self::PLACES[$to]);
    }

    private function road(array $a, array $b): float
    {
        [$lat1, $lng1] = array_map('deg2rad', $a);
        [$lat2, $lng2] = array_map('deg2rad', $b);
        $h = sin(($lat2 - $lat1) / 2) ** 2 + cos($lat1) * cos($lat2) * sin(($lng2 - $lng1) / 2) ** 2;

        return round(3958.8 * 2 * asin(sqrt($h)) * self::ROAD_FACTOR, 1);
    }
}
