<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Address\PostcodeAddressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Full "postcode → every address" lookup (Royal Mail PAF via getAddress.io).
 * It powers the customer booking form's "pick your exact house" list and is a
 * silent no-op until a key is set, so the form always works.
 */
class PostcodeAddressTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGetAddress(): void
    {
        Http::fake(['api.getaddress.io/*' => Http::response([
            'postcode' => 'S9 5BW',
            'addresses' => [
                ['formatted_address' => ['1 Harney Close', '', '', 'Sheffield', 'South Yorkshire']],
                ['formatted_address' => ['2 Harney Close', '', '', 'Sheffield', 'South Yorkshire']],
            ],
        ], 200)]);
    }

    public function test_it_lists_every_address_at_a_postcode_when_a_key_is_set(): void
    {
        Setting::set('getaddress_key', 'PAF-KEY', 'string', 'integrations');
        $this->fakeGetAddress();

        $this->getJson(route('public.book.addresses', ['postcode' => 's9 5bw']))
            ->assertOk()
            ->assertJson(['enabled' => true, 'postcode' => 'S9 5BW'])
            ->assertJsonFragment(['1 Harney Close, Sheffield, South Yorkshire, S9 5BW'])
            ->assertJsonCount(2, 'addresses');
    }

    public function test_it_is_a_no_op_without_a_key(): void
    {
        Http::fake();

        $this->getJson(route('public.book.addresses', ['postcode' => 'S9 5BW']))
            ->assertOk()
            ->assertExactJson(['enabled' => false, 'postcode' => 'S9 5BW', 'addresses' => []]);

        Http::assertNothingSent();
    }

    public function test_an_invalid_postcode_returns_nothing_and_calls_no_api(): void
    {
        Setting::set('getaddress_key', 'PAF-KEY', 'string', 'integrations');
        Http::fake();

        $this->getJson(route('public.book.addresses', ['postcode' => 'not a postcode']))
            ->assertOk()
            ->assertJson(['enabled' => true, 'postcode' => '', 'addresses' => []]);

        Http::assertNothingSent();
    }

    public function test_results_are_cached_so_the_free_tier_is_protected(): void
    {
        Cache::flush();
        Setting::set('getaddress_key', 'PAF-KEY', 'string', 'integrations');
        $this->fakeGetAddress();

        $svc = app(PostcodeAddressService::class);
        $svc->lookup('S9 5BW');
        $svc->lookup('S9 5BW'); // served from cache

        Http::assertSentCount(1);
    }

    public function test_it_normalises_various_postcode_spacings(): void
    {
        $svc = app(PostcodeAddressService::class);
        $this->assertSame('S9 5BW', $svc->normalise('s95bw'));
        $this->assertSame('S9 5BW', $svc->normalise('  S9   5BW '));
        $this->assertSame('', $svc->normalise('rubbish'));
    }
}
