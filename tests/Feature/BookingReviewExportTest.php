<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BookingReviewExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_has_review_columns_and_no_personal_details(): void
    {
        $this->travelTo(Carbon::parse('2026-11-06 12:00:00'));
        $admin = User::factory()->admin()->create();
        $customer = Customer::factory()->create(['name' => 'Zara Privateperson', 'phone' => '07700900999', 'email' => 'zara@private.test']);

        Booking::factory()->create(['customer_id' => $customer->id, 'source' => 'phone', 'final_price' => 90,
            'pickup_address' => '12 Secret Lane, Sheffield S10 2AB', 'pickup_postcode' => 'S10 2AB',
            'created_at' => '2026-10-01 10:00:00']);
        Booking::factory()->create(['customer_id' => $customer->id, 'source' => 'web', 'final_price' => 120,
            'pickup_address' => '12 Secret Lane, Sheffield S10 2AB', 'pickup_postcode' => 'S10 2AB',
            'destination_address' => 'Manchester Airport M90 1QX',
            'meta' => ['marketing' => ['utm_source' => 'instagram', 'utm_medium' => 'organic', 'utm_campaign' => '2026-11-09-week']],
            'created_at' => '2026-11-03 09:15:00', 'pickup_at' => '2026-11-20 06:00:00']);

        $res = $this->actingAs($admin)->get(route('marketing.growth.export', ['from' => '2026-09-01', 'to' => '2026-11-06']));
        $res->assertOk();
        $csv = $res->streamedContent();

        $lines = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $head = $lines[0];
        $this->assertContains('channel', $head);
        $this->assertContains('customer_type', $head);
        $this->assertCount(3, $lines);

        $row = array_combine($head, $lines[2]);
        $this->assertSame('Social media', $row['channel']);
        $this->assertSame('instagram', $row['utm_source']);
        $this->assertSame('S10', $row['pickup_area']);
        $this->assertSame('M90', $row['dropoff_area']);
        $this->assertSame('returning', $row['customer_type']);
        $this->assertSame('2', $row['customer_booking_no']);
        $this->assertSame('2026-11-03', $row['booked_date']);
        $this->assertEquals(120, (float) $row['fare_ex_vat']);
        $this->assertSame('new', array_combine($head, $lines[1])['customer_type']);

        foreach (['Zara', 'Privateperson', '07700900999', 'zara@private.test', 'Secret Lane', '2AB'] as $pii) {
            $this->assertStringNotContainsString($pii, $csv);
        }
    }

    public function test_export_by_pickup_date_lists_future_jobs_booked_earlier(): void
    {
        $this->travelTo(Carbon::parse('2026-11-06 12:00:00'));
        $admin = User::factory()->admin()->create();
        Booking::factory()->create(['final_price' => 175, 'created_at' => '2026-08-01 10:00:00', 'pickup_at' => '2026-12-10 01:00:00']);
        Booking::factory()->create(['final_price' => 50, 'created_at' => '2026-10-01 10:00:00', 'pickup_at' => '2026-10-05 10:00:00']);

        $csv = $this->actingAs($admin)->get(route('marketing.growth.export', ['by' => 'pickup', 'from' => '2026-11-06', 'to' => '2028-11-06']))
            ->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $lines, 'header + the one future job, even though it was booked in August');
        $this->assertStringContainsString('2026-12-10', $lines[1]);

        $this->actingAs($admin)->get(route('marketing.growth'))->assertSee('All future jobs');
    }

    public function test_buttons_show_and_drivers_are_blocked(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('marketing.growth'))->assertOk()->assertSee('Export for review');
        $this->actingAs($admin)->get(route('review.index'))->assertOk()->assertSee('Export for review');

        $driver = User::factory()->driver()->create();
        $this->actingAs($driver)->get(route('marketing.growth.export'))->assertStatus(403);
    }
}
