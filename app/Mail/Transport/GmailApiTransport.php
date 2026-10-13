<?php

namespace App\Mail\Transport;

use App\Services\Mail\GmailApiClient;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

/**
 * Laravel mail transport that sends through the Gmail API (HTTPS) for the PVT LTD
 * company — used instead of SMTP because the host blocks outbound SMTP. The whole
 * MIME message is handed to Gmail as-is, sent AS the PVT mailbox.
 */
class GmailApiTransport extends AbstractTransport
{
    public function __construct(private readonly GmailApiClient $gmail)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        // Send AS the message's From (the PVT address) when set, else the
        // configured PVT mailbox. Gmail sends it under that authorised mailbox.
        $from = $email->getFrom();
        $fromAddr = $from !== [] ? $from[0]->getAddress() : $this->gmail->sendAs();

        // The complete RFC822 message — Gmail keeps the headers, body and
        // attachments exactly as Laravel built them.
        $rfc822 = $email->toString();

        if (! $this->gmail->sendRaw($fromAddr, $rfc822)) {
            $why = $this->gmail->lastError ? ' ['.$this->gmail->lastError.']' : '';
            throw new \RuntimeException('Gmail API (PVT LTD) failed to send the message.'.$why);
        }
    }

    public function __toString(): string
    {
        return 'gmail';
    }
}
