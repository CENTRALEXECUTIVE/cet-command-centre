<?php

namespace App\Mail\Transport;

use App\Services\Mail\ResendClient;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

/**
 * Laravel mail transport that sends through Resend's HTTPS API for the PVT LTD
 * company — used because the host blocks SMTP and it needs no Google Cloud setup.
 */
class ResendTransport extends AbstractTransport
{
    public function __construct(private readonly ResendClient $resend)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $from = $email->getFrom();
        $fromAddr = $from !== [] ? $from[0]->getAddress() : '';
        $fromName = $from !== [] ? $from[0]->getName() : '';
        $fromHeader = $fromName !== '' ? $fromName.' <'.$fromAddr.'>' : $fromAddr;

        $to = array_map(fn ($a) => $a->getAddress(), $email->getTo());
        $cc = array_map(fn ($a) => $a->getAddress(), $email->getCc());
        $replyTo = $email->getReplyTo();
        $replyToAddr = $replyTo !== [] ? $replyTo[0]->getAddress() : null;

        $html = $email->getHtmlBody();
        if ($html === null) {
            $text = (string) $email->getTextBody();
            $html = $text !== '' ? nl2br(e($text)) : '&nbsp;';
        }

        $attachments = [];
        foreach ($email->getAttachments() as $att) {
            $attachments[] = [
                'filename' => $att->getFilename() ?? 'attachment',
                'content' => base64_encode($att->getBody()),
            ];
        }

        if (! $this->resend->send($fromHeader, $to, (string) $email->getSubject(), (string) $html, $cc, $replyToAddr, $attachments)) {
            $why = $this->resend->lastError ? ' ['.$this->resend->lastError.']' : '';
            throw new \RuntimeException('Resend (PVT LTD) failed to send the message.'.$why);
        }
    }

    public function __toString(): string
    {
        return 'resend';
    }
}
