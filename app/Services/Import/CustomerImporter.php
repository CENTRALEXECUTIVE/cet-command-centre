<?php

namespace App\Services\Import;

use App\Models\CorporateAccount;
use App\Models\Customer;
use Illuminate\Support\Str;

/**
 * Imports the ETO "Customers export" CSV into the Command Centre:
 *   - a Customer record per row (name, email, phone), deduped by email, and
 *   - a CorporateAccount for any "Account payment = Yes" row that names a company
 *     (JELD-WEN, Forged Solutions, Vulcan, …), deduped by company name, with the
 *     customer linked to it so account billing/invoicing works.
 *
 * Semicolon- OR comma-delimited. Read from the temp upload and never stored
 * (customer PII). Idempotent: re-running updates existing records, never
 * duplicates. Inactive rows are skipped.
 *
 * @phpstan-type Stats array{customers_created:int, customers_updated:int, accounts_created:int, accounts_linked:int, skipped:int, errors:list<string>}
 */
class CustomerImporter
{
    /** @return Stats */
    public function import(string $path): array
    {
        $stats = ['customers_created' => 0, 'customers_updated' => 0, 'accounts_created' => 0, 'accounts_linked' => 0, 'skipped' => 0, 'errors' => []];

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $stats['errors'][] = 'Could not open the file.';

            return $stats;
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);

            return $stats;
        }
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        $header = array_map(fn ($h) => strtolower(trim($h, " \t\n\r\0\x0B\"\xEF\xBB\xBF")), str_getcsv($firstLine, $delimiter));

        $idx = fn (string $name) => ($p = array_search($name, $header, true)) === false ? null : $p;
        $cols = [
            'first' => $idx('first name'), 'last' => $idx('last name'), 'email' => $idx('email'),
            'mobile' => $idx('mobile number'), 'tel' => $idx('telephone number'),
            'company' => $idx('company name'), 'company_no' => $idx('company number'),
            'vat' => $idx('company vat number'), 'account' => $idx('account payment'),
            'active' => $idx('active'),
        ];

        $row = 1;
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $row++;
            $get = fn (?int $i) => $i !== null && isset($data[$i]) ? trim((string) $data[$i]) : '';

            if ($cols['active'] !== null && strcasecmp($get($cols['active']), 'No') === 0) {
                $stats['skipped']++;

                continue;
            }

            $name = trim($get($cols['first']).' '.$get($cols['last']));
            $email = $get($cols['email']) ?: null;
            $phone = $get($cols['mobile']) ?: ($get($cols['tel']) ?: null);

            if ($name === '' && ! $email) {
                $stats['skipped']++;

                continue;
            }

            try {
                $account = $this->resolveAccount($get($cols['company']), $get($cols['account']), $get($cols['vat']), $get($cols['company_no']), $email, $phone, $stats);

                $customer = $email
                    ? Customer::firstOrNew(['email' => $email])
                    : Customer::firstOrNew(['name' => $name ?: 'Customer', 'phone' => $phone]);
                $existed = $customer->exists;

                $customer->name = $name ?: ($customer->name ?: 'Customer');
                if ($phone && ! $customer->phone) {
                    $customer->phone = $phone;
                }
                if ($account) {
                    $customer->corporate_account_id = $account->id;
                }
                $customer->save();

                $existed ? $stats['customers_updated']++ : $stats['customers_created']++;
                if ($account) {
                    $stats['accounts_linked']++;
                }
            } catch (\Throwable $e) {
                $stats['errors'][] = "Row {$row}: ".$e->getMessage();
            }
        }
        fclose($handle);

        return $stats;
    }

    /**
     * Find or create the corporate account for an "Account payment = Yes" company
     * row. Deduped by company-name slug. Returns null for non-account rows.
     *
     * @param  array<string,mixed>  $stats
     */
    private function resolveAccount(string $company, string $accountPayment, string $vat, string $companyNo, ?string $email, ?string $phone, array &$stats): ?CorporateAccount
    {
        if ($company === '' || strcasecmp($accountPayment, 'Yes') !== 0) {
            return null;
        }

        $slug = Str::slug($company);
        $account = CorporateAccount::withTrashed()->where('slug', $slug)->first();

        if (! $account) {
            $account = new CorporateAccount([
                'name' => $company,
                'slug' => $slug,
                'account_code' => CorporateAccount::generateAccountCode(),
                'is_active' => true,
            ]);
            $stats['accounts_created']++;
        }

        // Fill details we have without clobbering anything already set by the office.
        // A VAT number like "Jeld-Wen UK" (ETO junk) is ignored — must look like one.
        if (! $account->vat_number && $this->looksLikeVat($vat)) {
            $account->vat_number = strtoupper(preg_replace('/\s+/', '', $vat));
        }
        if (! $account->company_number && $companyNo !== '' && $companyNo !== $company) {
            $account->company_number = $companyNo;
        }
        if (! $account->billing_email && $email) {
            $account->billing_email = $email;
        }
        if (! $account->phone && $phone) {
            $account->phone = $phone;
        }
        $account->save();

        return $account;
    }

    private function looksLikeVat(string $vat): bool
    {
        $v = strtoupper(preg_replace('/\s+/', '', $vat));

        return (bool) preg_match('/^(GB)?\d{6,12}$/', $v);
    }
}
