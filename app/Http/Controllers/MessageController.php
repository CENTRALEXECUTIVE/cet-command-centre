<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Message;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

        $to = $booking->customer?->phone ?? $booking->customer?->email;
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
}
