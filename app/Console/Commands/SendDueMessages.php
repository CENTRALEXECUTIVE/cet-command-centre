<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Services\Messaging\BookingNotifier;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Console\Command;

/**
 * Handles queued WhatsApp messages whose scheduled time has arrived. Run on the
 * scheduler every minute.
 *
 * Pickup REMINDERS are not auto-delivered: the office sends them by hand from
 * their own WhatsApp (the free, no-API flow), so this command just re-renders a
 * due reminder's wording with the current driver/vehicle and leaves it queued as
 * a task on the dashboard + booking. Any other scheduled message (e.g. the
 * review request) is delivered as before.
 */
class SendDueMessages extends Command
{
    protected $signature = 'cet:send-due-messages';

    protected $description = 'Process scheduled WhatsApp messages that are now due';

    public function handle(WhatsAppService $whatsApp, BookingNotifier $notifier): int
    {
        $due = Message::where('channel', 'whatsapp')
            ->where('status', 'queued')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->with(['booking.driver.driverProfile.defaultVehicle', 'booking.vehicle', 'booking.customer', 'booking.vehicleType'])
            ->limit(200)
            ->get();

        $delivered = 0;
        foreach ($due as $message) {
            // Reminders wait for the operator to send them — just keep the
            // wording fresh with the current driver/vehicle.
            if ($message->isReminder() && $message->booking) {
                $message->body = $notifier->reminderBody($message->booking);
                $message->save();

                continue;
            }

            $whatsApp->deliver($message);
            $delivered++;
        }

        $this->info("Refreshed {$due->count()} due message(s); delivered {$delivered}.");

        return self::SUCCESS;
    }
}
