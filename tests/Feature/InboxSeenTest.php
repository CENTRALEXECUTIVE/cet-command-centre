<?php

namespace Tests\Feature;

use App\Services\Ai\AnthropicService;
use App\Services\Inbox\EnquiryInboxService;
use App\Support\InboxSeen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The inbox scans run every few minutes over weeks of email. An email the AI
 * has already judged "not relevant" must not be sent to the (paid) AI again —
 * but an AI ERROR must never mark an email as checked, or a real enquiry could
 * be skipped for good.
 */
class InboxSeenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        InboxSeen::reset('enquiry', true);
    }

    protected function tearDown(): void
    {
        InboxSeen::reset('enquiry', true);
        parent::tearDown();
    }

    private function fakeMailbox(array $messages): void
    {
        $mail = \Mockery::mock(\App\Services\Inbox\GraphMailClient::class);
        $mail->shouldReceive('fetchRecent')->andReturn($messages);
        $this->app->instance(\App\Services\Inbox\GraphMailClient::class, $mail);
    }

    public function test_a_not_relevant_email_is_only_sent_to_the_ai_once(): void
    {
        $this->fakeMailbox([['id' => 'm1', 'subject' => 'Newsletter', 'body' => 'Big sale', 'from' => 'news@example.com']]);
        $ai = \Mockery::mock(AnthropicService::class);
        $ai->shouldReceive('configured')->andReturn(true);
        $ai->shouldReceive('completeJson')->once()->andReturn(['is_enquiry' => false]);
        $this->app->instance(AnthropicService::class, $ai);

        app(EnquiryInboxService::class)->ingest(14);
        InboxSeen::reset('enquiry'); // a fresh process reads the file again
        app(EnquiryInboxService::class)->ingest(14);
        app(EnquiryInboxService::class)->ingest(14);
    }

    public function test_an_ai_error_is_retried_next_scan(): void
    {
        $this->fakeMailbox([['id' => 'm2', 'subject' => 'Need a car', 'body' => 'Sheffield to MAN Friday', 'from' => 'a@example.com']]);
        $ai = \Mockery::mock(AnthropicService::class);
        $ai->shouldReceive('configured')->andReturn(true);
        $ai->shouldReceive('completeJson')->twice()->andReturn(null);
        $this->app->instance(AnthropicService::class, $ai);

        app(EnquiryInboxService::class)->ingest(14);
        app(EnquiryInboxService::class)->ingest(14);

        $this->assertFalse(InboxSeen::has('enquiry', 'm2'));
    }
}
