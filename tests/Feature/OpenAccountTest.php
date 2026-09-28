<?php

namespace Tests\Feature;

use App\Models\CorporateAccount;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public "Open an account" sign-up. A personal profile saves a customer record; a
 * company applies for an invoice/credit account, created INACTIVE (pending office
 * approval) with its contacts and any extra emails.
 */
class OpenAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_signup_page_is_public(): void
    {
        $this->get(route('widget.open-account'))->assertOk()
            ->assertSee('Open an account')
            ->assertSee('Business');
    }

    public function test_a_personal_signup_saves_a_customer(): void
    {
        $this->post(route('widget.open-account.store'), [
            'account_type' => 'personal',
            'personal_name' => 'Olivia Lester',
            'personal_email' => 'olivia@example.com',
            'personal_phone' => '07100000000',
        ])->assertOk()->assertSee("You're all set", false);

        $this->assertDatabaseHas('customers', ['name' => 'Olivia Lester', 'email' => 'olivia@example.com']);
    }

    public function test_a_company_signup_creates_a_pending_account_with_contacts(): void
    {
        $this->post(route('widget.open-account.store'), [
            'account_type' => 'company',
            'company_name' => 'JELD-WEN UK',
            'company_number' => '15749931',
            'vat_number' => 'GB123456789',
            'company_address' => '1 Factory Road, Sheffield, S9 1AA',
            'contact_name' => 'Pat Buyer',
            'contact_email' => 'pat@jeldwen.example',
            'contact_phone' => '07200000000',
            'contact_address' => 'Head Office, London',
            'extra_emails' => ['accounts@jeldwen.example', 'bookings@jeldwen.example'],
        ])->assertOk()->assertSee('Business account requested');

        $account = CorporateAccount::where('name', 'JELD-WEN UK')->first();
        $this->assertNotNull($account);
        $this->assertFalse((bool) $account->is_active, 'A self-registered company account must start inactive (pending approval).');
        $this->assertSame('15749931', $account->company_number);
        $this->assertSame('GB123456789', $account->vat_number);

        // Primary contact + the two extra email contacts.
        $this->assertSame(1, $account->contacts()->where('is_primary', true)->count());
        $this->assertSame(3, $account->contacts()->count());
        $this->assertTrue($account->contacts()->where('email', 'accounts@jeldwen.example')->exists());
    }

    public function test_company_signup_validates_required_fields(): void
    {
        $this->post(route('widget.open-account.store'), ['account_type' => 'company'])
            ->assertSessionHasErrors(['company_name', 'company_address', 'contact_name', 'contact_email', 'contact_phone']);
    }
}
