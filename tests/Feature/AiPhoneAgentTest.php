<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PhoneCall;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** AI phone agent (directors, 23 Nov 2026): Twilio webhooks + Claude tools + office review. */
class AiPhoneAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cet.webhook_secret' => 'sek', 'services.anthropic.key' => 'test-key', 'cet.ai_voice.transfer' => '+447700900111']);
        VehicleType::firstOrCreate(['slug' => 'executive'], ['name' => 'Executive', 'passenger_capacity' => 4, 'luggage_capacity' => 2, 'is_active' => true, 'sort_order' => 1]);
    }

    private function post_(string $route, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route($route, ['secret' => 'sek']), $data);
    }

    private function text(string $t): array
    {
        return ['content' => [['type' => 'text', 'text' => $t]], 'stop_reason' => 'end_turn'];
    }

    private function tool(string $name, array $input, string $text = ''): array
    {
        $c = $text ? [['type' => 'text', 'text' => $text]] : [];
        $c[] = ['type' => 'tool_use', 'id' => 'tu_'.uniqid(), 'name' => $name, 'input' => $input];

        return ['content' => $c, 'stop_reason' => 'tool_use'];
    }

    public function test_incoming_call_greets_and_listens(): void
    {
        $r = $this->post_('webhooks.ai-voice', ['CallSid' => 'CA1', 'From' => '+447700900222']);
        $r->assertOk();
        $this->assertStringStartsWith('text/xml', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('<Gather input="speech"', $r->getContent());
        $this->assertStringContainsString('Central Executive Transfers', $r->getContent());
        $this->assertStringContainsString('virtual assistant', $r->getContent());
        $this->assertDatabaseHas('phone_calls', ['call_sid' => 'CA1', 'from_number' => '+447700900222']);
    }

    public function test_webhook_needs_the_secret(): void
    {
        $this->post(route('webhooks.ai-voice'), ['CallSid' => 'CA1'])->assertForbidden();
    }

    public function test_a_quote_comes_from_the_price_engine_not_the_model(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->tool('get_quote', ['pickup' => 'S1 2HE', 'destination' => 'Manchester Airport M90 1QX', 'pickup_date_time' => '2026-12-01 06:00', 'vehicle' => 'executive']))
            ->push($this->text('That journey is one hundred and fifteen pounds.'))]);
        $this->post_('webhooks.ai-voice', ['CallSid' => 'CA2', 'From' => '+447700900222']);
        $r = $this->post_('webhooks.ai-voice.turn', ['CallSid' => 'CA2', 'SpeechResult' => 'How much from S1 2HE to Manchester Airport on the first of December at 6am?']);

        $r->assertOk();
        $this->assertStringContainsString('<Gather', $r->getContent());
        $call = PhoneCall::where('call_sid', 'CA2')->first();
        $this->assertCount(1, $call->quotes);
        $this->assertArrayHasKey('pickup', $call->quotes[0]);
        $this->assertTrue(isset($call->quotes[0]['total_gbp']) || ! empty($call->quotes[0]['on_request']));
        $this->assertSame('caller', $call->transcript[1]['role']);
    }

    public function test_booking_request_is_saved_for_the_office_and_never_booked(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_super_admin' => true]);
        Http::fake(['*' => Http::sequence()
            ->push($this->tool('save_booking_request', ['lead_name' => 'Jane Smith', 'contact_no' => '07700900222', 'pickup_at' => '2026-12-01 06:00',
                'pickup_address' => 'S1 2HE', 'destination_address' => 'Manchester Airport', 'vehicle' => 'executive', 'passengers' => 2, 'price' => 115]))
            ->push($this->text('Thank you. The office will confirm your booking shortly.'))]);
        $this->post_('webhooks.ai-voice', ['CallSid' => 'CA3', 'From' => '+447700900222']);
        $this->post_('webhooks.ai-voice.turn', ['CallSid' => 'CA3', 'SpeechResult' => 'Yes that is all correct']);
        $this->post_('webhooks.ai-voice.status', ['CallSid' => 'CA3', 'CallStatus' => 'completed'])->assertOk();

        $call = PhoneCall::where('call_sid', 'CA3')->first();
        $this->assertTrue($call->needs_action);
        $this->assertSame('Jane Smith', $call->booking_fields['lead_name']);
        $this->assertStringContainsString('Booking request', $call->summary);
        $this->assertSame(0, \App\Models\Booking::count());

        $this->actingAs($admin)->get(route('phone-calls.index'))->assertOk()->assertSee('Booking request');
        $this->actingAs($admin)->get(route('phone-calls.show', $call))->assertOk()
            ->assertSee('Review and confirm booking')->assertSee('name="fields[lead_name]" value="Jane Smith"', false);
    }

    public function test_when_the_ai_is_down_the_caller_is_put_through(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);
        $this->post_('webhooks.ai-voice', ['CallSid' => 'CA4', 'From' => '+447700900222']);
        $r = $this->post_('webhooks.ai-voice.turn', ['CallSid' => 'CA4', 'SpeechResult' => 'Hello']);

        $this->assertStringContainsString('<Dial', $r->getContent());
        $this->assertStringContainsString('+447700900111', $r->getContent());
        $this->assertSame('transferred', PhoneCall::where('call_sid', 'CA4')->value('status'));
    }

    public function test_transfer_tool_dials_and_unanswered_goes_back_to_a_message(): void
    {
        Http::fake(['*' => Http::response($this->tool('transfer_to_person', ['reason' => 'complaint'], 'Putting you through now.'))]);
        $this->post_('webhooks.ai-voice', ['CallSid' => 'CA5', 'From' => '+447700900222']);
        $r = $this->post_('webhooks.ai-voice.turn', ['CallSid' => 'CA5', 'SpeechResult' => 'I want to make a complaint']);
        $this->assertStringContainsString('<Dial', $r->getContent());

        $r = $this->post_('webhooks.ai-voice.dial-done', ['CallSid' => 'CA5', 'DialCallStatus' => 'no-answer']);
        $this->assertStringContainsString('take a message', $r->getContent());
        $this->assertStringContainsString('<Gather', $r->getContent());
    }

    public function test_two_silences_end_the_call_politely(): void
    {
        $this->post_('webhooks.ai-voice', ['CallSid' => 'CA6']);
        $first = $this->post_('webhooks.ai-voice.turn', ['CallSid' => 'CA6', 'SpeechResult' => '']);
        $this->assertStringContainsString('<Gather', $first->getContent());
        $second = $this->post_('webhooks.ai-voice.turn', ['CallSid' => 'CA6', 'SpeechResult' => '']);
        $this->assertStringContainsString('<Hangup/>', $second->getContent());
    }

    public function test_prices_are_never_allowed_from_memory_in_the_prompt(): void
    {
        $call = PhoneCall::create(['call_sid' => 'CA7', 'from_number' => '+447700900222']);
        $system = app(\App\Services\Voice\PhoneAgent::class)->system($call);
        $this->assertStringContainsString('NEVER say a price you did not get from the get_quote tool', $system);
        $this->assertStringContainsString('NEVER say the booking is confirmed yourself', $system);
    }
}
