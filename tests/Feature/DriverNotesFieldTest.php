<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin "Notes for the driver" field: office writes a brief, it shows on the
 * driver's job screen, changing it makes the driver re-confirm, and a number in
 * the notes is never shown to the driver.
 */
class DriverNotesFieldTest extends TestCase
{
    use RefreshDatabase;

    private function job(): Booking
    {
        $driver = User::factory()->driver()->create();

        return Booking::factory()->create([
            'driver_id' => $driver->id,
            'status' => BookingStatus::Allocated,
            'pickup_at' => now()->addHour(),
        ]);
    }

    public function test_an_admin_saves_notes_and_the_driver_sees_them(): void
    {
        $b = $this->job();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('bookings.driver-notes', $b), ['driver_notes' => 'Meet at the side entrance, ring the bell.'])
            ->assertRedirect();

        $b->refresh();
        $this->assertSame('Meet at the side entrance, ring the bell.', $b->driverNotes());

        $this->actingAs($b->driver)->get(route('driver.job', $b))->assertOk()
            ->assertSee('Meet at the side entrance', false);
    }

    public function test_changing_the_notes_clears_the_read_acknowledgement(): void
    {
        $b = $this->job();
        $b->setDriverNotes('First version');
        $b->confirmDriverNotesRead($b->driver);
        $this->assertTrue($b->fresh()->driverNotesAcknowledged());

        $b->fresh()->setDriverNotes('Updated — different meeting point');
        $this->assertFalse($b->fresh()->driverNotesAcknowledged());
    }

    public function test_the_same_text_keeps_the_acknowledgement(): void
    {
        $b = $this->job();
        $b->setDriverNotes('Same note');
        $b->confirmDriverNotesRead($b->driver);

        $b->fresh()->setDriverNotes('Same note');
        $this->assertTrue($b->fresh()->driverNotesAcknowledged());
    }

    public function test_a_driver_cannot_edit_the_notes(): void
    {
        $b = $this->job();

        $this->actingAs($b->driver)
            ->post(route('bookings.driver-notes', $b), ['driver_notes' => 'hacked'])
            ->assertForbidden();
    }

    public function test_a_link_in_the_notes_is_clickable_on_the_driver_screen(): void
    {
        $b = $this->job();
        $b->setDriverNotes('Account job — job card: https://cdserver2.com/JobCard.aspx?HIRE_ID=8791cc66');

        $this->actingAs($b->driver)->get(route('driver.job', $b))->assertOk()
            ->assertSee('<a href="https://cdserver2.com/JobCard.aspx?HIRE_ID=8791cc66"', false);
    }

    public function test_a_number_in_the_notes_is_never_shown_to_the_driver(): void
    {
        $b = $this->job();
        $b->setDriverNotes('Call the customer on 07123456789 when close.');

        $this->actingAs($b->driver)->get(route('driver.job', $b))->assertOk()
            ->assertDontSee('07123456789');
    }
}
