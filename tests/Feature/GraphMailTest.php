<?php

namespace Tests\Feature;

use App\Mail\Transport\GraphTransport;
use App\Models\Setting;
use App\Services\Inbox\GraphMailClient;
use App\Support\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Microsoft 365 sending: pick "Microsoft 365" and email goes out through the
 * connected Graph mailbox (your own domain, no branding) instead of SMTP.
 */
class GraphMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_choosing_microsoft_365_switches_the_mailer_to_graph(): void
    {
        Setting::set('mail_transport', 'graph', 'string', 'mail');

        MailSettings::apply();

        $this->assertSame('graph', config('mail.default'));
        $this->assertSame('graph', config('mail.mailers.graph.transport'));
    }

    public function test_graph_transport_sends_through_the_graph_client(): void
    {
        $graph = Mockery::mock(GraphMailClient::class);
        $graph->shouldReceive('sendMail')->once()
            ->withArgs(fn ($to, $subject, $html) => $to === ['to@y.com'] && $subject === 'Hi' && str_contains($html, 'Body'))
            ->andReturn(true);

        $email = (new Email())->from('from@x.com')->to('to@y.com')->subject('Hi')->html('<p>Body</p>');

        (new GraphTransport($graph))->send($email); // no exception = sent

        $this->assertTrue(true);
    }

    public function test_graph_transport_throws_when_the_send_fails(): void
    {
        $graph = Mockery::mock(GraphMailClient::class);
        $graph->shouldReceive('sendMail')->once()->andReturn(false);

        $email = (new Email())->from('from@x.com')->to('to@y.com')->subject('Hi')->html('<p>Body</p>');

        $this->expectException(\Throwable::class);
        (new GraphTransport($graph))->send($email);
    }
}
