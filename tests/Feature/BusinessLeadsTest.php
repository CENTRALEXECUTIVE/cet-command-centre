<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Past customers with company emails = warm leads (27 Nov 2026). */
class BusinessLeadsTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_email_customers_are_listed_and_free_mail_is_not(): void
    {
        $biz = Customer::create(['name' => 'Rebecca F', 'email' => 'rebecca@gradconsult.example']);
        $free = Customer::create(['name' => 'Gmail Person', 'email' => 'someone@gmail.com']);
        Booking::factory()->create(['customer_id' => $biz->id]);
        Booking::factory()->create(['customer_id' => $free->id]);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('business-leads.index'))
            ->assertOk()->assertSee('gradconsult.example')->assertSee('Rebecca F')->assertDontSee('Gmail Person');

        $this->actingAs(User::factory()->create())->get(route('business-leads.index'))->assertForbidden();
    }
}
