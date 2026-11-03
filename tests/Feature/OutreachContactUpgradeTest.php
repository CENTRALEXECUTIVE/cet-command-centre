<?php

namespace Tests\Feature;

use App\Models\Prospect;
use App\Models\User;
use App\Services\Outreach\ContactUpgrader;
use App\Services\Outreach\OutreachEmailComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Better published contacts (DocFest: production@ + Joanna Hunter on CC) replace info@ safely. */
class OutreachContactUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private function prospect(array $attrs = []): Prospect
    {
        return Prospect::create($attrs + [
            'company' => 'Sheffield DocFest', 'town' => 'Sheffield', 'segment' => 'Events', 'status' => 'new', 'fit_score' => 5,
            'contact_email' => 'info@sheffdocfest.com', 'email_subject' => 'Transport for DocFest guests',
            'email_body' => "Dear [Name],\n\nBody.\n\nKind regards,\n[Signature]\n\n[Opt-out line]",
        ]);
    }

    private function row(array $o = []): array
    {
        return $o + ['company' => 'Sheffield DocFest', 'town' => 'Sheffield', 'primary_email' => 'production@sheffdocfest.com',
            'primary_type' => 'department', 'contact_name' => '', 'contact_role' => 'Festival production team',
            'cc_email' => 'joanna.hunter@sheffdocfest.com', 'cc_name' => 'Joanna Hunter',
            'source_url' => 'https://www.sheffdocfest.com/contact', 'why' => 'production inbox handles suppliers'];
    }

    public function test_department_inbox_with_cc_replaces_the_general_inbox(): void
    {
        $p = $this->prospect();
        $stats = ['upgraded' => 0, 'skipped' => 0, 'unmatched' => 0];
        app(ContactUpgrader::class)->apply($this->row(), null, $stats);

        $p->refresh();
        $this->assertSame(1, $stats['upgraded']);
        $this->assertSame('production@sheffdocfest.com', $p->contact_email);
        $this->assertSame('joanna.hunter@sheffdocfest.com', $p->cc_email);
        $this->assertSame('new', $p->status);

        $c = app(OutreachEmailComposer::class);
        $first = $c->firstEmail($p, User::factory()->admin()->create());
        $this->assertStringStartsWith('Dear Sheffield DocFest Team,', $first['body']);
        $mailto = $c->mailto($p, $first['subject'], $first['body']);
        $this->assertStringStartsWith('mailto:production@sheffdocfest.com?', $mailto);
        $this->assertStringContainsString('&cc=joanna.hunter@sheffdocfest.com', $mailto);
    }

    public function test_named_person_is_greeted_by_first_name(): void
    {
        $p = $this->prospect();
        $stats = ['upgraded' => 0, 'skipped' => 0, 'unmatched' => 0];
        app(ContactUpgrader::class)->apply($this->row(['primary_type' => 'named', 'primary_email' => 'joanna.hunter@sheffdocfest.com',
            'contact_name' => 'Joanna Hunter', 'cc_email' => '']), null, $stats);

        $body = app(OutreachEmailComposer::class)->firstEmail($p->fresh(), User::factory()->admin()->create())['body'];
        $this->assertStringStartsWith('Dear Joanna,', $body);
    }

    public function test_emailed_dnc_and_director_edited_prospects_are_never_touched(): void
    {
        $emailed = $this->prospect(['company' => 'A Co', 'status' => 'contacted']);
        $dnc = $this->prospect(['company' => 'B Co', 'status' => 'do_not_contact']);
        $edited = $this->prospect(['company' => 'C Co']);
        $edited->log('edited', 'Edited: contact_email');

        $stats = ['upgraded' => 0, 'skipped' => 0, 'unmatched' => 0];
        foreach (['A Co', 'B Co', 'C Co'] as $co) {
            app(ContactUpgrader::class)->apply($this->row(['company' => $co]), null, $stats);
        }
        app(ContactUpgrader::class)->apply($this->row(['company' => 'Nobody Ltd']), null, $stats);

        $this->assertSame(0, $stats['upgraded']);
        $this->assertSame(1, $stats['unmatched']);
        foreach ([$emailed, $dnc, $edited] as $p) {
            $this->assertSame('info@sheffdocfest.com', $p->fresh()->contact_email);
        }
    }
}
