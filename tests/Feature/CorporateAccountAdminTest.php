<?php

namespace Tests\Feature;

use App\Models\CorporateAccount;
use App\Models\CorporateContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Business (corporate) accounts admin — the place the office finds companies like
 * MEPS by name or account number (they don't appear under Customers).
 */
class CorporateAccountAdminTest extends TestCase
{
    use RefreshDatabase;

    private function account(): CorporateAccount
    {
        $a = CorporateAccount::create([
            'name' => 'MEPS International Ltd', 'account_code' => '1001',
            'slug' => 'meps-international', 'vat_number' => 'GB 439097618',
            'is_active' => true, 'payment_terms_days' => 30,
        ]);
        CorporateContact::create([
            'corporate_account_id' => $a->id, 'name' => 'Lorna Roberts',
            'email' => 'lroberts@meps.co.uk', 'phone' => '07712129000',
            'job_title' => 'Head of HR', 'is_primary' => true,
        ]);

        return $a;
    }

    public function test_admin_can_list_and_search_business_accounts(): void
    {
        $admin = User::factory()->admin()->create();
        $this->account();

        $this->actingAs($admin)->get(route('accounts.index'))
            ->assertOk()->assertSee('MEPS International Ltd')->assertSee('1001');

        // Search by name and by account number both find it.
        $this->actingAs($admin)->get(route('accounts.index', ['q' => 'Meps']))
            ->assertOk()->assertSee('MEPS International Ltd');
        $this->actingAs($admin)->get(route('accounts.index', ['q' => '1001']))
            ->assertOk()->assertSee('MEPS International Ltd');
    }

    public function test_the_account_page_shows_details_and_contacts(): void
    {
        $admin = User::factory()->admin()->create();
        $account = $this->account();

        $this->actingAs($admin)->get(route('accounts.show', $account))
            ->assertOk()
            ->assertSee('MEPS International Ltd')
            ->assertSee('GB 439097618')
            ->assertSee('Lorna Roberts')
            ->assertSee('lroberts@meps.co.uk');
    }

    public function test_a_customer_search_surfaces_a_matching_business_account(): void
    {
        $admin = User::factory()->admin()->create();
        $this->account();

        $this->actingAs($admin)->get(route('customers.index', ['q' => 'MEPS']))
            ->assertOk()
            ->assertSee('business accounts', false)
            ->assertSee('MEPS International Ltd');
    }

    public function test_business_accounts_are_admin_only(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('accounts.index'))->assertForbidden();
    }
}
