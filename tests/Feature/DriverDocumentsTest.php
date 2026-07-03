<?php

namespace Tests\Feature;

use App\Models\DriverDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DriverDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private function driver(): User
    {
        return User::factory()->create(['role' => 'driver']);
    }

    public function test_documents_page_shows_a_card_for_every_required_type(): void
    {
        $driver = $this->driver();

        $response = $this->actingAs($driver)->get(route('driver.documents'));

        $response->assertOk();
        foreach (DriverDocument::TYPES as $meta) {
            $response->assertSee($meta['label']);
        }
        $response->assertSee('Upload required'); // nothing uploaded yet
    }

    public function test_driver_can_upload_a_document_pending_review(): void
    {
        Storage::fake('local');
        $driver = $this->driver();

        $this->actingAs($driver)->post(route('driver.documents.store'), [
            'type' => 'mot',
            'file' => UploadedFile::fake()->create('mot.pdf', 200, 'application/pdf'),
            'expiry_date' => now()->addDays(40)->toDateString(),
        ])->assertRedirect();

        $doc = DriverDocument::where('user_id', $driver->id)->where('type', 'mot')->first();
        $this->assertNotNull($doc);
        $this->assertEquals('pending', $doc->status);
        $this->assertEquals('pending', $doc->cardStatus()); // uploaded → awaits admin review
        Storage::disk('local')->assertExists($doc->file_path);
    }

    public function test_days_left_status_colours(): void
    {
        $expired = new DriverDocument(['type' => 'mot', 'file_path' => 'x', 'status' => 'approved', 'expiry_date' => now()->subDay()]);
        $soon = new DriverDocument(['type' => 'mot', 'file_path' => 'x', 'status' => 'approved', 'expiry_date' => now()->addDays(6)]);
        $ok = new DriverDocument(['type' => 'mot', 'file_path' => 'x', 'status' => 'approved', 'expiry_date' => now()->addDays(200)]);

        $this->assertEquals('expired', $expired->cardStatus());
        $this->assertEquals('expiring', $soon->cardStatus());
        $this->assertEquals('valid', $ok->cardStatus());
    }

    public function test_a_driver_cannot_download_another_drivers_file(): void
    {
        Storage::fake('local');
        $owner = $this->driver();
        $other = $this->driver();
        $this->actingAs($owner)->post(route('driver.documents.store'), [
            'type' => 'badge',
            'file' => UploadedFile::fake()->create('badge.jpg', 100, 'image/jpeg'),
        ]);
        $doc = DriverDocument::where('user_id', $owner->id)->first();

        $this->actingAs($other)->get(route('driver.documents.file', $doc))->assertForbidden();
        $this->actingAs($owner)->get(route('driver.documents.file', $doc))->assertOk();
    }
}
