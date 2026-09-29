<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The company + bank details printed on invoices and receipts. The office can set
 * these in Settings → Company & invoice details (they override the config/.env
 * defaults) so invoices are accurate without a deploy. Everything is optional and
 * only shown when present, so a partially-filled profile never breaks a PDF.
 */
class InvoiceProfile
{
    /** @return array<string, string> */
    public static function company(): array
    {
        return [
            'name' => (string) config('cet.company.name'),
            'number' => (string) config('cet.company.number'),
            'operator_licence' => (string) config('cet.company.operator_licence'),
            'website' => (string) config('cet.company.website'),
            'vat_number' => trim((string) (Setting::get('invoice_vat_number') ?: config('cet.company.vat_number'))),
            'address' => trim((string) (Setting::get('invoice_company_address') ?: config('cet.company.address'))),
            'phone' => trim((string) (Setting::get('invoice_phone') ?: config('cet.company.phone'))),
            'email' => trim((string) (Setting::get('invoice_email') ?: config('cet.company.email'))),
        ];
    }

    /** Bank (BACS) details, only when a sort code AND account number are set. */
    public static function bank(): array
    {
        $bank = [
            'name' => trim((string) (Setting::get('invoice_bank_name') ?: config('cet.bank.name'))),
            'sort_code' => trim((string) (Setting::get('invoice_bank_sort') ?: config('cet.bank.sort_code'))),
            'account_number' => trim((string) (Setting::get('invoice_bank_account') ?: config('cet.bank.account_number'))),
        ];

        return ($bank['sort_code'] !== '' && $bank['account_number'] !== '') ? $bank : [];
    }

    /** Optional free-text note printed at the foot of every invoice. */
    public static function footerNote(): string
    {
        return trim((string) Setting::get('invoice_footer_note', ''));
    }
}
