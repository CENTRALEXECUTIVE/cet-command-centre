<?php

namespace App\Console\Commands;

use App\Models\CorporateAccount;
use App\Models\Customer;
use Illuminate\Console\Command;

/**
 * Audit: which corporate accounts have an email DOMAIN the leaderboard can group
 * on, and how many customers/bookings that domain would roll in. The Review page
 * matches a traveller to a business when their email is on the business's domain,
 * so this shows where that will fire and where a domain is still missing.
 *
 *   php artisan cet:corporate-domains
 */
class CorporateDomains extends Command
{
    protected $signature = 'cet:corporate-domains';

    protected $description = 'Show each corporate account’s email domain(s) and how many customers match';

    /** Free web-mail domains never identify a business. */
    private const FREE = ['gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.co.uk', 'outlook.com', 'yahoo.com', 'yahoo.co.uk', 'icloud.com', 'live.com', 'live.co.uk', 'aol.com', 'msn.com', 'me.com', 'btinternet.com', 'sky.com', 'protonmail.com'];

    public function handle(): int
    {
        $customers = Customer::whereNotNull('email')->get(['id', 'email', 'corporate_account_id']);

        // domain => [total customers, untagged customers]
        $byDomain = [];
        foreach ($customers as $c) {
            $d = $this->domain($c->email);
            if (! $d) {
                continue;
            }
            $byDomain[$d] ??= ['total' => 0, 'untagged' => 0];
            $byDomain[$d]['total']++;
            if (! $c->corporate_account_id) {
                $byDomain[$d]['untagged']++;
            }
        }

        $accounts = CorporateAccount::with('contacts')->get();
        if ($accounts->isEmpty()) {
            $this->warn('No corporate accounts set up yet.');

            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            $tagged = $customers->where('corporate_account_id', $account->id);
            $domains = collect()
                ->merge([$this->domain($account->billing_email)])
                ->merge($account->contacts->map(fn ($ct) => $this->domain($ct->email)))
                ->merge($tagged->map(fn ($c) => $this->domain($c->email)))
                ->filter()->unique()->values();

            $this->line('');
            $this->getOutput()->writeln("<fg=cyan>{$account->name}</> — {$tagged->count()} tagged customer(s)");

            if ($domains->isEmpty()) {
                $this->warn('  ✗ no company domain detected — nobody will auto-roll in.');
                $this->line('     Fix: set the account billing email, add a contact, or tag ONE customer who uses the company email.');

                continue;
            }

            foreach ($domains as $d) {
                $stat = $byDomain[$d] ?? ['total' => 0, 'untagged' => 0];
                $this->getOutput()->writeln("  <fg=green>✓ @{$d}</>  — {$stat['total']} customer(s) on this domain, {$stat['untagged']} not yet tagged (these roll in automatically now)");
            }
        }

        // Domains that look like a company but aren't attached to any account.
        $known = $accounts->flatMap(function ($a) {
            return collect([$this->domain($a->billing_email)])
                ->merge($a->contacts->map(fn ($ct) => $this->domain($ct->email)))
                ->merge(Customer::where('corporate_account_id', $a->id)->pluck('email')->map(fn ($e) => $this->domain($e)));
        })->filter()->unique()->all();

        $orphan = collect($byDomain)
            ->reject(fn ($_, $d) => in_array($d, $known, true))
            ->filter(fn ($s) => $s['total'] >= 3) // only domains with a few customers
            ->sortByDesc(fn ($s) => $s['total']);

        if ($orphan->isNotEmpty()) {
            $this->line('');
            $this->getOutput()->writeln('<fg=yellow>Company-looking domains with NO account (a business you could add / tag):</>');
            foreach ($orphan as $d => $s) {
                $this->line("  @{$d} — {$s['total']} customer(s)");
            }
        }

        return self::SUCCESS;
    }

    private function domain(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return null;
        }
        $domain = substr($email, $at + 1);

        return ($domain !== '' && ! in_array($domain, self::FREE, true) && str_contains($domain, '.')) ? $domain : null;
    }
}
