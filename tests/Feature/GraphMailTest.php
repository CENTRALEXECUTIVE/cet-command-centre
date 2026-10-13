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

    public function test_send_goes_through_the_real_mailbox_but_keeps_the_from(): void
    {
        // The real licensed mailbox that the app can open.
        config([
            'services.microsoft_graph.client_id' => 'id',
            'services.microsoft_graph.client_secret' => 'secret',
            'services.microsoft_graph.tenant_id' => 'tenant',
            'services.microsoft_graph.mailbox' => 'admin@centralexecutivetransfers.co.uk',
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'login.microsoftonline.com/*' => \Illuminate\Support\Facades\Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => \Illuminate\Support\Facades\Http::response([], 202),
        ]);

        // Send "as" bookings@ (an alias on the SAME domain as the real mailbox).
        app(GraphMailClient::class)->sendMail(
            to: ['customer@x.com'], subject: 'Hi', html: '<p>Body</p>',
            fromMailbox: 'bookings@centralexecutivetransfers.co.uk',
        );

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/sendMail')) {
                return false;
            }
            // The API path is the REAL mailbox (not the alias) — this is the fix.
            $pathOk = str_contains(rawurldecode($request->url()), '/users/admin@centralexecutivetransfers.co.uk/sendMail');
            // ...but the visible From is still bookings@.
            $fromOk = data_get($request->data(), 'message.from.emailAddress.address') === 'bookings@centralexecutivetransfers.co.uk';

            return $pathOk && $fromOk;
        });
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
