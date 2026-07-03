<?php

namespace Tests\Feature;

use App\Models\AdMetric;
use App\Models\Booking;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminImportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(VehicleTypeSeeder::class);
    }

    public function test_admin_can_upload_a_google_ads_report_in_app(): void
    {
        $admin = User::factory()->admin()->create();
        $csv = "Untitled report\n\"June 4, 2026 - July 3, 2026\"\n"
            ."Campaign,Search keyword,Clicks,Cost,Conversions,Conv. value\n"
            ."Airport,taxi,26,64.15,5,\"1,150.00\"\n";
        $file = UploadedFile::fake()->createWithContent('ads.csv', $csv);

        $this->actingAs($admin)->post(route('imports.ads'), ['file' => $file])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertEquals(1, AdMetric::count());
        $this->assertEquals(64.15, (float) AdMetric::first()->spend);
        $this->assertNotNull(Setting::get('last_ads_import_at'));
    }

    public function test_admin_can_upload_an_eto_export_in_app(): void
    {
        $admin = User::factory()->admin()->create();
        $csv = "Journey date;Reference number;Vehicle type;Status;Total;Payments\n"
            ."24/03/2025 22:05;ZWR6MM;Executive;Completed;200.00;\"Paid, Stripe, 200\"\n";
        $file = UploadedFile::fake()->createWithContent('eto.csv', $csv);

        $this->actingAs($admin)->post(route('imports.eto'), ['file' => $file])
            ->assertRedirect()->assertSessionHas('status');

        $booking = Booking::where('external_reference', 'ZWR6MM')->first();
        $this->assertNotNull($booking);
        $this->assertEquals(200.00, (float) $booking->final_price);
        $this->assertNotNull(Setting::get('last_eto_import_at'));
    }

    public function test_rejects_non_csv(): void
    {
        $admin = User::factory()->admin()->create();
        $file = UploadedFile::fake()->create('report.pdf', 10, 'application/pdf');

        $this->actingAs($admin)->post(route('imports.ads'), ['file' => $file])
            ->assertSessionHasErrors('file');
    }

    public function test_non_admin_cannot_import(): void
    {
        $driver = User::factory()->create(['role' => 'driver']);
        $this->actingAs($driver)->get(route('imports.index'))->assertForbidden();
    }
}
