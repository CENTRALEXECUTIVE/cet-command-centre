<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Prospect;
use App\Models\User;
use App\Services\Growth\GrowthDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GrowthDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Thursday 5 Nov 2026 → this week starts Mon 2 Nov.
        $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
    }

    public function test_bookings_are_split_by_channel_and_new_vs_returning(): void
    {
        $repeat = Customer::factory()->create();
        Booking::factory()->create(['customer_id' => $repeat->id, 'created_at' => '2026-10-01 10:00:00']); // earlier, outside window... still sets first-seen

        // This week:
        Booking::factory()->create(['source' => 'web', 'final_price' => 100,
            'meta' => ['marketing' => ['utm_source' => 'instagram', 'utm_medium' => 'organic']], 'created_at' => '2026-11-03 09:00:00']);
        Booking::factory()->create(['source' => 'web', 'final_price' => 80, 'created_at' => '2026-11-03 10:00:00']);
        Booking::factory()->create(['source' => 'web', 'final_price' => 90,
            'meta' => ['marketing' => ['gclid' => 'abc']], 'created_at' => '2026-11-04 10:00:00']);
        Booking::factory()->create(['customer_id' => $repeat->id, 'source' => 'phone', 'final_price' => 50, 'created_at' => '2026-11-04 11:00:00']);
        // Cancelled and demo bookings never count.
        Booking::factory()->create(['source' => 'web', 'final_price' => 999, 'status' => BookingStatus::Cancelled, 'created_at' => '2026-11-04 12:00:00']);
        Booking::factory()->create(['source' => 'demo', 'final_price' => 999, 'created_at' => '2026-11-04 12:00:00']);

        $d = app(GrowthDashboard::class)->build(4);
        $wk = '2026-11-02';

        $this->assertSame(1, $d['channels']['social']['count'][$wk]);
        $this->assertSame(1, $d['channels']['website']['count'][$wk]);
        $this->assertSame(1, $d['channels']['ads']['count'][$wk]);
        $this->assertSame(1, $d['channels']['phone']['count'][$wk]);
        $this->assertSame(4, $d['totals']['count'][$wk]);
        $this->assertEquals(320.0, $d['totals']['revenue'][$wk]);
        $this->assertSame(3, $d['totals']['new'][$wk]);
        $this->assertSame(1, $d['totals']['repeat'][$wk]);
    }

    public function test_return_leg_adds_value_but_not_an_extra_booking(): void
    {
        $out = Booking::factory()->create(['source' => 'web', 'final_price' => 100, 'created_at' => '2026-11-03 09:00:00']);
        Booking::factory()->create(['customer_id' => $out->customer_id, 'source' => 'web', 'final_price' => 100,
            'is_return_leg' => true, 'linked_booking_id' => $out->id, 'created_at' => '2026-11-03 09:00:00']);

        $d = app(GrowthDashboard::class)->build(4);
        $this->assertSame(1, $d['totals']['count']['2026-11-02']);
        $this->assertEquals(200.0, $d['totals']['revenue']['2026-11-02']);
    }

    public function test_page_shows_outreach_and_reviews_and_saves_the_social_log(): void
    {
        $admin = User::factory()->admin()->create();
        Prospect::create(['company' => 'Acme', 'town' => 'Sheffield', 'status' => 'emailed', 'contact_email' => 'a@acme.test',
            'last_contacted_at' => '2026-11-03 10:00:00']);
        Prospect::create(['company' => 'Beta', 'town' => 'Rotherham', 'status' => 'replied', 'contact_email' => 'b@beta.test',
            'last_contacted_at' => '2026-11-02 10:00:00', 'replied_at' => '2026-11-04 10:00:00']);
        Prospect::create(['company' => 'Gamma', 'town' => 'Barnsley', 'status' => 'new', 'contact_email' => 'g@gamma.test']);
        $b = Booking::factory()->create(['created_at' => '2026-10-20 10:00:00']);
        Message::create(['booking_id' => $b->id, 'customer_id' => $b->customer_id, 'channel' => 'whatsapp',
            'type' => 'review_request', 'to_address' => '07700900123', 'body' => 'Review please', 'status' => 'sent',
            'sent_at' => '2026-11-04 10:00:00']);

        $d = app(GrowthDashboard::class)->build(4);
        $this->assertSame(2, $d['outreach']['contacted_by_week']['2026-11-02']);
        $this->assertSame(1, $d['outreach']['replied_by_week']['2026-11-02']);
        $this->assertSame(1, $d['outreach']['with_email_not_yet_emailed']);
        $this->assertEquals(50.0, $d['outreach']['reply_rate']);
        $this->assertSame(1, $d['reviews']['2026-11-02']);

        $this->actingAs($admin)->get(route('marketing.growth'))
            ->assertOk()->assertSee('Growth dashboard')->assertSee('Bookings by channel')->assertSee('Outreach');

        $this->actingAs($admin)->post(route('marketing.growth.social'), [
            'week' => '2026-11-04', 'instagram' => 120, 'google_reviews' => 14, 'google_rating' => 4.9, 'tiktok' => '',
        ])->assertRedirect(route('marketing.growth'));

        $log = GrowthDashboard::log();
        $this->assertSame(['instagram' => 120, 'google_reviews' => 14, 'google_rating' => 4.9], $log['2026-11-02']);
    }

    public function test_drivers_cannot_open_it(): void
    {
        $driver = User::factory()->driver()->create();
        $this->actingAs($driver)->get(route('marketing.growth'))->assertStatus(403);
    }
}
