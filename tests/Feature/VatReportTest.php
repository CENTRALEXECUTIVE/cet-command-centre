<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Services\Reporting\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The VAT report lists every VAT invoice in a period with net / VAT / gross and
 * whether the VAT has been received — the figures for the VAT return.
 */
class VatReportTest extends TestCase
{
    use RefreshDatabase;

    private function vatBooking(float $net, bool $vatPaid, string $when): Booking
    {
        $b = Booking::factory()->create(['quoted_price' => $net, 'pickup_at' => \Carbon\Carbon::parse($when)]);
        $meta = ['vat_invoice_requested' => true];
        if ($vatPaid) {
            $meta['vat_paid'] = true;
        }
        $b->forceFill(['meta' => $meta])->save();

        return $b->fresh();
    }

    public function test_it_totals_net_vat_gross_and_splits_paid_outstanding(): void
    {
        // £180 net → £36 VAT → £216 gross. One paid, one outstanding.
        $this->vatBooking(180, true, now()->subDays(5)->toDateTimeString());
        $this->vatBooking(180, false, now()->subDays(3)->toDateTimeString());
        // A non-VAT booking in range must be ignored.
        Booking::factory()->create(['quoted_price' => 100, 'pickup_at' => now()->subDays(2)]);

        $data = app(ReportService::class)->vatInvoices(now()->subMonth(), now());

        $this->assertSame(2, $data['count']);
        $this->assertEqualsWithDelta(360.0, $data['net'], 0.01);
        $this->assertEqualsWithDelta(72.0, $data['vat'], 0.01);
        $this->assertEqualsWithDelta(432.0, $data['gross'], 0.01);
        $this->assertEqualsWithDelta(36.0, $data['vat_received'], 0.01);
        $this->assertEqualsWithDelta(36.0, $data['vat_outstanding'], 0.01);
    }

    public function test_the_page_and_csv_render_for_an_admin(): void
    {
        $this->vatBooking(180, false, now()->subDay()->toDateTimeString());
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('reports.vat'))->assertOk()->assertSee('VAT report');
        $this->actingAs($admin)->get(route('reports.vat', ['csv' => 1]))
            ->assertOk()
            ->assertDownload();
    }

    public function test_non_admins_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->driver()->create())
            ->get(route('reports.vat'))->assertForbidden();
    }
}
