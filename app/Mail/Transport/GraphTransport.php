<?php

namespace App\Mail\Transport;

use App\Services\Inbox\GraphMailClient;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

/**
 * Laravel mail transport that sends through Microsoft 365 (Graph sendMail) using
 * the SAME connection that already reads the ETO mailbox. Mail goes out as your
 * own M365 mailbox/domain — reliable deliverability, no third-party branding, and
 * it rides HTTPS (which GoDaddy doesn't block), sidestepping the SMTP problems.
 */
class GraphTransport extends AbstractTransport
{
    public function __construct(private readonly GraphMailClient $graph)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $to = array_map(fn ($a) => $a->getAddress(), $email->getTo());
        $cc = array_map(fn ($a) => $a->getAddress(), $email->getCc());
        $replyTo = $email->getReplyTo();
        $replyToAddr = $replyTo !== [] ? $replyTo[0]->getAddress() : null;
        // The message's own From drives which company mailbox it's sent from.
        $from = $email->getFrom();
        $fromMailbox = $from !== [] ? $from[0]->getAddress() : null;

        // Prefer the HTML body; fall back to the text body (e.g. the plain test
        // email) rendered as simple HTML.
        $html = $email->getHtmlBody();
        if ($html === null) {
            $text = (string) $email->getTextBody();
            $html = $text !== '' ? nl2br(e($text)) : '&nbsp;';
        }

        $attachments = [];
        foreach ($email->getAttachments() as $att) {
            $attachments[] = [
                'name' => $att->getFilename() ?? 'attachment',
                'contentType' => $att->getContentType() ?? 'application/octet-stream',
                'contentBytes' => base64_encode($att->getBody()),
            ];
        }

        $ok = $this->graph->sendMail(
            to: $to,
            subject: (string) $email->getSubject(),
            html: (string) $html,
            cc: $cc,
            replyTo: $replyToAddr,
            attachments: $attachments,
            fromMailbox: $fromMailbox,
        );

        if (! $ok) {
            throw new \RuntimeException('Microsoft 365 (Graph) refused or failed to send the message — check the mailbox connection and that the app has the Mail.Send permission.');
        }
    }

    public function __toString(): string
    {
        return 'graph';
    }
}
