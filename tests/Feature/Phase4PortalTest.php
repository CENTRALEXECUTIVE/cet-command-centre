<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Mail\InvoiceMail;
use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\Message;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\BookingService;
use App\Services\BookingStatusService;
use App\Services\Payments\InvoiceService;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\RotationSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class Phase4PortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class, RotationSeeder::class]);
    }

    public function test_corporate_client_sees_only_their_account_statement(): void
    {
        $account = CorporateAccount::create(['name' => 'JELD-WEN', 'slug' => 'jw', 'account_code' => 'JW', 'is_active' => true]);
        $other = CorporateAccount::create(['name' => 'LB Foster', 'slug' => 'lb', 'account_code' => 'LB', 'is_active' => true]);

        $mine = Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create(['corporate_account_id' => $account->id]);
        $theirs = Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())
            ->create(['corporate_account_id' => $other->id]);

        $client = User::factory()->corporateClient()->create();
        $client->corporateAccounts()->attach($account->id);

        $this->actingAs($client)->get(route('corporate.statement'))
            ->assertOk()
            ->assertSee($mine->reference)
            ->assertDontSee($theirs->reference);
    }

    public function test_account_invoice_is_emailed_on_generation(): void
    {
        Mail::fake();

        $account = CorporateAccount::create([
            'name' => 'JELD-WEN', 'slug' => 'jw', 'account_code' => 'JW',
            'is_active' => true, 'billing_email' => 'accounts@jeld-wen.example', 'payment_terms_days' => 30,
        ]);

        Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create([
            'corporate_account_id' => $account->id,
            'payment_method' => 'account',
            'status' => BookingStatus::Complete->value,
            'final_price' => 100,
            'pickup_at' => now()->subDays(3),
        ]);

        $invoice = app(InvoiceService::class)->generateForAccount($account, now()->subWeek(), now());

        Mail::assertSent(InvoiceMail::class);
        $this->assertNotNull($invoice->fresh()->emailed_at);
    }

    public function test_review_request_is_scheduled_30_minutes_after_completion(): void
    {
        // Freeze at a daytime moment so the window-clamp doesn't shift the review.
        \Illuminate\Support\Carbon::setTestNow('2026-07-15 12:00:00');

        $admin = User::factory()->admin()->create();

        // Build a booking with a customer phone, drive it to collected, then complete.
        $booking = $this->completeJourney($admin, 'James Watson', '07700900123');

        $review = Message::where('booking_id', $booking->id)->where('type', 'review_request')->first();
        $this->assertNotNull($review);
        $this->assertEquals('queued', $review->status);
        $this->assertTrue($review->scheduled_for->greaterThan(now()->addMinutes(25)));

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_review_request_uses_the_office_wording_and_google_link(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-07-15 12:00:00');
        config(['cet.review_url' => 'https://g.page/r/CYo2748zMiu5EBM/review']);

        $admin = User::factory()->admin()->create();
        // Lead passenger "Lewis Carter" — greeting uses the first name.
        $booking = $this->completeJourney($admin, 'Lewis Carter', '07700900456');

        $review = Message::where('booking_id', $booking->id)->where('type', 'review_request')->first();
        $this->assertStringContainsString("Hi Lewis,\n\nWe hope you had a smooth journey with *Central Executive Transfers*!", $review->body);
        $this->assertStringContainsString('👉 Google: https://g.page/r/CYo2748zMiu5EBM/review', $review->body);
        $this->assertStringContainsString("*Central Executive Transfers*\nwww.centralexecutivetransfers.co.uk", $review->body);
        $this->assertStringNotContainsString('🌐', $review->body);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_review_request_is_sent_by_hand_not_auto_delivered(): void
    {
        // A completed job at night queues the review for the morning window, and
        // the scheduler must NOT deliver it — the office sends it from WhatsApp.
        \Illuminate\Support\Carbon::setTestNow('2026-07-15 12:00:00');

        $admin = User::factory()->admin()->create();
        $booking = $this->completeJourney($admin, 'Priya Shah', '07700900789');

        $review = Message::where('booking_id', $booking->id)->where('type', 'review_request')->first();
        $this->assertEquals('queued', $review->status);

        // Roll past its due time and run the scheduler — it stays queued (manual).
        \Illuminate\Support\Carbon::setTestNow('2026-07-15 13:00:00');
        $this->artisan('cet:send-due-messages')->assertSuccessful();

        $this->assertEquals('queued', $review->fresh()->status);
        $this->assertNull($review->fresh()->sent_at);
        // And it's now offered as a manual "Send on WhatsApp" prompt.
        $this->assertTrue($review->fresh()->isReadyToSend());

        \Illuminate\Support\Carbon::setTestNow();
    }

    private function completeJourney(User $admin, string $customerName, string $phone): Booking
    {
        $booking = app(BookingService::class)->createFromForm([
            'customer_name' => $customerName, 'customer_phone' => $phone,
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'),
            'journey_type' => 'one_way', 'pickup_at' => now()->addDay()->format('Y-m-d H:i'),
            'pickup_address' => 'Sheffield', 'destination_address' => 'MAN', 'passengers' => 1,
            'payment_method' => 'cash', 'privacy_consent' => '1',
        ], $admin);

        $status = app(BookingStatusService::class);
        foreach ([BookingStatus::Allocated, BookingStatus::Accepted, BookingStatus::EnRoute,
            BookingStatus::Arrived, BookingStatus::Collected, BookingStatus::Complete] as $to) {
            $status->transition($booking->fresh(), $to, $admin);
        }

        return $booking->fresh();
    }
}
