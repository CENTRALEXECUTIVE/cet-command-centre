<?php

namespace Tests\Feature;

use App\Mail\BookingCancellationMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The office can preview, then send, a cancellation email to the customer — never
 * automatic. It confirms the cancellation and (when money was taken) the refund.
 */
class CancellationEmailTest extends TestCase
{
    use RefreshDatabase;

    private function cancelled(float $paid = 0, bool $withEmail = true): Booking
    {
        $this->seed(VehicleTypeSeeder::class);
        $customer = Customer::create(['name' => 'Lisa Shanley', 'phone' => '07700900900', 'email' => $withEmail ? 'lisa@example.com' : null]);

        $b = Booking::factory()->create([
            'reference' => 'CET-CANC1', 'customer_id' => $customer->id,
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'),
            'status' => 'cancelled', 'payment_method' => 'card',
            'payment_status' => $paid > 0 ? 'paid' : 'pending', 'quoted_price' => 115,
            'pickup_at' => now()->addDays(3), 'pickup_address' => 'Manchester Airport',
            'destination_address' => 'Sheffield S1 4LR',
        ]);
        if ($paid > 0) {
            $b->payments()->create(['method' => 'card', 'amount' => $paid, 'status' => 'paid', 'paid_at' => now(), 'meta' => ['name' => 'Fare']]);
            $b->load('payments');
        }

        return $b->fresh();
    }

    public function test_preview_renders_the_cancellation_email(): void
    {
        $booking = $this->cancelled(paid: 115);

        $res = $this->actingAs(User::factory()->admin()->create())
            ->get(route('bookings.cancellation-email.preview', $booking))->assertOk();

        $html = $res->getContent();
        $this->assertStringContainsString('has been cancelled', $html);
        $this->assertStringContainsString('Refund of £115.00', $html);

        // Save it so we can eyeball the design.
        $dir = '/tmp/claude-0/-home-user-cet-command-centre/a5de91ce-cd31-5f95-91c6-54603496e379/scratchpad/proto';
        @mkdir($dir, 0777, true);
        @file_put_contents($dir.'/cancellation-email.html', $html);
    }

    public function test_an_unpaid_cancellation_says_nothing_to_refund(): void
    {
        $booking = $this->cancelled(paid: 0);
        $html = (new BookingCancellationMail($booking))->render();
        $this->assertStringContainsString('nothing to refund', $html);
    }

    public function test_sending_emails_the_customer_and_stamps_it(): void
    {
        Mail::fake();
        $booking = $this->cancelled(paid: 115);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.cancellation-email.send', $booking))
            ->assertRedirect()->assertSessionHas('status');

        Mail::assertSent(BookingCancellationMail::class, fn ($m) => $m->booking->is($booking) && $m->hasTo('lisa@example.com'));
        $this->assertNotEmpty($booking->fresh()->meta['cancellation_email_sent'] ?? null);
    }

    public function test_send_needs_a_customer_email(): void
    {
        Mail::fake();
        $booking = $this->cancelled(paid: 0, withEmail: false);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('bookings.cancellation-email.send', $booking))
            ->assertRedirect()->assertSessionHas('error');
        Mail::assertNothingSent();
    }

    public function test_it_is_admin_only(): void
    {
        $booking = $this->cancelled();
        $this->actingAs(User::factory()->driver()->create())
            ->get(route('bookings.cancellation-email.preview', $booking))->assertForbidden();
    }
}
