<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Services\Messaging\BookingNotifier;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Console\Command;

/**
 * Delivers queued WhatsApp messages whose scheduled time has arrived — the
 * automated 24h and 2h booking reminders. Run on the scheduler every minute.
 *
 * Reminder bodies are re-rendered at send time from the current booking so the
 * driver / vehicle details (which are often finalised after the reminder was
 * first queued) are always the latest.
 */
class SendDueMessages extends Command
{
    protected $signature = 'cet:send-due-messages';

    protected $description = 'Deliver scheduled WhatsApp messages that are now due';

    public function handle(WhatsAppService $whatsApp, BookingNotifier $notifier): int
    {
        $due = Message::where('channel', 'whatsapp')
            ->where('status', 'queued')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->with(['booking.driver.driverProfile.defaultVehicle', 'booking.vehicle', 'booking.customer', 'booking.vehicleType'])
            ->limit(200)
            ->get();

        foreach ($due as $message) {
            // Refresh reminder wording with the current driver/vehicle.
            if (in_array($message->type, ['reminder_24h', 'reminder_2h'], true) && $message->booking) {
                $message->body = $notifier->reminderBody($message->booking);
                $message->save();
            }

            $whatsApp->deliver($message);
        }

        $this->info("Delivered {$due->count()} due message(s).");

        return self::SUCCESS;
    }
}
