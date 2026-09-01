<?php

namespace App\Http\Controllers;

use App\Mail\CustomerMessageMail;
use App\Models\Booking;
use App\Models\Message;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Admin-side customer comms: send a one-off message against a booking and resend
 * a queued/failed one. Delivery goes through WhatsAppService, which uses the
 * configured provider or the log transport when none is set.
 */
class MessageController extends Controller
{
    public function __construct(private readonly WhatsAppService $whatsApp) {}

    /** Send a custom message to the booking's customer. */
    public function store(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $to = $booking->customerContactNumber() ?? $booking->customer?->email;
        if (blank($to)) {
            return back()->withErrors(['body' => 'This customer has no phone or email on file.']);
        }

        $this->whatsApp->send($to, $data['body'], [
            'type' => 'custom',
            'booking' => $booking,
        ]);

        return back()->with('status', 'Message sent.');
    }

    /** Resend a specific message (e.g. a failed delivery). */
    public function resend(Request $request, Message $message): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        // Clear any prior scheduling so it goes out now.
        $message->forceFill(['scheduled_for' => null, 'status' => 'queued'])->save();
        $this->whatsApp->deliver($message);

        return back()->with('status', 'Message resent.');
    }

    /**
     * Mark a message as sent by hand — used after the operator sends the
     * pre-filled WhatsApp message from their own phone (the free, manual flow).
     */
    public function markSent(Request $request, Message $message): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $message->forceFill(['status' => 'sent', 'sent_at' => now()])->save();

        return back()->with('status', 'Marked as sent.');
    }

    /**
     * Email this message to the customer — for customers who don't use WhatsApp.
     * Operator-triggered per message (a button tap), never automatic. Sends the
     * same text as the WhatsApp version, with the *bold* markers stripped, then
     * marks the message sent since it genuinely went out.
     */
    public function email(Request $request, Message $message): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $email = $message->booking?->customer?->email ?: $message->customer?->email;
        if (blank($email)) {
            return back()->withErrors(['email' => 'This customer has no email address on file.']);
        }

        try {
            Mail::to($email)->send(new CustomerMessageMail(
                $this->emailSubject($message),
                $this->plainText($message->renderedBody()),
            ));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[email] customer message send failed: '.$e->getMessage());

            return back()->withErrors(['email' => 'Could not send the email — please try again.']);
        }

        $message->forceFill(['status' => 'sent', 'sent_at' => now()])->save();

        return back()->with('status', 'Emailed to '.$email.'.');
    }

    /** A readable email subject for the message type. */
    private function emailSubject(Message $message): string
    {
        $ref = $message->booking?->reference;
        $suffix = $ref ? ' — '.$ref : '';

        return match (true) {
            $message->isReminder() => 'Your upcoming journey with Central Executive Transfers'.$suffix,
            $message->isReviewRequest() => 'How was your journey with Central Executive Transfers?',
            default => 'A message from Central Executive Transfers'.$suffix,
        };
    }

    /** Strip WhatsApp *bold* markers so the text reads cleanly as an email. */
    private function plainText(string $body): string
    {
        return preg_replace('/\*(.+?)\*/s', '$1', $body);
    }
}
