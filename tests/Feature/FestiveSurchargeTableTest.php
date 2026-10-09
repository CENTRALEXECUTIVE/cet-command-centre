<?php

namespace Tests\Feature;

use App\Services\Pricing\FareCalculator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locks the published Festive & Special-Event surcharge table 2026/27 to the
 * real config, so the multipliers the office advertised are exactly what the
 * live fare engine applies (including the New Year time-of-day splits).
 */
class FestiveSurchargeTableTest extends TestCase
{
    private function factorAt(string $datetime): ?array
    {
        $tz = config('app.timezone');

        return app(FareCalculator::class)->holidayFactor(Carbon::parse($datetime, $tz));
    }

    public function test_all_day_festive_rates(): void
    {
        $this->assertSame(1.60, $this->factorAt('2026-10-31 12:00')['factor']); // Halloween +60%
        $this->assertSame(1.25, $this->factorAt('2026-12-24 09:00')['factor']); // Christmas Eve +25%
        $this->assertSame(1.75, $this->factorAt('2026-12-25 14:00')['factor']); // Christmas Day +75%
        $this->assertSame(1.50, $this->factorAt('2026-12-26 08:00')['factor']); // Boxing Day +50%

        $this->assertSame('Halloween', $this->factorAt('2026-10-31 12:00')['label']);
        $this->assertSame('Christmas Day', $this->factorAt('2026-12-25 14:00')['label']);
    }

    public function test_new_years_eve_splits_at_6pm(): void
    {
        $this->assertSame(1.25, $this->factorAt('2026-12-31 05:00')['factor']); // before 6pm +25%
        $this->assertSame(1.25, $this->factorAt('2026-12-31 17:59')['factor']); // last minute before 6pm
        $this->assertSame(1.50, $this->factorAt('2026-12-31 18:00')['factor']); // 6pm → midnight +50%
        $this->assertSame(1.50, $this->factorAt('2026-12-31 23:30')['factor']);
    }

    public function test_new_years_day_splits_at_6am(): void
    {
        $this->assertSame(1.80, $this->factorAt('2027-01-01 00:30')['factor']); // midnight–6am +80%
        $this->assertSame(1.80, $this->factorAt('2027-01-01 05:59')['factor']); // last minute before 6am
        $this->assertSame(1.50, $this->factorAt('2027-01-01 06:00')['factor']); // 6am onwards +50%
        $this->assertSame(1.50, $this->factorAt('2027-01-01 15:00')['factor']);
    }

    public function test_normal_days_have_no_surcharge(): void
    {
        $this->assertNull($this->factorAt('2026-12-20 10:00')); // 18–23 Dec normal
        $this->assertNull($this->factorAt('2026-12-28 10:00')); // 27–30 Dec normal
        $this->assertNull($this->factorAt('2027-01-02 10:00')); // 2 Jan onwards normal
        $this->assertNull($this->factorAt('2026-11-15 10:00')); // ordinary day
    }

    public function test_it_recurs_every_year_without_edits(): void
    {
        // Same table applies in any future year — no date edits needed.
        foreach (['2027', '2030', '2045'] as $year) {
            $this->assertSame(1.75, $this->factorAt("$year-12-25 12:00")['factor'], "Christmas $year");
            $this->assertSame(1.60, $this->factorAt("$year-10-31 20:00")['factor'], "Halloween $year");
            $this->assertSame(1.50, $this->factorAt("$year-12-31 19:00")['factor'], "NYE evening $year");
            $this->assertSame(1.80, $this->factorAt("$year-01-01 03:00")['factor'], "NYD early $year");
            $this->assertNull($this->factorAt("$year-07-04 12:00"), "ordinary day $year");
        }
    }
}
