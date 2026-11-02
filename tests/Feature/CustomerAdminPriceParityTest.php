<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Models\VehicleType;
use App\Models\Voucher;
use App\Services\Payments\PaymentGateway;
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
 *   CUSTOMER  widget.prices (what the card shows, combined exactly like the widget
 *             JS currentTotal(): price × legs + meet & greet + extras, × VAT),
 *             widget.book.store (quoted_price stored on BOTH legs) and the signed
 *             widget.pay link (the amount actually sent to Stripe/Square).
 *   ADMIN     pricing.strip (FareCalculator per leg, the return on its own route
 *             with its own drag) and, where it matters, bookings.store (the per-leg
 *             fares it stores and what each leg's amountPayable() charges).
 *
 * Distances are stubbed offline: straight-line × 1.25 road factor from real
 * coordinates, with the staging-measured Kidderminster figures pinned
 * (113.9 mi base→pickup, 91.7 mi pickup→Premier Inn MAN).
 *
 * Tests named test_parity_* are CONFIRMED parity and must stay green.
 * Tests named test_MISMATCH_* document a real divergence: they markTestIncomplete
 * with the exact figures while it exists, and turn into a passing parity check by
 * themselves once the directors decide the fix and it lands. No pricing code was
 * changed for this sweep.
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

    private array $pricesCache = [];

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
    //  CONFIRMED PARITY
    // =====================================================================

    public function test_parity_one_way_every_area_every_vehicle_no_extras(): void
    {
        $bad = [];
        foreach (self::PAIRS as [$p, $d]) {
            foreach ($this->vehicles() as $vt) {
                $r = $this->compare($p, $d, 'one_way', $vt);
                if (! $r['match'] || ! $this->same($r['cust_shown'], $r['cust_charged']) || ! $this->same($r['cust_charged'], $r['cust_pay'])) {
                    $bad[] = $this->row($r);
                }
            }
        }
        $this->assertSame([], $this->unknown, 'stub was asked for an unknown address');
        $this->assertSame([], $bad, "One-way customer vs admin differ:\n".implode("\n", $bad));
    }

    public function test_parity_return_where_the_reverse_leg_prices_the_same_as_the_outbound(): void
    {
        $checked = 0;
        $bad = [];
        foreach (self::PAIRS as [$p, $d]) {
            foreach ($this->vehicles() as $vt) {
                if (! $this->symmetric($p, $d, $vt) || $this->bothAirports($p, $d)) {
                    continue; // covered by the MISMATCH test below
                }
                $checked++;
                $r = $this->compare($p, $d, 'return', $vt);
                if (! $r['match'] || ! $this->same($r['cust_charged'], $r['cust_pay']) || ! $this->same($r['cust_shown'], $r['cust_charged'])) {
                    $bad[] = $this->row($r);
                }
            }
        }
        $this->assertGreaterThan(50, $checked);
        $this->assertSame([], $bad, "Symmetric returns differ:\n".implode("\n", $bad));
    }

    public function test_parity_one_way_extras_seats_stops_and_meet_and_greet(): void
    {
        $bad = [];
        foreach (self::SUBSET as [$p, $d]) {
            foreach (['executive', 'estate', 'v-class', 'minibus-8'] as $slug) {
                foreach ([
                    ['child_seats' => 1, 'booster_seats' => 1],
                    ['infant_seats' => 2],
                    ['stops' => 2],
                    ['child_seats' => 1, 'stops' => 1],
                ] as $ex) {
                    $r = $this->compare($p, $d, 'one_way', $this->vt($slug), $ex);
                    if (! $r['match'] || ! $this->same($r['cust_shown'], $r['cust_charged'])) {
                        $bad[] = $this->row($r);
                    }
                }
            }
        }
        $this->assertSame([], $bad, "One-way extras differ:\n".implode("\n", $bad));
    }

    public function test_parity_wedding_ribbons_on_cars(): void
    {
        $bad = [];
        foreach (self::SUBSET as [$p, $d]) {
            foreach (['executive', 'estate'] as $slug) {
                $r = $this->compare($p, $d, 'one_way', $this->vt($slug), ['ribbons' => true]);
                if (! $r['match']) {
                    $bad[] = $this->row($r);
                }
            }
        }
        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_parity_meet_and_greet_is_charged_once_on_the_arrival_leg(): void
    {
        // Admin side ticks meet & greet (the form only AUTO-ticks it when the outbound
        // pickup is the airport — on Sheffield → MAN return the office must tick it).
        $exec = $this->vt('executive');
        foreach ([
            ['Sheffield S1 2HH', self::MAN, 'return', 105 + 105 + 10],
            [self::MAN, 'Sheffield S1 2HH', 'return', 105 + 10 + 105],
            [self::MAN, 'Sheffield S1 2HH', 'one_way', 105 + 10],
            ['Sheffield S1 2HH', self::MAN, 'one_way', 105],
        ] as [$p, $d, $jt, $expected]) {
            $r = $this->compare($p, $d, $jt, $exec);
            $this->assertTrue($r['match'], $this->row($r));
            $this->assertEqualsWithDelta($expected, $r['cust_charged'], 0.01, $this->row($r));
            $this->assertEqualsWithDelta($expected, $r['cust_shown'], 0.01, $this->row($r));
        }
    }

    public function test_parity_vat_invoice_customer_is_quoted_net_plus_20_percent_like_admin(): void
    {
        $bad = [];
        foreach (self::SUBSET as [$p, $d]) {
            foreach (['executive', 'v-class'] as $slug) {
                foreach (['one_way', 'return'] as $jt) {
                    if ($jt === 'return' && ! $this->symmetric($p, $d, $this->vt($slug))) {
                        continue;
                    }
                    $r = $this->compare($p, $d, $jt, $this->vt($slug), [], vat: true);
                    // Compares what the customer is SHOWN/STORED with what the admin says the
                    // customer pays (strip net × 1.2). The pay link is checked separately.
                    if (! $this->same($r['cust_shown'], $r['admin_pays']) || ! $this->same($r['cust_charged'], $r['admin_pays'])) {
                        $bad[] = $this->row($r);
                    }
                }
            }
        }
        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_parity_fixed_and_percent_voucher_on_a_one_way(): void
    {
        Voucher::create(['code' => 'FIVER', 'type' => 'fixed', 'value' => 5, 'is_active' => true]);
        Voucher::create(['code' => 'TEN', 'type' => 'percent', 'value' => 10, 'is_active' => true]);
        $exec = $this->vt('executive');

        foreach (['FIVER', 'TEN'] as $code) {
            foreach ([['Sheffield S1 2HH', self::MAN], [self::KIDDY, self::PREMIER_INN]] as [$p, $d]) {
                $cust = $this->customer($p, $d, 'one_way', $exec, [], false, $code);
                $adm = $this->adminStore($p, $d, 'one_way', $exec, [], false, $code);
                $this->assertEqualsWithDelta($adm['pays'], $cust['charged'], 0.01, "{$code} {$p} → {$d}: customer £{$cust['charged']} vs admin £{$adm['pays']}");
                $this->assertEqualsWithDelta($adm['pays'], $cust['pay'], 0.01, "{$code} pay link");
            }
        }
    }

    public function test_parity_admin_stores_each_return_leg_at_its_own_quoted_fare(): void
    {
        // Admin side of the Kidderminster round trip, end to end through bookings.store:
        // outbound £215 + £115 drag = £330; return Premier Inn MAN → Kidderminster is its
        // own leg: £215 trip + £40 drag (base → Manchester Airport) + £10 airport pickup
        // (meet & greet on the leg collected at the airport hotel) = £265.
        $exec = $this->vt('executive');
        $adm = $this->adminStore(self::KIDDY, self::PREMIER_INN, 'return', $exec);
        $this->assertEqualsWithDelta(330.0, $adm['out'], 0.01);
        $this->assertEqualsWithDelta(265.0, $adm['ret'], 0.01);
        $this->assertEqualsWithDelta(595.0, $adm['pays'], 0.01);
        $this->assertEqualsWithDelta($this->strip(self::KIDDY, self::PREMIER_INN, 'return', $exec), $adm['pays'], 0.01);
    }

    public function test_parity_hourly_hire_is_never_auto_priced_for_the_customer(): void
    {
        $this->post(route('widget.book.store'), $this->widgetPayload('Sheffield S1 2HH', '', 'hourly', $this->vt('executive')) + ['hours' => 3])
            ->assertOk();
        $this->assertNull(Booking::where('source', 'web')->latest('id')->first()->quoted_price);
    }

    // =====================================================================
    //  DOCUMENTED MISMATCHES (incomplete until the directors pick the fix)
    // =====================================================================

    /**
     * Customer return leg = a COPY of the outbound quote (BookingWidgetController
     * store(): $returnNet = $quote['price'] + $mgMoved; widget JS legs() × 2), but the
     * admin strip prices the return on its own reverse route with its own drag
     * (base → return pickup). Every out-of-area / asymmetric return differs.
     */
    public function test_MISMATCH_return_leg_is_a_copy_of_the_outbound_on_the_customer_side(): void
    {
        $rows = [];
        foreach (self::PAIRS as [$p, $d]) {
            foreach ($this->vehicles() as $vt) {
                if ($this->symmetric($p, $d, $vt) && ! $this->bothAirports($p, $d)) {
                    continue;
                }
                $r = $this->compare($p, $d, 'return', $vt);
                if (! $r['match']) {
                    $rows[] = $this->row($r);
                }
            }
        }
        $this->document($rows, 'RETURN: customer charges 2 × outbound; admin prices the reverse leg on its own (own drag; meet & greet on each airport-pickup leg)');
    }

    /** Widget always charges ribbons_car (£30); admin FareCalculator charges ribbons_minibus (£50) on minibus/V Class. */
    public function test_MISMATCH_wedding_ribbons_on_minibus_and_v_class(): void
    {
        $rows = [];
        foreach (self::SUBSET as [$p, $d]) {
            foreach (['v-class', 'minibus-8', 'minibus-8-xl'] as $slug) {
                $r = $this->compare($p, $d, 'one_way', $this->vt($slug), ['ribbons' => true]);
                if (! $r['match']) {
                    $rows[] = $this->row($r);
                }
            }
        }
        $this->document($rows, 'RIBBONS: widget uses ribbons_car for every vehicle; admin uses ribbons_minibus for minibus/V Class');
    }

    /** Widget charges extras once per trip (outbound only); admin strip charges them on BOTH legs. */
    public function test_MISMATCH_return_extras_once_per_trip_vs_per_leg(): void
    {
        $rows = [];
        // Symmetric pairs only, so the extras are the ONLY difference.
        $sym = [['Sheffield S1 2HH', self::MAN], [self::MAN, 'Sheffield S1 2HH'], ['Sheffield S1 2HH', 'Rotherham S60 1QY']];
        foreach ($sym as [$p, $d]) {
            foreach (['executive', 'v-class'] as $slug) {
                foreach ([['child_seats' => 1], ['booster_seats' => 1, 'infant_seats' => 1], ['stops' => 1], ['ribbons' => true]] as $ex) {
                    if ($slug === 'v-class' && ! empty($ex['ribbons'])) {
                        continue; // ribbon rate mismatch documented separately
                    }
                    $r = $this->compare($p, $d, 'return', $this->vt($slug), $ex);
                    if (! $r['match']) {
                        $rows[] = $this->row($r);
                    }
                }
            }
        }
        $this->document($rows, 'RETURN EXTRAS: widget adds seats/stops/ribbons once; admin strip adds them on outbound AND return (stops are only stored on the outbound leg by BookingService)');
    }

    /**
     * Widget card one-way with a VAT invoice: quoted_price is stored VAT-INCLUSIVE
     * (store(): $charge = net × 1.2) AND meta.vat_invoice_requested=true, so
     * Booking::amountPayable() adds VAT a second time (fareVatBreakdown treats the
     * stored price as NET). The pay link charges net × 1.44.
     */
    public function test_MISMATCH_vat_invoice_pay_link_charges_vat_twice(): void
    {
        $rows = [];
        foreach (self::SUBSET as [$p, $d]) {
            foreach (['one_way', 'return'] as $jt) {
                $exec = $this->vt('executive');
                if ($jt === 'return' && ! $this->symmetric($p, $d, $exec)) {
                    continue;
                }
                $r = $this->compare($p, $d, $jt, $exec, [], vat: true);
                if (! $this->same($r['cust_pay'], $r['admin_pays'])) {
                    $rows[] = $this->row($r);
                }
            }
        }
        $this->document($rows, 'VAT: the widget pay link charges the VAT-inclusive stored price PLUS VAT again (×1.44); admin pays net × 1.2');
    }

    /**
     * Two separate time-surcharge systems: admin FareCalculator applies the config
     * cet.holiday_surcharges factors (Halloween ×1.6, Christmas Day ×1.75, NYE ×1.25/×1.5,
     * NYD ×1.8/×1.5, …) but never passes the pickup time into QuoteService; the widget
     * uses QuoteService::quote(…, $pickupAt) which applies only the office TimeSurcharges
     * setting and never the config holiday factors.
     */
    public function test_MISMATCH_holiday_factor_is_admin_only(): void
    {
        $rows = [];
        $exec = $this->vt('executive');
        foreach (['2026-12-24T12:00', '2026-12-25T12:00', '2026-12-31T20:00', '2027-01-01T03:00'] as $when) {
            foreach ([['Sheffield S1 2HH', self::MAN], [self::KIDDY, self::PREMIER_INN]] as [$p, $d]) {
                $r = $this->compare($p, $d, 'one_way', $exec, [], false, null, $when);
                if (! $r['match']) {
                    $rows[] = $this->row($r);
                }
            }
        }
        $this->document($rows, 'HOLIDAY: admin applies config cet.holiday_surcharges; customer widget does not');
    }

    public function test_MISMATCH_office_night_surcharge_is_customer_only(): void
    {
        TimeSurcharges::save(['enabled' => true, 'from' => '22:00', 'to' => '06:00', 'type' => 'percent', 'value' => 20], [
            ['date' => '2026-11-18', 'label' => 'Test date uplift', 'type' => 'fixed', 'value' => 25],
        ]);
        $rows = [];
        $exec = $this->vt('executive');
        foreach (['2026-11-17T23:30', '2026-11-18T12:00'] as $when) {
            foreach ([['Sheffield S1 2HH', self::MAN], ['Sheffield S1 2HH', 'Leeds LS1 4DY']] as [$p, $d]) {
                $r = $this->compare($p, $d, 'one_way', $exec, [], false, null, $when);
                if (! $r['match']) {
                    $rows[] = $this->row($r);
                }
            }
        }
        // And on a return the customer's return leg inherits the OUTBOUND time's surcharge.
        $r = $this->compare('Sheffield S1 2HH', self::MAN, 'return', $exec, [], false, null, '2026-11-17T23:30', '2026-11-20T11:00');
        if (! $r['match']) {
            $rows[] = $this->row($r);
        }
        $this->document($rows, 'NIGHT/DATE (office TimeSurcharges): customer widget applies it; admin strip never does (FareCalculator calls quote() without the pickup time)');
    }

    /** Widget discounts only the OUTBOUND (price + extras); admin discounts the whole trip total. */
    public function test_MISMATCH_percent_voucher_on_a_return(): void
    {
        Voucher::create(['code' => 'TEN', 'type' => 'percent', 'value' => 10, 'is_active' => true]);
        $exec = $this->vt('executive');
        $rows = [];
        // Symmetric pairs only, so the voucher is the ONLY difference.
        foreach ([['Sheffield S1 2HH', self::MAN], [self::MAN, 'Sheffield S1 2HH'], ['Sheffield S1 2HH', 'Rotherham S60 1QY']] as [$p, $d]) {
            $cust = $this->customer($p, $d, 'return', $exec, [], false, 'TEN');
            $adm = $this->adminStore($p, $d, 'return', $exec, [], false, 'TEN');
            if (! $this->same($cust['charged'], $adm['pays'])) {
                $rows[] = sprintf('%s → %s | return | Executive | voucher TEN 10%% | customer charged £%.2f (out £%.2f + ret £%.2f) | admin £%.2f (out £%.2f + ret £%.2f)',
                    $p, $d, $cust['charged'], $cust['out'], $cust['ret'], $adm['pays'], $adm['out'], $adm['ret']);
            }
        }
        $this->document($rows, 'VOUCHER: widget takes 10% off the outbound only; admin takes 10% off the round-trip total');
    }

    /** The thank-you page "Pay now · £X" shows the outbound leg only; the link then charges both legs. */
    public function test_MISMATCH_thank_you_page_shows_only_the_outbound_amount_on_a_return(): void
    {
        $exec = $this->vt('executive');
        $res = $this->post(route('widget.book.store'), $this->widgetPayload('Sheffield S1 2HH', 'Leeds LS1 4DY', 'return', $exec))->assertOk();
        $out = Booking::where('source', 'web')->where('is_return_leg', false)->latest('id')->first();
        $charged = $this->payFor($out);
        $shown = (float) $res->viewData('payAmount');

        if (! $this->same($shown, $charged)) {
            $this->markTestIncomplete(sprintf('MISMATCH (display): Sheffield → Leeds return, Executive — thank-you page "Pay now · £%.2f" but the pay link charges £%.2f (both legs). BookingWidgetController::store() passes payAmount => $charge (outbound leg only).', $shown, $charged));
        }
        $this->assertEqualsWithDelta($charged, $shown, 0.01);
    }

    /**
     * Not a widget/admin split, but found on the way: builtinZonesFor() maps ANY
     * outward code starting with "S" to the Sheffield zone (SE, SW, ST, SK, SO, SN…).
     * With Maps up the 35-mile backstop catches it; when Google can't measure the
     * address, a full postcode skips the "office confirms" guard and the far address
     * gets the Sheffield fixed fare (Stoke-on-Trent → MAN = £105).
     */
    public function test_MISMATCH_non_sheffield_s_postcodes_get_the_sheffield_fare_when_maps_cannot_measure(): void
    {
        $distance = \Mockery::mock(DistanceService::class);
        $distance->shouldReceive('resolve')->andReturn(['miles' => 10.0, 'minutes' => 25, 'source' => 'estimate']);
        $distance->shouldReceive('configured')->andReturn(true);
        $this->app->instance(DistanceService::class, $distance);

        $rows = [];
        foreach (['Stoke-on-Trent ST4 4EG', 'Southampton SO14 3TJ', 'Swansea SA1 1NW', 'London SE1 7PB'] as $far) {
            $q = app(QuoteService::class)->quote($far, self::MAN, $this->vt('executive'));
            if ($q['price'] !== null) {
                $rows[] = sprintf('%s → Manchester Airport (Maps unable to measure) | Executive | quoted £%.2f (%s) — should be office-to-confirm', $far, $q['price'], $q['basis']);
            }
        }
        $this->document($rows, 'ZONE: any S-prefixed postcode is treated as Sheffield (QuoteService::builtinZonesFor, `str_starts_with($outward, \'S\')`)');
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
        $adminPays = $strip === null ? null : ($vat ? round($strip * 1.2, 2) : $strip);

        return [
            'label' => sprintf('%s → %s | %s | %s%s%s', $p, $d, $jt === 'return' ? 'return' : 'one-way', $vt->name,
                $ex ? ' | '.$this->exLabel($ex) : '', $vat ? ' | VAT' : ''),
            'cust_shown' => $cust['shown'],
            'cust_charged' => $cust['charged'],
            'cust_out' => $cust['out'],
            'cust_ret' => $cust['ret'],
            'cust_pay' => $cust['pay'],
            'admin_strip' => $strip,
            'admin_pays' => $adminPays,
            'admin_legs' => $this->stripLegs($p, $d, $jt, $vt, $ex, $when, $retWhen),
            'match' => $this->same($cust['charged'], $adminPays) && $this->same($cust['shown'], $adminPays),
        ];
    }

    /** @return array{shown: float|null, charged: float|null, out: float|null, ret: float|null, pay: float|null} */
    private function customer(string $p, string $d, string $jt, VehicleType $vt, array $ex = [], bool $vat = false, ?string $voucher = null, string $when = self::OUT_AT, string $retWhen = self::RET_AT): array
    {
        // 1) What the customer SEES: widget.prices × the widget JS currentTotal() formula.
        $opt = collect($this->widgetPrices($p, $d, $when))->firstWhere('id', $vt->id);
        $rates = \App\Support\Surcharges::rates();
        $legs = $jt === 'return' ? 2 : 1;
        $mg = $this->mgApplies($p, $d, $jt) ? (float) $rates['meet_greet'] : 0.0;
        $extras = ((int) ($ex['child_seats'] ?? 0) + (int) ($ex['booster_seats'] ?? 0) + (int) ($ex['infant_seats'] ?? 0)) * (float) $rates['child_seat']
            + (int) ($ex['stops'] ?? 0) * (float) $rates['stopover']
            + (! empty($ex['ribbons']) ? (float) $rates['ribbons_car'] : 0.0);
        $shown = ($opt['price'] ?? null) === null ? null
            : (float) round(((float) $opt['price'] * $legs + $mg + $extras) * ($vat ? 1.2 : 1)); // JS Math.round

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

    private function widgetPrices(string $p, string $d, string $when): array
    {
        $key = $p.'|'.$d.'|'.$when;

        return $this->pricesCache[$key] ??= $this->postJson(route('widget.prices'), [
            'pickup' => $p, 'destination' => $d, 'pickup_at' => $when,
        ])->assertOk()->json('options');
    }

    private function stripOptions(string $p, string $d, string $jt, array $ex, string $when, string $retWhen): array
    {
        $body = [
            'pickup' => $p, 'destination' => $d, 'pickup_at' => $when, 'journey_type' => $jt,
            'return_pickup_at' => $jt === 'return' ? $retWhen : null,
            'stops' => (int) ($ex['stops'] ?? 0),
            // The office ticks meet & greet whenever the customer widget would apply it.
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
        $legs = collect($o['breakdown'] ?? [])->pluck('amount', 'label');

        return [$legs['Outbound leg'] ?? null, $legs['Return leg'] ?? null];
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

    /** Does each leg of the return price the same in QuoteService (so a copy is right)? */
    private function symmetric(string $p, string $d, VehicleType $vt): bool
    {
        $q = app(QuoteService::class);

        return $this->same($q->quote($p, $d, $vt)['price'], $q->quote($d, $p, $vt)['price']);
    }

    private function bothAirports(string $p, string $d): bool
    {
        return AirportMatcher::isAirport($p) && AirportMatcher::isAirport($d);
    }

    /** Widget rule: meet & greet on an airport arrival (one-way airport pickup, or a return touching an airport). */
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
        return ($a === null && $b === null) || ($a !== null && $b !== null && abs($a - $b) < 0.01);
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

    /** Mark the test incomplete with every mismatch row, or pass when there are none. */
    private function document(array $rows, string $what): void
    {
        $this->assertSame([], $this->unknown, 'stub was asked for an unknown address');
        if ($rows) {
            $this->markTestIncomplete("MISMATCH — {$what}\n".count($rows)." case(s):\n".implode("\n", $rows));
        }
        $this->assertSame([], $rows);
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
