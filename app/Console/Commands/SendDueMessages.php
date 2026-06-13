<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Console\Command;

/**
 * Delivers queued WhatsApp messages whose scheduled time has arrived — the
 * automated 24h and 2h booking reminders. Run on the scheduler every minute.
 */
class SendDueMessages extends Command
{
    protected $signature = 'cet:send-due-messages';

    protected $description = 'Deliver scheduled WhatsApp messages that are now due';

    public function handle(WhatsAppService $whatsApp): int
    {
        $due = Message::where('channel', 'whatsapp')
            ->where('status', 'queued')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->limit(200)
            ->get();

        foreach ($due as $message) {
            $whatsApp->deliver($message);
        }

        $this->info("Delivered {$due->count()} due message(s).");

        return self::SUCCESS;
    }
}
