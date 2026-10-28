<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\BookingConfirmationMail;
use App\Models\Booking;
use App\Services\Messaging\ConfirmationEmailState;
use App\Support\BookingEmailLog;
use App\Support\MailFailure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Booking communications from the booking page: resend the confirmation, and email
 * the customer the UPDATED booking after an edit / re-price. Both are deliberate
 * office button presses (preview first) — nothing here is ever automatic.
 *
 * Imported ETO / calendar bookings are never emailed unless the admin ticks an
 * explicit "send anyway" — the same intent as the source_system guard on the
 * automatic paid confirmation (BookingStatusService::emailPaidConfirmation).
 */
class BookingCommsController extends Controller
{
    public function resendConfirmation(Request $request, Booking $booking): RedirectResponse
    {
        return $this->send($request, $booking, updated: false);
    }

    public function sendUpdated(Request $request, Booking $booking): RedirectResponse
    {
        return $this->send($request, $booking, updated: true);
    }

    private function send(Request $request, Booking $booking, bool $updated): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $booking = $booking->fresh(['customer', 'payments']);
        $what = $updated ? 'updated booking email' : 'booking confirmation';

        if ($reason = ConfirmationEmailState::blockedReason($booking)) {
            return back()->with('error', $reason)->with('scroll', 'customer-emails');
        }

        $imported = ConfirmationEmailState::isImported($booking);
        if ($imported && ! $request->boolean('send_anyway')) {
            return back()
                ->with('error', 'This booking was imported from '.strtoupper($booking->source_system).' — tick "Send anyway" to confirm you really want to email this customer.')
                ->with('scroll', 'customer-emails');
        }

        $state = ConfirmationEmailState::for($booking);
        $email = $booking->customer->email;
        $note = $imported ? 'Sent anyway — imported '.strtoupper($booking->source_system).' booking' : null;

        try {
            BookingEmailLog::withNote($note, fn () => Mail::to($email)->send(
                new BookingConfirmationMail($booking, paid: $state['paid'], payUrl: $state['payUrl'], updated: $updated)
            ));
        } catch (\Throwable $e) {
            MailFailure::report($what, $e, $booking, $email);

            return back()->with('error', 'Could not send the '.$what.' — check Settings → Email and try again.')->with('scroll', 'customer-emails');
        }

        // The email covers every live leg of the trip — note it as sent on each, so
        // the "UPDATED" tag only marks changes made after this email.
        $sentAt = now()->toIso8601String();
        foreach (($booking->tripBookings()->count() > 1 ? $booking->tripBookings() : collect([$booking])) as $leg) {
            $leg = $leg->fresh();
            $leg->forceFill(['meta' => array_merge($leg->meta ?? [], ['customer_email_sent' => $sentAt])])->save();
        }

        return back()
            ->with('status', ($updated ? 'Updated booking' : 'Confirmation').' emailed to '.$email.' ('.$state['label'].') from '.$booking->senderAddress().'.')
            ->with('scroll', 'customer-emails');
    }
}
