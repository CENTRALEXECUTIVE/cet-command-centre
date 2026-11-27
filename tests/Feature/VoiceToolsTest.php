<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PhoneCall;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Realtime voice (ElevenLabs) tools + post-call webhook for the AI phone agent. */
class VoiceToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cet.webhook_secret' => 'sek', 'services.elevenlabs.webhook_secret' => 'elsek']);
        VehicleType::firstOrCreate(['slug' => 'executive'], ['name' => 'Executive', 'passenger_capacity' => 4, 'luggage_capacity' => 2, 'is_active' => true, 'sort_order' => 1]);
    }

    public function test_tools_need_the_secret(): void
    {
        $this->postJson(route('webhooks.voice-tools.time'))->assertForbidden();
        $this->postJson(route('webhooks.voice-tools.time', ['secret' => 'sek']))->assertOk()->assertJsonStructure(['today', 'date', 'uk_time']);
    }

    public function test_quote_uses_the_price_engine_and_logs_the_call(): void
    {
        $r = $this->postJson(route('webhooks.voice-tools.quote', ['secret' => 'sek']), [
            'pickup' => 'S1 2HE', 'destination' => 'Manchester Airport M90 1QX', 'pickup_date_time' => '2026-12-01 06:00',
            'vehicle' => 'executive', 'caller_id' => '+447700900222', 'conversation_id' => 'conv1',
        ])->assertOk();
        $this->assertTrue($r->json('total_gbp') !== null || $r->json('on_request') === true);
        $call = PhoneCall::where('call_sid', 'el-conv1')->first();
        $this->assertSame('+447700900222', $call->from_number);
        $this->assertCount(1, $call->quotes);
    }

    public function test_booking_request_alerts_and_waits_for_the_office(): void
    {
        User::factory()->create(['role' => UserRole::Admin, 'is_super_admin' => true]);
        $this->postJson(route('webhooks.voice-tools.booking', ['secret' => 'sek']), [
            'lead_name' => 'Jane Smith', 'contact_no' => '07700900222', 'pickup_at' => '2026-12-01 06:00',
            'pickup_address' => 'S1 2HE', 'destination_address' => 'Manchester Airport', 'vehicle' => 'executive', 'conversation_id' => 'conv2',
        ])->assertOk()->assertJson(['saved' => true]);
        $call = PhoneCall::where('call_sid', 'el-conv2')->first();
        $this->assertTrue($call->needs_action);
        $this->assertSame('Jane Smith', $call->booking_fields['lead_name']);
        $this->assertSame(0, \App\Models\Booking::count());
        $this->assertDatabaseHas('watchdog_events', ['event_type' => 'ai_call']);
    }

    public function test_post_call_webhook_stores_transcript_with_a_valid_signature_only(): void
    {
        $body = json_encode(['type' => 'post_call_transcription', 'data' => [
            'conversation_id' => 'conv3',
            'transcript' => [['role' => 'agent', 'message' => 'Central Executive Transfers, how can I help?'], ['role' => 'user', 'message' => 'Just checking your hours']],
            'metadata' => ['start_time_unix_secs' => time() - 90, 'call_duration_secs' => 60],
            'analysis' => ['transcript_summary' => 'Caller asked about opening hours.'],
            'conversation_initiation_client_data' => ['dynamic_variables' => ['system__caller_id' => '+447700900333']],
        ]]);
        $this->call('POST', route('webhooks.elevenlabs'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ElevenLabs-Signature' => 't='.time().',v0=bad'], $body)->assertForbidden();

        $t = time();
        $sig = 't='.$t.',v0='.hash_hmac('sha256', $t.'.'.$body, 'elsek');
        $this->call('POST', route('webhooks.elevenlabs'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ElevenLabs-Signature' => $sig], $body)->assertOk();

        $call = PhoneCall::where('call_sid', 'el-conv3')->first();
        $this->assertSame('+447700900333', $call->from_number);
        $this->assertSame('caller', $call->transcript[1]['role']);
        $this->assertStringContainsString('opening hours', $call->summary);
        $this->assertNotNull($call->ended_at);
    }

    public function test_setup_page_is_directors_only_and_carries_the_prompt(): void
    {
        $director = User::factory()->create(['role' => UserRole::Admin, 'is_super_admin' => true]);
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_super_admin' => false]);
        $this->actingAs($admin)->get(route('phone-calls.setup'))->assertForbidden();
        $this->actingAs($director)->get(route('phone-calls.setup'))->assertOk()
            ->assertSee('current_time')->assertSee('save_booking_request')->assertSee('NEVER say a price', false)
            ->assertSee('system__caller_id');
    }

    // 27 Nov 2026 standard: phone requests are critical, emailed to the office with every
    // detail, and nothing the caller said is lost on the way into Paste a booking.
    public function test_phone_booking_is_critical_emailed_and_keeps_every_detail(): void
    {
        config(['cet.ops_email' => 'admin@example.com']);
        $sent = [];
        \Illuminate\Support\Facades\Mail::shouldReceive('raw')->andReturnUsing(function ($body, $cb) use (&$sent) {
            $sent[] = $body;
        });
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_super_admin' => true]);
        $this->postJson(route('webhooks.voice-tools.booking', ['secret' => 'sek']), [
            'lead_name' => 'Bill Example', 'contact_no' => '07700900222', 'pickup_at' => '2026-12-07 11:10',
            'pickup_address' => 'Manchester Airport T2', 'destination_address' => 'Bessemer Park, Sheffield S9 1DZ', 'vehicle' => 'executive',
            'flight_landing_at' => '2026-12-07 10:40', 'return_pickup_at' => '2026-12-08 15:00', 'child_seats' => 1,
            'vat_invoice' => true, 'company_name' => 'Example Ltd', 'company_vat_number' => 'GB123', 'company_address' => '1 Test St',
            'conversation_id' => 'conv9',
        ])->assertOk();
        app()->terminate();

        $this->assertDatabaseHas('watchdog_events', ['event_type' => 'ai_call', 'severity' => 'critical']);
        $this->assertNotEmpty($sent);
        foreach (['Bill Example', '2026-12-08 15:00', 'GB123', 'Example Ltd', '2026-12-07 10:40'] as $want) {
            $this->assertStringContainsString($want, $sent[0]);
        }

        $call = PhoneCall::where('call_sid', 'el-conv9')->first();
        $notes = $call->intakeFields()['notes'];
        foreach (['RETURN JOURNEY WANTED', 'Return pickup time: 2026-12-08 15:00', 'Flight lands: 2026-12-07 10:40', 'Child seats: 1', 'VAT number: GB123', 'Company: Example Ltd'] as $want) {
            $this->assertStringContainsString($want, $notes);
        }
        $this->actingAs($admin)->get(route('phone-calls.show', $call))->assertOk()
            ->assertSee('Return pickup time')->assertSee('GB123')->assertSee('Flight lands');
    }
}
