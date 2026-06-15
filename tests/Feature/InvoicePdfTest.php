<?php

namespace Tests\Feature;

use App\Mail\InvoiceMail;
use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Payments\InvoicePdf;
use App\Services\Payments\InvoiceService;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function invoice(?string $billingEmail = null): Invoice
    {
        $account = CorporateAccount::create([
            'name' => 'JELD-WEN', 'slug' => 'jw', 'account_code' => 'JW',
            'is_active' => true, 'billing_email' => $billingEmail, 'payment_terms_days' => 30,
        ]);
        Booking::factory()->forVehicleType(VehicleType::where('slug', 'executive')->first())->create([
            'corporate_account_id' => $account->id,
            'payment_method' => 'account',
            'status' => 'complete',
            'final_price' => 100,
            'pickup_at' => now()->subDays(3),
        ]);

        return app(InvoiceService::class)->generateForAccount($account, now()->subWeek(), now());
    }

    public function test_invoice_pdf_is_rendered_and_stored(): void
    {
        Storage::fake('local');
        $invoice = $this->invoice();

        $pdf = app(InvoicePdf::class)->render($invoice);
        $this->assertStringStartsWith('%PDF', $pdf); // valid PDF header

        app(InvoicePdf::class)->store($invoice);
        Storage::disk('local')->assertExists("invoices/{$invoice->invoice_number}.pdf");
        $this->assertNotNull($invoice->fresh()->pdf_path);
    }

    public function test_generated_invoice_email_attaches_the_pdf(): void
    {
        Storage::fake('local');
        Mail::fake();

        $invoice = $this->invoice('accounts@jeld-wen.example');

        Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $mail) => count($mail->attachments()) === 1);
        $this->assertNotNull($invoice->fresh()->pdf_path);
    }

    public function test_admin_can_download_invoice_pdf(): void
    {
        $invoice = $this->invoice();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('invoices.pdf', $invoice))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
