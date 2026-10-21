<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\VehicleType;
use Database\Seeders\AirportSeeder;
use Database\Seeders\DirectorSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Smoke test: load EVERY GET page in the app as a super-admin with real seeded
 * data, and fail on any that 5xx. This is the "every button on every page is
 * clickable and the page isn't broken" guard — a page that renders has its
 * buttons; a page that 500s is the bug class we keep hitting (a too-small column,
 * a bad relation, a view error). 403/404/302 are fine (the route works, it just
 * guards or has no row); only a 5xx is a failure.
 */
class EveryPageLoadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([VehicleTypeSeeder::class, DirectorSeeder::class, AirportSeeder::class]);
    }

    public function test_every_get_page_loads_without_a_server_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_super_admin' => true]);
        $exec = VehicleType::where('slug', 'executive')->first();
        $customer = Customer::factory()->create(['email' => 'cust@example.com', 'phone' => '07700900900']);
        $driver = User::factory()->driver()->create();

        $booking = Booking::factory()->create([
            'customer_id' => $customer->id,
            'vehicle_type_id' => $exec->id,
            'driver_id' => $driver->id,
            'status' => BookingStatus::Allocated,
            'pickup_at' => now()->addDay(),
            'pickup_address' => 'Sheffield S1 2HH',
            'destination_address' => 'Manchester Airport',
            'quoted_price' => 115,
        ]);
        $payment = $booking->payments()->create(['method' => 'card', 'amount' => 115, 'status' => 'pending']);

        // Best-effort richer data so more pages render fully (200), not just 404.
        $accountId = $this->tryMake(function () use ($customer) {
            $a = \App\Models\CorporateAccount::create([
                'name' => 'Acme Ltd', 'is_active' => true, 'account_code' => 'ACME',
            ]);
            $customer->forceFill(['corporate_account_id' => $a->id])->save();

            return $a->id;
        });
        $invoiceId = $this->tryMake(fn () => \App\Models\Invoice::create([
            'corporate_account_id' => $accountId,
            'invoice_number' => 'INV-TEST-1',
            'status' => 'draft',
            'subtotal' => 100, 'vat_amount' => 20, 'total' => 120,
            'issued_at' => now(), 'due_at' => now()->addDays(30),
        ])?->id);

        $params = array_filter([
            'booking' => $booking->id,
            'customer' => $customer->id,
            'user' => $driver->id,
            'payment' => $payment->id,
            'account' => $accountId,
            'invoice' => $invoiceId,
            'token' => $booking->driverLinkToken(),
        ], fn ($v) => $v !== null);

        // Externals / filesystem / non-page endpoints we don't smoke here.
        $skip = ['cet.opcache', 'storage.local'];

        $failures = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $name = (string) $route->getName();
            if ($name !== '' && in_array($name, $skip, true)) {
                continue;
            }

            // Substitute {param} / {param?} with a seeded id, else a dummy (→ 404,
            // which is fine — we only care about 5xx).
            $uri = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => (string) ($params[$m[1]] ?? '999999'), $route->uri());
            if (str_contains($uri, '{')) {
                continue; // unresolved optional/regex param — skip
            }

            try {
                // getStatusCode() works for page, JSON, file and streamed responses.
                $status = $this->actingAs($admin)->get('/'.ltrim($uri, '/'))->getStatusCode();
            } catch (\Throwable $e) {
                $failures[] = $name.' ['.$uri.'] threw '.class_basename($e).': '.$e->getMessage();

                continue;
            }

            if ($status >= 500) {
                $failures[] = $name.' ['.$uri.'] → HTTP '.$status;
            }
        }

        $this->assertSame([], $failures, "Pages returning a server error:\n".implode("\n", $failures));
    }

    /** Create a model, returning its id, or null if its schema needs more than this. */
    private function tryMake(callable $fn): ?int
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return null;
        }
    }
}
