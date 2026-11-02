<?php

namespace Tests\Feature;

use App\Models\Prospect;
use App\Models\User;
use App\Services\Outreach\ContactNameCorrections;
use App\Services\Outreach\OutreachEmailComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Greeting fixes: a stale researched name must not greet the wrong person at a
 * general inbox, and a director's own edit must never be overwritten.
 */
class OutreachContactNameCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private function prospect(array $attrs): Prospect
    {
        return Prospect::create($attrs + [
            'segment' => 'Corporate', 'status' => 'new', 'fit_score' => 5,
            'contact_email' => 'enquiries@acme.test', 'email_subject' => 'Corporate chauffeur account',
            'email_body' => "Dear [Name],\n\nBody.",
        ]);
    }

    private function fix(string $company, string $town, string $old, ?string $new): array
    {
        return ['company' => $company, 'town' => $town, 'old_prefix' => $old, 'new_name' => $new, 'reason' => 'test'];
    }

    public function test_stale_name_is_cleared_so_a_general_inbox_gets_dear_sir_or_madam(): void
    {
        $p = $this->prospect(['company' => 'Acme AMRC', 'town' => 'Rotherham', 'contact_name' => 'Prof Ben Morgan (CEO - target his PA)']);

        $stats = app(ContactNameCorrections::class)->apply([$this->fix('Acme AMRC', 'Rotherham', 'Prof Ben Morgan', null)]);

        $p->refresh();
        $this->assertSame(1, $stats['changed']);
        $this->assertNull($p->contact_name);
        $this->assertSame('new', $p->status, 'status untouched');
        $this->assertStringContainsString('Dear Sir or Madam,', app(OutreachEmailComposer::class)->merge($p->email_body, $p, User::factory()->admin()->create()));
        $this->assertStringContainsString('Greeting fix', $p->activities()->first()->body);
    }

    public function test_stale_name_is_replaced_by_the_named_owner_of_the_address(): void
    {
        $p = $this->prospect(['company' => 'Law LLP', 'town' => 'Sheffield', 'contact_name' => 'Petra Billing named office managing partner']);

        app(ContactNameCorrections::class)->apply([$this->fix('Law LLP', 'Sheffield', 'Petra Billing', 'Amanda Pilkington')]);

        $this->assertSame('Amanda', $p->refresh()->firstName());
    }

    public function test_a_directors_edit_and_do_not_contact_are_never_touched(): void
    {
        $edited = $this->prospect(['company' => 'Edited Ltd', 'town' => 'Leeds', 'contact_name' => 'Jane Director-Chosen']);
        $dnc = $this->prospect(['company' => 'Gone Ltd', 'town' => 'Leeds', 'contact_name' => 'Bridget Ball', 'status' => 'do_not_contact']);

        $stats = app(ContactNameCorrections::class)->apply([
            $this->fix('Edited Ltd', 'Leeds', 'Bridget Ball', null),
            $this->fix('Gone Ltd', 'Leeds', 'Bridget Ball', null),
            $this->fix('Missing Ltd', 'Leeds', 'Bridget Ball', null),
        ]);

        $this->assertSame(0, $stats['changed']);
        $this->assertSame(3, $stats['skipped']);
        $this->assertSame('Jane Director-Chosen', $edited->refresh()->contact_name);
        $this->assertSame('Bridget Ball', $dnc->refresh()->contact_name);
    }
}
