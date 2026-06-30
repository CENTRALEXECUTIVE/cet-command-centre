<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CalendarEvent;
use App\Models\Customer;
use App\Models\VehicleType;
use App\Services\Calendar\GoogleCalendarService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CalendarDedupeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);

        // Fake service-account credentials with a real RSA key so JWT signing works.
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);
        config(['services.google_calendar.credentials' => json_encode([
            'client_email' => 'svc@proj.iam.gserviceaccount.com',
            'private_key' => $privateKey,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ])]);

        // This suite exercises real calendar writes, so lift the default pause.
        \App\Models\Setting::set('calendar_paused', false, 'bool', 'calendar');
    }

    private function makeEvent(string $reference): CalendarEvent
    {
        $vt = VehicleType::where('slug', 'executive')->first();
        $customer = Customer::create(['name' => 'Jane Doe', 'phone' => '07000000000']);
        $booking = Booking::create([
            'reference' => Booking::generateReference(),
            'external_reference' => $reference,
            'source_system' => 'eto',
            'customer_id' => $customer->id,
            'vehicle_type_id' => $vt->id,
            'pickup_at' => now()->addDay(),
            'pickup_address' => 'A',
            'destination_address' => 'B',
            'passengers' => 1,
            'status' => 'pending',
            'payment_method' => 'card',
        ]);

        return CalendarEvent::create([
            'booking_id' => $booking->id,
            'calendar_id' => 'admin@centralexecutivetransfers.co.uk',
            'title' => "*Jane Doe MAN (ABDI)*",
            'description' => "📑 *Booking Confirmation – Transfer*\n• *Booking Reference:* {$reference}",
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'timezone' => 'Europe/London',
            'sync_status' => 'pending',
        ]);
    }

    public function test_push_updates_existing_event_with_same_reference_instead_of_duplicating(): void
    {
        $existingId = 'existing-event-id-123';

        Http::fake(function ($request) use ($existingId) {
            $url = $request->url();
            $method = $request->method();

            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200);
            }
            // Search by reference → returns the event already on the calendar.
            if ($method === 'GET' && str_contains($url, '/events?')) {
                return Http::response(['items' => [[
                    'id' => $existingId,
                    'description' => "📑 Booking Confirmation\n• Booking Reference: ABC123",
                ]]], 200);
            }
            // Confirm read-back + PUT update both succeed.
            return Http::response(['id' => $existingId, 'status' => 'confirmed'], 200);
        });

        $event = $this->makeEvent('ABC123');
        $ok = app(GoogleCalendarService::class)->push($event);

        $this->assertTrue($ok);
        // It adopted the existing id and UPDATED it...
        $this->assertEquals($existingId, $event->fresh()->google_event_id);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/events/'.$existingId));
        // ...and never created a second copy.
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/calendar/v3/'));
    }

    public function test_push_does_nothing_while_calendar_is_paused(): void
    {
        \App\Models\Setting::set('calendar_paused', true, 'bool', 'calendar');
        Http::fake(); // any HTTP call would be a failure

        $event = $this->makeEvent('PAUSED1');
        $ok = app(GoogleCalendarService::class)->push($event);

        $this->assertFalse($ok);
        $this->assertNull($event->fresh()->google_event_id);
        Http::assertNothingSent();
    }

    public function test_push_creates_when_no_matching_event_exists(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            $method = $request->method();

            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200);
            }
            // Search returns nothing → must create.
            if ($method === 'GET' && str_contains($url, '/events?')) {
                return Http::response(['items' => []], 200);
            }
            // POST create + confirm read-back.
            return Http::response(['id' => 'new-event-id', 'status' => 'confirmed'], 200);
        });

        $event = $this->makeEvent('NEW999');
        $ok = app(GoogleCalendarService::class)->push($event);

        $this->assertTrue($ok);
        $this->assertEquals('new-event-id', $event->fresh()->google_event_id);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/calendar/v3/') && str_contains($r->url(), '/events'));
    }
}
