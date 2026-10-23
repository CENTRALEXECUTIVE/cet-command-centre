<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\CorporateAccount;
use App\Models\CorporateContact;
use App\Models\Customer;
use App\Services\Watchdog\AdminAlerts;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Public "Open an account" sign-up (embeddable, no login). A person can register a
 * PERSONAL profile, or apply for a COMPANY (invoice/credit) account with full
 * company details. Both land as a request for the office to review and approve —
 * a company account is created INACTIVE so no credit is ever extended unvetted.
 * Nothing here provisions a login by itself; the office activates it.
 */
class AccountRequestController extends Controller
{
    public function __construct(private readonly AdminAlerts $adminAlerts) {}

    private function frameAncestors(): string
    {
        return "frame-ancestors 'self' https://centralexecutivetransfers.co.uk "
            .'https://*.centralexecutivetransfers.co.uk http://localhost:* http://127.0.0.1:*';
    }

    public function show(Request $request): \Illuminate\Http\Response
    {
        // Let a "?type=business" (or "company") link open straight on the Business tab,
        // so the marketing site's "Open a business account" button lands there.
        $preselect = in_array($request->query('type'), ['company', 'business'], true) ? 'company' : 'personal';

        return response()
            ->view('widget.open-account', ['done' => false, 'preselect' => $preselect])
            ->header('Content-Security-Policy', $this->frameAncestors());
    }

    public function store(Request $request): \Illuminate\Http\Response
    {
        // Honeypot.
        if (filled($request->input('company'))) {
            return response()->view('widget.open-account', ['done' => true])
                ->header('Content-Security-Policy', $this->frameAncestors());
        }

        $type = $request->input('account_type') === 'company' ? 'company' : 'personal';

        if ($type === 'company') {
            $data = $request->validate([
                'company_name' => ['required', 'string', 'max:160'],
                'company_number' => ['nullable', 'string', 'max:32'],
                'vat_number' => ['nullable', 'string', 'max:32'],
                'company_address' => ['required', 'string', 'max:500'],
                'contact_name' => ['required', 'string', 'max:120'],
                'contact_email' => ['required', 'email', 'max:160'],
                'contact_phone' => ['required', 'string', 'max:32'],
                'contact_address' => ['nullable', 'string', 'max:500'],
                'extra_emails' => ['nullable', 'array', 'max:10'],
                'extra_emails.*' => ['nullable', 'email', 'max:160'],
                'password' => ['nullable', 'string', 'min:8', 'max:72', 'confirmed'],
            ]);

            $account = CorporateAccount::create([
                'name' => $data['company_name'],
                'company_number' => $data['company_number'] ?? null,
                'slug' => $this->uniqueSlug($data['company_name']),
                'account_code' => $this->uniqueAccountCode(),
                'billing_email' => $data['contact_email'],
                'phone' => $data['contact_phone'],
                'billing_address' => $data['company_address'],
                'vat_number' => $data['vat_number'] ?? null,
                'cost_code_required' => false,
                'payment_terms_days' => 30,
                'is_active' => false, // PENDING — office approves before credit/invoice
                'notes' => trim("Self-registered ".now()->format('D d M Y, H:i')
                    .(filled($data['contact_address'] ?? null) ? "\nMain contact address: ".$data['contact_address'] : '')),
            ]);

            CorporateContact::create([
                'corporate_account_id' => $account->id,
                'name' => $data['contact_name'],
                'email' => $data['contact_email'],
                'phone' => $data['contact_phone'],
                'is_primary' => true,
            ]);

            // "Add another email" — each extra address is a notification contact.
            foreach (array_values(array_filter(array_map('trim', $data['extra_emails'] ?? []))) as $email) {
                CorporateContact::create([
                    'corporate_account_id' => $account->id,
                    'name' => $data['contact_name'],
                    'email' => $email,
                    'is_primary' => false,
                ]);
            }

            // Give the main contact a My Account login (a customer record — never a
            // system user), linked to the pending company. Credit stays off until
            // the office approves; the login just lets them see & manage bookings.
            $this->upsertCustomerLogin(
                $data['contact_name'], $data['contact_email'], $data['contact_phone'],
                $data['password'] ?? null, $account->id,
            );

            $this->adminAlerts->notify('account_request',
                '🏢 New business account request — '.$account->name,
                $account->name.' applied for an invoice/credit account. Review & approve to switch on credit terms.',
                'info');
            \App\Models\WatchdogEvent::log('account_request', 'Business account request — '.$account->name, 'info');
            $this->emailOffice('company', $account->name, [
                'Company' => $account->name,
                'Companies House' => $account->company_number,
                'VAT number' => $account->vat_number,
                'Address' => $account->billing_address,
                'Contact' => $data['contact_name'],
                'Email' => $data['contact_email'],
                'Phone' => $data['contact_phone'],
            ], url('/accounts/'.$account->id));

            return response()->view('widget.open-account', ['done' => true, 'accountType' => 'company'])
                ->header('Content-Security-Policy', $this->frameAncestors());
        }

        // Personal profile.
        $data = $request->validate([
            'personal_name' => ['required', 'string', 'max:120'],
            'personal_email' => ['required', 'email', 'max:160'],
            'personal_phone' => ['required', 'string', 'max:32'],
            'password' => ['nullable', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);

        $customer = $this->upsertCustomerLogin(
            $data['personal_name'], $data['personal_email'], $data['personal_phone'],
            $data['password'] ?? null,
        );

        $this->adminAlerts->notify('account_request',
            '👤 New personal account — '.$customer->name,
            $customer->name.' registered ('.$customer->email.'). They can manage bookings via My Account.',
            'info');
        // notify() is push-only — also drop a persistent row in the alerts feed so a
        // personal sign-up is never lost if push is off, matching the business branch.
        \App\Models\WatchdogEvent::log('account_request', 'Personal account — '.$customer->name, 'info');
        $this->emailOffice('personal', $customer->name, [
            'Name' => $customer->name,
            'Email' => $customer->email,
            'Phone' => $customer->phone,
        ], url('/customers/'.$customer->id));

        return response()->view('widget.open-account', ['done' => true, 'accountType' => 'personal'])
            ->header('Content-Security-Policy', $this->frameAncestors());
    }

    /**
     * Email the office inbox about a new sign-up (personal or business), so a new
     * account is never missed when push is off or muted — the same ops inbox that
     * gets web bookings. Best-effort: a mail failure must never break the sign-up.
     *
     * @param  'company'|'personal'  $type
     * @param  array<string,?string>  $details
     */
    private function emailOffice(string $type, string $name, array $details, ?string $reviewUrl): void
    {
        if (! $ops = config('cet.ops_email')) {
            return;
        }
        try {
            \Illuminate\Support\Facades\Mail::to($ops)->send(
                new \App\Mail\AccountRequestMail($type, $name, array_filter($details, fn ($v) => filled($v)), $reviewUrl)
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Signup] office email failed: '.$e->getMessage());
        }
    }

    /**
     * Find-or-create the customer record for a sign-up, optionally setting a
     * self-service login password. Matches on phone first, then email, so we don't
     * duplicate an existing customer. Never creates a system user.
     */
    private function upsertCustomerLogin(
        string $name, string $email, string $phone, ?string $password = null, ?int $corporateAccountId = null,
    ): Customer {
        $customer = Customer::where('phone', $phone)->first()
            ?? Customer::where('email', $email)->first();

        $fields = array_filter([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'corporate_account_id' => $corporateAccountId,
        ], fn ($v) => $v !== null && $v !== '');

        if ($customer) {
            $customer->fill($fields)->save();
        } else {
            $customer = Customer::create($fields);
        }

        if (filled($password) && Customer::passwordLoginAvailable()) {
            $customer->setLoginPassword($password);
        }

        return $customer;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'account';
        $slug = $base;
        $n = 1;
        while (CorporateAccount::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }

    private function uniqueAccountCode(): string
    {
        return CorporateAccount::generateAccountCode();
    }
}
