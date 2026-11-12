<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Directors (12 Nov 2026): "How did you hear about us?" on the booking form, and a
 * weekly bookings-by-source report with a marker at the week Google Ads were paused.
 */
class BookingSourcesReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_heard_about_comes_from_the_answer_or_the_link(): void
    {
        $b = new Booking(['meta' => ['heard_about' => 'tiktok']]);
        $this->assertSame('tiktok', $b->heardAboutKey());
        $this->assertSame('google_ads', (new Booking(['meta' => ['marketing' => ['gclid' => 'abc']]]))->heardAboutKey());
        $this->assertSame('instagram', (new Booking(['meta' => ['marketing' => ['utm_source' => 'ig']]]))->heardAboutKey());
        $this->assertNull((new Booking(['meta' => []]))->heardAboutKey());
        $this->assertSame('Google Ads', Booking::heardAboutLabel('google_ads'));
        $this->assertSame('Not asked / unknown', Booking::heardAboutLabel(null));
    }

    public function test_the_report_counts_bookings_by_week_channel_and_source(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12 10:00'));
        $c = Customer::create(['name' => 'Jo', 'phone' => '07700900123']);
        $make = fn ($created, $attrs) => tap(Booking::factory()->create($attrs + ['customer_id' => $c->id, 'quoted_price' => 100]),
            fn ($b) => $b->forceFill(['created_at' => Carbon::parse($created)])->save());

        $make('2026-10-28 12:00', ['source' => 'web', 'meta' => ['marketing' => ['gclid' => 'x']]]);
        $make('2026-11-10 12:00', ['source' => 'web', 'meta' => ['heard_about' => 'tiktok']]);
        $make('2026-11-10 15:00', ['source' => 'phone', 'meta' => ['enquiry_source' => 'whatsapp', 'heard_about' => 'recommended']]);

        $admin = User::factory()->admin()->create();
        $page = $this->actingAs($admin)->get(route('reports.sources'))->assertOk();
        $page->assertSee('Bookings by source')->assertSee('Ads paused')
            ->assertSee('TikTok')->assertSee('Recommended by someone')->assertSee('Google Ads');
    }

    public function test_the_website_form_saves_how_they_heard(): void
    {
        $this->assertArrayHasKey('tiktok', Booking::HEARD_ABOUT);
        $html = $this->get(route('widget.book'))->getContent();
        $this->assertStringContainsString('How did you hear about us?', $html);
        $this->assertStringContainsString('name="heard_about"', $html);
    }
}
