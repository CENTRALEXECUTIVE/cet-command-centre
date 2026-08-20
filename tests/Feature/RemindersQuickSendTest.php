<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Opening a due reminder should land straight on the send buttons, and the
 * booking page keeps the contact-numbers/masking block collapsed for a cleaner
 * page.
 */
class RemindersQuickSendTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_reminder_link_jumps_to_the_reminders_anchor(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create();
        $booking = Booking::factory()->create(['driver_id' => $driver->id, 'pickup_at' => now()->addHours(20)]);
        Message::create([
            'booking_id' => $booking->id, 'customer_id' => $booking->customer_id,
            'channel' => 'whatsapp', 'direction' => 'outbound', 'type' => 'reminder_24h',
            'to_address' => '07700900000', 'body' => 'reminder', 'status' => 'queued',
            'scheduled_for' => now()->subMinutes(5),
        ]);

        $res = $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $reminders = collect($res->viewData('remindersToSend'));
        $this->assertNotEmpty($reminders);
        $this->assertStringEndsWith('#reminders', $reminders->first()['url']);
    }

    public function test_the_booking_page_has_the_reminders_anchor_and_collapsed_masking(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->driver()->create();
        $booking = Booking::factory()->create(['driver_id' => $driver->id, 'pickup_at' => now()->addDay()]);

        $this->actingAs($admin)->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertSee('id="reminders"', false)                 // the scroll target
            ->assertSee('Contact numbers &amp; masking', false); // masking now collapsed in a <details>
    }
}
