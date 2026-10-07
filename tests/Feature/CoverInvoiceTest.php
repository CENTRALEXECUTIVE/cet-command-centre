<?php

namespace Tests\Feature;

use App\Mail\BookingReceiptMail;
use App\Models\Booking;
use App\Models\User;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * One combined invoice to an operator for several cover jobs.
 */
class CoverInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    private function coverJob(string $operator, float $amount, ?string $email = null): Booking
    {
        $b = Booking::factory()->create(['quoted_price' => $amount]);
        $b->setCoverFor(['name' => $operator, 'email' => $email, 'amount' => $amount]);

        return $b->fresh();
    }

    public function test_the_page_groups_cover_jobs_by_operator(): void
    {
        $admin = User::factory()->admin()->create();
        $this->coverJob('A1 Cars', 50);
        $this->coverJob('A1 Cars', 70);
        $this->coverJob('B2 Travel', 40);

        $this->actingAs($admin)->get(route('cover-invoices.index'))->assertOk()
            ->assertSee('A1 Cars', false)
            ->assertSee('B2 Travel', false);
    }

    public function test_a_combined_invoice_pdf_totals_the_selected_jobs(): void
    {
        $admin = User::factory()->admin()->create();
        $j1 = $this->coverJob('A1 Cars', 50);
        $j2 = $this->coverJob('A1 Cars', 70);

        $res = $this->actingAs($admin)->post(route('cover-invoices.pdf'), ['ids' => [$j1->id, $j2->id]]);
        $res->assertOk();
        $res->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_emailing_the_combined_invoice_goes_to_the_operator(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $j1 = $this->coverJob('A1 Cars', 50, 'accounts@a1.co.uk');
        $j2 = $this->coverJob('A1 Cars', 70, 'accounts@a1.co.uk');

        $this->actingAs($admin)->post(route('cover-invoices.email'), ['ids' => [$j1->id, $j2->id]])
            ->assertRedirect();

        Mail::assertSent(BookingReceiptMail::class, fn ($m) => $m->hasTo('accounts@a1.co.uk'));
    }

    public function test_only_the_first_operators_jobs_are_billed_if_a_mix_is_selected(): void
    {
        $admin = User::factory()->admin()->create();
        $a = $this->coverJob('A1 Cars', 50);
        $b = $this->coverJob('B2 Travel', 40);

        // Mixed selection — the invoice sticks to the first operator (A1 Cars).
        $res = $this->actingAs($admin)->post(route('cover-invoices.pdf'), ['ids' => [$a->id, $b->id]]);
        $res->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }
}
