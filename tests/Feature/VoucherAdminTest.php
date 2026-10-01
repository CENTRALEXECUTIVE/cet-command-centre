<?php

namespace Tests\Feature;

use App\Models\Voucher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The office can create and manage discount codes that the booking widget accepts.
 */
class VoucherAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_create_a_percentage_code(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('vouchers.store'), [
            'code' => 'welcome10', 'type' => 'percent', 'value' => 10, 'max_uses' => 50,
        ])->assertRedirect();

        $v = Voucher::whereRaw('UPPER(code) = ?', ['WELCOME10'])->first();
        $this->assertNotNull($v);
        $this->assertSame('WELCOME10', $v->code);
        $this->assertSame('percent', $v->type);
        $this->assertTrue($v->isRedeemable());
        $this->assertEqualsWithDelta(10.0, $v->discountOn(100), 0.01); // 10% of £100
    }

    public function test_duplicate_codes_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        Voucher::create(['code' => 'SAVE5', 'type' => 'fixed', 'value' => 5, 'is_active' => true]);

        $this->actingAs($admin)->post(route('vouchers.store'), [
            'code' => 'save5', 'type' => 'fixed', 'value' => 5,
        ])->assertSessionHasErrors('code');

        $this->assertSame(1, Voucher::whereRaw('UPPER(code) = ?', ['SAVE5'])->count());
    }

    public function test_an_admin_can_edit_and_delete_a_code(): void
    {
        $admin = User::factory()->admin()->create();
        $v = Voucher::create(['code' => 'OLD', 'type' => 'fixed', 'value' => 5, 'is_active' => true]);

        $this->actingAs($admin)->put(route('vouchers.update', $v), [
            'code' => 'OLD', 'type' => 'fixed', 'value' => 8, // is_active omitted → off
        ])->assertRedirect();
        $v->refresh();
        $this->assertEqualsWithDelta(8.0, $v->value, 0.01);
        $this->assertFalse($v->is_active);

        $this->actingAs($admin)->delete(route('vouchers.destroy', $v))->assertRedirect();
        $this->assertNull(Voucher::find($v->id));
    }

    public function test_a_driver_cannot_manage_vouchers(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('vouchers.index'))->assertForbidden();
        $this->actingAs($driver)->post(route('vouchers.store'), ['code' => 'X', 'type' => 'percent', 'value' => 5])->assertForbidden();
    }
}
