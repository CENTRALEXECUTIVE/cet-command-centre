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
        $abdi = User::where('email', 'abdi@centralexecutivetransfers.co.uk')->first();
        $admin = User::factory()->admin()->create();

        // Build a booking with a customer phone, drive it to collected, then complete.
        $booking = app(BookingService::class)->createFromForm([
            'customer_name' => 'James Watson', 'customer_phone' => '07700900123',
            'vehicle_type_id' => VehicleType::where('slug', 'executive')->value('id'),
            'journey_type' => 'one_way', 'pickup_at' => now()->addDay()->format('Y-m-d H:i'),
            'pickup_address' => 'Sheffield', 'destination_address' => 'MAN', 'passengers' => 1,
            'payment_method' => 'cash', 'privacy_consent' => '1',
        ], $admin);

        $status = app(BookingStatusService::class);
        $status->transition($booking, BookingStatus::Allocated, $admin);
        $status->transition($booking->fresh(), BookingStatus::Accepted, $admin);
        $status->transition($booking->fresh(), BookingStatus::EnRoute, $admin);
        $status->transition($booking->fresh(), BookingStatus::Collected, $admin);
        $status->transition($booking->fresh(), BookingStatus::Complete, $admin);

        $review = Message::where('booking_id', $booking->id)->where('type', 'review_request')->first();
        $this->assertNotNull($review);
        $this->assertEquals('queued', $review->status);
        $this->assertTrue($review->scheduled_for->greaterThan(now()->addMinutes(25)));
    }
}
