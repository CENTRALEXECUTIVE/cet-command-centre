<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Services\Assistant\AssistantBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AssistantBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_assistant_shows_jobs_prepaid_drivers_share_money_and_todos(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 09:00:00'));
        $director = User::factory()->admin()->create(['is_super_admin' => true, 'name' => 'Abdi']);
        $driver = User::factory()->driver()->create(['name' => 'Kash']);

        Booking::factory()->create(['pickup_at' => '2026-11-10 15:00:00', 'driver_id' => null, 'final_price' => 100]);
        // Prepaid future jobs: one external (unallocated), one the director's own.
        Booking::factory()->create(['pickup_at' => '2026-11-20 10:00:00', 'final_price' => 200, 'payment_status' => 'paid', 'driver_id' => null]);
        Booking::factory()->create(['pickup_at' => '2026-11-21 10:00:00', 'final_price' => 100, 'payment_status' => 'paid', 'driver_id' => $director->id]);
        Booking::factory()->create(['pickup_at' => '2026-11-22 10:00:00', 'final_price' => 50, 'payment_status' => 'pending', 'driver_id' => $driver->id]);

        $b = app(AssistantBoard::class)->build();
        $this->assertSame(1, $b['today']->count());
        $this->assertSame(2, $b['prepaid']['jobs']);
        $this->assertEquals(180.0, $b['prepaid']['external_share']);
        $this->assertEquals(90.0, $b['prepaid']['director_share']);

        $this->actingAs($director)->post(route('assistant.money'), [
            'ltd' => '296.16', 'pvt' => '593.72', 'incoming' => '177.10',
            'owed_to_us' => "Company X - 620", 'we_owe' => "Abdi last week - 900\nAbdi older £1,000",
        ])->assertRedirect(route('assistant.index'));
        $m = AssistantBoard::money();
        $this->assertEquals(620.0, $m['owed_to_us'][0]['amount']);
        $this->assertEquals(1000.0, $m['we_owe'][1]['amount']);

        $this->actingAs($director)->post(route('assistant.todos.add'), ['text' => 'Chase the £620', 'due' => '2026-11-11']);
        $id = AssistantBoard::todos()[0]['id'];
        $this->actingAs($director)->post(route('assistant.todos.toggle', $id));
        $this->assertTrue(AssistantBoard::todos()[0]['done']);

        $this->actingAs($director)->get(route('assistant.index'))->assertOk()
            ->assertSee('My assistant')->assertSee('Chase the £620')->assertSee('£889.88')->assertSee('£180.00')
            ->assertSee('no driver', false);

        $this->actingAs($director)->post(route('assistant.todos.delete', $id));
        $this->assertSame([], AssistantBoard::todos());
    }

    public function test_drivers_cannot_open_it(): void
    {
        $driver = User::factory()->driver()->create();
        $this->actingAs($driver)->get(route('assistant.index'))->assertStatus(403);
        $this->actingAs($driver)->post(route('assistant.money'), ['ltd' => 1])->assertStatus(403);
    }
}
