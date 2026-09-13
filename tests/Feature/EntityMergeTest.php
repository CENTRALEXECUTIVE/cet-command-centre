<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CorporateAccount;
use App\Models\Customer;
use App\Services\Reporting\ReportService;
use App\Services\Reporting\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The customer leaderboard counts the SAME person once even when booked under
 * name variants or separate records (matched by name / email / phone), rolls a
 * business's people up by email domain, and can be ordered by jobs or revenue.
 */
class EntityMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // corporate name map is cached
    }

    private function ranJob(int $customerId, float $price): Booking
    {
        return Booking::factory()->create([
            'customer_id' => $customerId,
            'pickup_at' => now()->subDays(2),
            'final_price' => $price,
        ]);
    }

    private function entities()
    {
        return app(ReportService::class)->entities(now()->subMonth(), now());
    }

    public function test_a_title_variant_of_the_same_name_merges_into_one_row(): void
    {
        // Same person, one record with a title — no email/phone, so matched by name.
        $plain = Customer::factory()->create(['name' => 'Karl Walton', 'email' => null, 'phone' => null]);
        $titled = Customer::factory()->create(['name' => 'Dr Karl Walton', 'email' => null, 'phone' => null]);
        $this->ranJob($plain->id, 100);
        $this->ranJob($titled->id, 150);

        $rows = $this->entities()->where('type', 'customer');
        $walton = $rows->firstWhere('name', 'Karl Walton');

        $this->assertNotNull($walton, 'the two Karl Walton records should be one row');
        $this->assertSame(2, $walton['jobs']);
        $this->assertSame(250.0, $walton['revenue']);
        $this->assertNull($rows->firstWhere('name', 'Dr Karl Walton'));
    }

    public function test_records_sharing_an_email_merge_even_with_different_names(): void
    {
        // "Richard" and "Richard Mauer" are the same person on one email.
        $a = Customer::factory()->create(['name' => 'Richard', 'email' => 'richard@example.com', 'phone' => null]);
        $b = Customer::factory()->create(['name' => 'Richard Mauer', 'email' => 'richard@example.com', 'phone' => null]);
        $this->ranJob($a->id, 90);
        $this->ranJob($b->id, 110);

        $row = $this->entities()->firstWhere('id', $a->id) ?? $this->entities()->firstWhere('id', $b->id);
        $this->assertSame(2, $row['jobs']);
        $this->assertSame(200.0, $row['revenue']);
        // The fuller name is shown.
        $this->assertSame('Richard Mauer', $row['name']);
    }

    public function test_a_traveller_on_the_business_email_domain_rolls_into_the_business(): void
    {
        $jw = CorporateAccount::create([
            'name' => 'JELD-WEN', 'slug' => 'jw', 'account_code' => 'JW',
            'billing_email' => 'accounts@jeld-wen.com', 'is_active' => true,
        ]);
        Cache::forget('corporate_name_map');

        // Not tagged to the account, but their email is on the business domain.
        $iria = Customer::factory()->create(['name' => 'Iria Lago', 'email' => 'iria.lago@jeld-wen.com']);
        $this->ranJob($iria->id, 320);

        $rows = $this->entities();
        $business = $rows->firstWhere('name', 'JELD-WEN');
        $this->assertNotNull($business, 'a @jeld-wen traveller should roll into JELD-WEN');
        $this->assertSame('business', $business['type']);
        $this->assertNull($rows->firstWhere('name', 'Iria Lago'));
    }

    public function test_domain_is_learned_from_a_customer_already_tagged_to_the_account(): void
    {
        // Account has NO billing email and NO contacts — but one customer tagged to
        // it uses the company email, so its domain is learned and rolls in the rest.
        $jw = CorporateAccount::create(['name' => 'JELD-WEN', 'slug' => 'jw', 'account_code' => 'JW', 'is_active' => true]);
        $tagged = Customer::factory()->create(['name' => 'TJ Curran', 'email' => 'tj@jeld-wen.com', 'corporate_account_id' => $jw->id]);
        $this->ranJob($tagged->id, 100);
        Cache::forget('corporate_name_map');

        // A different, untagged @jeld-wen traveller.
        $untagged = Customer::factory()->create(['name' => 'Lucy Weaver', 'email' => 'lucy.weaver@jeld-wen.com']);
        $this->ranJob($untagged->id, 220);

        $rows = $this->entities();
        $business = $rows->firstWhere('name', 'JELD-WEN');
        $this->assertNotNull($business);
        $this->assertSame(2, $business['jobs']);          // both rolled in
        $this->assertNull($rows->firstWhere('name', 'Lucy Weaver'));
    }

    public function test_the_leaderboard_can_be_ordered_by_jobs(): void
    {
        // Big revenue, few jobs vs small revenue, many jobs.
        $whale = Customer::factory()->create(['name' => 'Big Spender', 'email' => 'whale@x.com', 'phone' => null]);
        $this->ranJob($whale->id, 5000);

        $regular = Customer::factory()->create(['name' => 'Frequent Flyer', 'email' => 'freq@x.com', 'phone' => null]);
        foreach (range(1, 4) as $i) {
            $this->ranJob($regular->id, 50);
        }

        $byJobs = app(ReviewService::class)->build(now()->subMonth(), now(), 'jobs', 'desc');
        $this->assertSame('Frequent Flyer', $byJobs['topCustomers']->first()['name']);

        $byRevenue = app(ReviewService::class)->build(now()->subMonth(), now(), 'revenue', 'desc');
        $this->assertSame('Big Spender', $byRevenue['topCustomers']->first()['name']);
    }
}
