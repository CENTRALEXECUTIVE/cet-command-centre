<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Telephony\OfficeAlertCall;
use Illuminate\Console\Command;

/**
 * Diagnose the emergency at-risk auto-call setup — WITHOUT placing a call or
 * printing any secret. Reports whether Twilio credentials are present, which
 * number the call would dial FROM (and where that resolved from) and TO, and
 * which directors are reachable for the routed call. Numbers are masked to the
 * last 4 digits so the output is safe to paste into a chat.
 *
 *   php artisan cet:alert-call-status   # run this ON the staging server
 *
 * To actually hear it ring, use the "Fire full test" button on Settings →
 * Notifications (super-admin), which places a real one-off test call.
 */
class AlertCallStatus extends Command
{
    protected $signature = 'cet:alert-call-status';

    protected $description = 'Report whether the emergency at-risk auto-call is wired up (no secrets, no call placed)';

    public function handle(OfficeAlertCall $call): int
    {
        $d = $call->diagnostics();

        $this->newLine();
        $this->line('  <options=bold>Emergency at-risk auto-call — setup check</>');
        $this->newLine();

        $this->row('Twilio credentials', $d['twilio_credentials'], $d['twilio_credentials'] ? 'present' : 'MISSING (TWILIO_SID / TWILIO_AUTH_TOKEN)');
        $this->row('Call FROM (caller ID)', $d['from'] !== null, $d['from'] ? $this->mask($d['from']) : 'NOT SET (no driver line / customer line / CET_ALERT_CALL_FROM)');
        if ($d['from'] !== null) {
            $this->line('         source: '.$d['from_source']);
        }
        $this->row('Call TO (office line)', $d['to'] !== null, $d['to'] ? $this->mask($d['to']) : 'NOT SET (CET_OFFICE_CALL_NUMBER)');

        $this->newLine();

        // Who the routed call can actually reach (directors with a mobile saved).
        $admins = User::where('role', UserRole::Admin->value)->where('is_active', true)->get();
        $withPhone = $admins->filter(fn (User $u) => filled($u->phone));
        $this->line('  <options=bold>Directors reachable for the routed call</> ('.$withPhone->count().' of '.$admins->count().' have a mobile saved):');
        foreach ($admins as $u) {
            $ok = filled($u->phone);
            $this->line('    '.($ok ? '<fg=green>✓</>' : '<fg=red>✗</>').' '.$u->name.'  '.($ok ? $this->mask($u->phone) : '<fg=red>no mobile — set it on /users</>'));
        }

        $this->newLine();
        if ($d['configured'] && $withPhone->isNotEmpty()) {
            $this->info('  ✅ Emergency auto-call is fully wired up. An at-risk job will ring a free director, falling back to the office line.');
        } elseif ($d['configured']) {
            $this->warn('  ⚠ The call is configured but NO director has a mobile — every at-risk call will fall back to the office line only.');
        } else {
            $this->error('  ✗ The auto-call is NOT active yet — see the MISSING/NOT SET rows above. (The push alert + siren still fire; only the phone call is off.)');
        }
        $this->newLine();

        return self::SUCCESS;
    }

    private function row(string $label, bool $ok, string $detail): void
    {
        $this->line('    '.($ok ? '<fg=green>✓</>' : '<fg=red>✗</>')."  {$label}: {$detail}");
    }

    /** Show only the last 4 digits so full numbers never hit the terminal/logs. */
    private function mask(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number);
        $tail = substr($digits, -4);

        return '•••• '.$tail;
    }
}
