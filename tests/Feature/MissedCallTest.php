<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MissedCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MissedCallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cet.webhook_secret' => 'test-secret']);
    }

    public function test_missed_call_is_recorded_and_alerts_the_office_without_messaging_the_caller(): void
    {
        $response = $this->postJson(route('webhooks.missed-call'), [
            'secret' => 'test-secret',
            'From' => '07700900456',
        ]);

        $response->assertOk()->assertJson(['handled' => true, 'auto_response_sent' => false]);

        $this->assertDatabaseHas('missed_calls', ['from_number' => '07700900456']);
        // Nothing auto-sends to customers (CLAUDE.md rule 2) — the office gets an
        // alert with a ready-written WhatsApp to tap instead.
        $this->assertDatabaseMissing('messages', ['to_address' => '07700900456']);
        $this->assertTrue(\App\Models\WatchdogEvent::where('event_type', 'missed_call')->exists());
    }

    public function test_existing_customer_is_linked_to_the_missed_call(): void
    {
        $customer = Customer::factory()->create(['phone' => '07700900456']);

        $this->postJson(route('webhooks.missed-call'), [
            'secret' => 'test-secret',
            'From' => '07700900456',
        ])->assertOk();

        $this->assertEquals($customer->id, MissedCall::first()->customer_id);
    }

    public function test_webhook_rejects_an_invalid_secret(): void
    {
        $this->postJson(route('webhooks.missed-call'), [
            'secret' => 'wrong',
            'From' => '07700900456',
        ])->assertForbidden();

        $this->assertEquals(0, MissedCall::count());
    }
}
