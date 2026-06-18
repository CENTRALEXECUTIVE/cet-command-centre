<?php

namespace App\Console\Commands;

use App\Services\Inbox\GraphMailClient;
use Illuminate\Console\Command;

/**
 * Diagnoses the Microsoft Graph connection step by step (config → token →
 * mailbox read) so a "Processed 0" can be explained: bad secret, missing admin
 * consent, wrong mailbox, or simply no unread emails. Reveals no secrets.
 */
class TestGraph extends Command
{
    protected $signature = 'cet:test-graph';

    protected $description = 'Check the Microsoft Graph email connection and report what works';

    public function handle(GraphMailClient $graph): int
    {
        $d = $graph->diagnose();

        $this->line('Config present:');
        $this->line('  client_id:     '.($d['client_id'] ? 'yes' : 'NO'));
        $this->line('  tenant_id:     '.($d['tenant_id'] ? 'yes' : 'NO'));
        $this->line('  client_secret: '.($d['client_secret'] ? 'yes' : 'NO'));
        $this->line('  mailbox:       '.($d['mailbox'] ?: 'NOT SET'));

        if (array_key_exists('token_ok', $d)) {
            $this->line('Token (login):   '.($d['token_ok'] ? 'OK' : 'FAILED'));
        }
        if (! empty($d['token_error'])) {
            $this->error('  Token error: '.$d['token_error']);
        }
        if (array_key_exists('mail_http_status', $d)) {
            $this->line('Mailbox read:    HTTP '.$d['mail_http_status']);
        }
        if (! empty($d['mail_error'])) {
            $this->error('  Mailbox error: '.$d['mail_error']);
        }
        if (array_key_exists('unread_count', $d)) {
            $this->line('Unread emails:   '.$d['unread_count']);
            foreach ($d['unread_subjects'] ?? [] as $subject) {
                $this->line('   • '.$subject);
            }
        }

        $ok = ($d['result'] ?? '') !== '' && str_starts_with($d['result'], 'Connected OK');
        $this->newLine();
        $ok ? $this->info($d['result']) : $this->warn($d['result'] ?? 'Unknown result.');

        return self::SUCCESS;
    }
}
