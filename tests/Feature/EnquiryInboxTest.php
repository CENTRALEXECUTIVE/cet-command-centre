<?php

namespace Tests\Feature;

use App\Models\EmailEnquiry;
use App\Models\User;
use App\Services\Ai\AnthropicService;
use App\Services\Inbox\EnquiryInboxService;
use App\Services\Inbox\GraphMailClient;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnquiryInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function mockInbox(array $extracted): void
    {
        $this->mock(GraphMailClient::class, function ($m) {
            $m->shouldReceive('configured')->andReturn(true);
            $m->shouldReceive('fetchRecent')->andReturn([[
                'id' => 'msg-1', 'subject' => 'Quote please',
                'body' => 'Hi, need a car from Sheffield to Manchester Airport on 5 Aug at 10am for 2 people.',
                'from' => 'jane@example.com', 'from_name' => 'Jane Doe',
            ]]);
        });
        $this->mock(AnthropicService::class, function ($m) use ($extracted) {
            $m->shouldReceive('completeJson')->andReturn($extracted);
        });
    }

    public function test_ingesting_an_enquiry_creates_a_quote_and_draft_reply(): void
    {
        $this->mockInbox([
            'is_enquiry' => true, 'customer_name' => 'Jane Doe',
            'pickup' => 'Sheffield', 'destination' => 'Manchester Airport',
            'pickup_datetime' => '2026-08-05T10:00', 'passengers' => 2, 'vehicle' => 'executive',
        ]);

        $stats = app(EnquiryInboxService::class)->ingest();
        $this->assertEquals(1, $stats['created']);

        $enquiry = EmailEnquiry::first();
        $this->assertEquals('jane@example.com', $enquiry->from_email);
        $this->assertNotNull($enquiry->quote_amount); // Sheffield→MAN is a fixed price
        $this->assertStringContainsString('Central Executive Transfers', $enquiry->draft_reply);
        $this->assertStringContainsString('Manchester Airport', $enquiry->draft_reply);
        $this->assertEquals('quoted', $enquiry->status);
    }

    public function test_non_enquiry_emails_are_skipped(): void
    {
        $this->mockInbox(['is_enquiry' => false]);

        $stats = app(EnquiryInboxService::class)->ingest();
        $this->assertEquals(0, $stats['created']);
        $this->assertEquals(0, EmailEnquiry::count());
    }

    public function test_admin_can_edit_and_mark_replied(): void
    {
        $admin = User::factory()->admin()->create();
        $enquiry = EmailEnquiry::create([
            'from_email' => 'a@b.com', 'subject' => 'Hi', 'draft_reply' => 'Draft', 'status' => 'quoted',
        ]);

        $this->actingAs($admin)->put(route('enquiries.update', $enquiry), [
            'quote_amount' => 175, 'draft_reply' => 'Edited reply',
        ])->assertRedirect();
        $this->assertEquals('Edited reply', $enquiry->fresh()->draft_reply);
        $this->assertEquals('175.00', (string) $enquiry->fresh()->quote_amount);

        // Manual send marks it replied without needing Graph.
        $this->actingAs($admin)->post(route('enquiries.send', $enquiry), ['manual' => 1])->assertRedirect();
        $this->assertEquals('replied', $enquiry->fresh()->status);
        $this->assertNotNull($enquiry->fresh()->replied_at);
    }

    public function test_driver_cannot_access_enquiries(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('enquiries.index'))->assertForbidden();
    }
}
