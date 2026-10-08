<?php

namespace Tests\Feature;

use App\Models\CorporateAccount;
use App\Models\Customer;
use App\Models\User;
use App\Services\Import\CustomerImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerImportTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $body): string
    {
        $header = '"First name";"Last name";"Email";"Mobile number";"Telephone number";"Emergency number";"Company name";"Company number";"Company VAT number";"Account payment";"Address";"City";"Postcode";"County";"Country";"Notes";"Member since";"Verified";"Active"';
        $path = tempnam(sys_get_temp_dir(), 'cust').'.csv';
        file_put_contents($path, $header."\n".$body."\n");

        return $path;
    }

    public function test_it_imports_customers_and_creates_corporate_accounts(): void
    {
        $path = $this->csv(implode("\n", [
            '"Jane";"McGuinness";"jane.mcguinness@vulcanseals.com";"";"";"";"Vulcan Engineering Ltd";"02422728";"GB533827144";"Yes";"";"";"";"";"";"";"07/10/2026";"Yes";"Yes"',
            '"Kevin";"Mappin";"kevinmappin@yahoo.co.uk";"";"";"";"";"";"";"No";"";"";"";"";"";"";"30/06/2026";"Yes";"Yes"',
        ]));

        $stats = app(CustomerImporter::class)->import($path);

        $this->assertSame(2, $stats['customers_created']);
        $this->assertSame(1, $stats['accounts_created']);

        $account = CorporateAccount::where('slug', 'vulcan-engineering-ltd')->first();
        $this->assertNotNull($account);
        $this->assertSame('GB533827144', $account->vat_number);
        $this->assertSame('02422728', $account->company_number);

        $jane = Customer::where('email', 'jane.mcguinness@vulcanseals.com')->first();
        $this->assertSame('Jane McGuinness', $jane->name);
        $this->assertSame($account->id, $jane->corporate_account_id);

        $kevin = Customer::where('email', 'kevinmappin@yahoo.co.uk')->first();
        $this->assertNull($kevin->corporate_account_id);
    }

    public function test_it_is_idempotent_and_skips_inactive(): void
    {
        $rows = '"Jane";"McGuinness";"jane.mcguinness@vulcanseals.com";"";"";"";"Vulcan Engineering Ltd";"02422728";"GB533827144";"Yes";"";"";"";"";"";"";"07/10/2026";"Yes";"Yes"';
        app(CustomerImporter::class)->import($this->csv($rows));
        $stats = app(CustomerImporter::class)->import($this->csv($rows));

        $this->assertSame(0, $stats['customers_created']);
        $this->assertSame(1, $stats['customers_updated']);
        $this->assertSame(1, Customer::count());
        $this->assertSame(1, CorporateAccount::count());

        // Inactive rows are skipped.
        $inactive = $this->csv('"Old";"Account";"old@example.com";"";"";"";"";"";"";"No";"";"";"";"";"";"";"01/01/2026";"Yes";"No"');
        $s2 = app(CustomerImporter::class)->import($inactive);
        $this->assertSame(1, $s2['skipped']);
        $this->assertSame(0, $s2['customers_created']);
    }

    public function test_it_ignores_junk_vat_numbers(): void
    {
        // ETO sometimes puts the company name in the VAT column.
        $path = $this->csv('"Jackie";"Donoghue";"JDonoghue@jeldwen.com";"";"";"";"Jeld-Wen UK";"Jeld-Wen UK";"Jeld-Wen UK";"Yes";"";"";"";"";"";"";"01/05/2026";"Yes";"Yes"');
        app(CustomerImporter::class)->import($path);

        $account = CorporateAccount::where('slug', 'jeld-wen-uk')->first();
        $this->assertNotNull($account);
        $this->assertNull($account->vat_number); // junk ignored
    }

    public function test_admin_can_upload_from_the_page(): void
    {
        $admin = User::factory()->admin()->create();
        $path = $this->csv('"Kevin";"Mappin";"kevinmappin@yahoo.co.uk";"";"";"";"";"";"";"No";"";"";"";"";"";"";"30/06/2026";"Yes";"Yes"');
        $file = new \Illuminate\Http\UploadedFile($path, 'customers.csv', 'text/csv', null, true);

        $this->actingAs($admin)->post(route('imports.customers'), ['file' => $file])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('customers', ['email' => 'kevinmappin@yahoo.co.uk']);
    }
}
