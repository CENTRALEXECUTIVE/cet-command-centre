<?php

namespace Tests\Feature;

use App\Models\Prospect;
use App\Models\User;
use App\Services\Outreach\OutreachEmailComposer;
use App\Services\Outreach\ProspectImporter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OutreachTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'company,segment,town,website,why_they_need_us,contact_role,contact_name,contact_email,contact_phone,linkedin_url,fit_score,angle,source_url,email_subject,email_body';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-28 10:00:00'));
    }

    private function csv(array $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('prospects.csv', self::HEADER."\n".implode("\n", $rows)."\n");
    }

    private function prospect(array $attrs = []): Prospect
    {
        return Prospect::create($attrs + [
            'company' => 'Acme Steel', 'town' => 'Sheffield', 'contact_name' => 'Jane Smith',
            'contact_email' => 'jane@acme.test', 'email_subject' => 'Visitors to Acme',
            'email_body' => "Hello Jane,\n\nLine two.", 'status' => 'new',
        ]);
    }

    // ── Import ────────────────────────────────────────────────────────────

    public function test_admin_imports_csv_and_owns_new_prospects(): void
    {
        $admin = User::factory()->admin()->create();
        $file = $this->csv([
            'Acme Steel,Manufacturing,Sheffield,acme.test,Lots of visitors,PA to MD,Jane Smith,jane@acme.test,0114 000,https://linkedin.com/in/x,5,Fixed prices,https://news.test/a,Visitors to Acme,"Hello Jane,\n\nWe can help."',
            'Beta Labs,Research,Rotherham,,,,,,,,9,,,,',
        ]);

        $this->actingAs($admin)->post(route('outreach.import'), ['file' => $file])
            ->assertRedirect(route('outreach.index'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, '2 added') && str_contains($s, '0 duplicate'));

        $this->assertSame(2, Prospect::count());
        $acme = Prospect::where('company', 'Acme Steel')->first();
        $this->assertSame($admin->id, $acme->owner_id);
        $this->assertSame('new', $acme->status);
        $this->assertSame(5, $acme->fit_score);
        $this->assertSame("Hello Jane,\n\nWe can help.", $acme->email_body, 'literal \n becomes a real line break');
        $this->assertSame(5, Prospect::where('company', 'Beta Labs')->first()->fit_score, 'fit clamped to 1–5');
        $this->assertSame('imported', $acme->activities()->first()->type);
    }

    public function test_reimport_skips_duplicates_and_never_overwrites_status_or_notes(): void
    {
        $admin = User::factory()->admin()->create();
        $existing = $this->prospect(['status' => 'meeting', 'notes' => 'Met Jane', 'contact_phone' => null, 'segment' => 'Old']);

        $file = $this->csv([
            'ACME  steel,New segment,sheffield,,,,Someone Else,other@acme.test,0114 999,,3,,,New subject,New body',
            'Gamma Ltd,Logistics,Doncaster,,,,,,,,4,,,,',
            'gamma ltd,Logistics,Doncaster,,,,,,,,4,,,,',
        ]);

        $this->actingAs($admin)->post(route('outreach.import'), ['file' => $file])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '1 added') && str_contains($s, '2 duplicate'));

        $existing->refresh();
        $this->assertSame('meeting', $existing->status);
        $this->assertSame('Met Jane', $existing->notes);
        $this->assertSame('Old', $existing->segment, 'filled fields are never overwritten');
        $this->assertSame('Jane Smith', $existing->contact_name);
        $this->assertSame('0114 999', $existing->contact_phone, 'blank fields may be filled');
        $this->assertSame(2, Prospect::count());
    }

    public function test_reimport_never_reopens_or_touches_do_not_contact(): void
    {
        $admin = User::factory()->admin()->create();
        $dnc = $this->prospect(['status' => 'do_not_contact', 'contact_phone' => null]);

        $file = $this->csv([
            'Acme Steel,Manufacturing,Sheffield,,,,,,0114 999,,5,,,Hi,Body',
            // Same DNC person under a differently-spelt company is still DNC.
            'Acme Steel Group,Manufacturing,Sheffield,,,,Jane,JANE@acme.test,,,5,,,Hi,Body',
        ]);

        $this->actingAs($admin)->post(route('outreach.import'), ['file' => $file])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '0 added') && str_contains($s, '2 do-not-contact skipped'));

        $dnc->refresh();
        $this->assertSame('do_not_contact', $dnc->status);
        $this->assertNull($dnc->contact_phone);
        $this->assertSame(1, Prospect::count());
    }

    public function test_import_accepts_the_older_batch_column_names(): void
    {
        $admin = User::factory()->admin()->create();
        $csv = "id,company,track,sector,town_site,why_they_need_us,contact_role,contact_name,source_url,fit_score,angle,status\n"
            ."A01,Rolls-Royce,A,Aerospace manufacturing,\"AMP, Rotherham\",Visitors,PA,,https://x.test,5,Angle,new\n"
            ."B09,Opted Out Co,B,,Leeds,,,,,3,,opted-out\n";

        $this->actingAs($admin)->post(route('outreach.import'), ['file' => UploadedFile::fake()->createWithContent('old.csv', $csv)])
            ->assertSessionHas('status');

        $rr = Prospect::where('company', 'Rolls-Royce')->first();
        $this->assertSame('Aerospace manufacturing', $rr->segment);
        $this->assertSame('AMP, Rotherham', $rr->town);
        $opt = Prospect::where('company', 'Opted Out Co')->first();
        $this->assertSame('Partner operator', $opt->segment);
        $this->assertSame('do_not_contact', $opt->status);
    }

    public function test_artisan_command_imports_with_the_same_rules(): void
    {
        $maj = User::factory()->admin()->create(['email' => 'maj@cet.test']);
        $this->prospect(['status' => 'do_not_contact']);
        $path = tempnam(sys_get_temp_dir(), 'pr');
        file_put_contents($path, self::HEADER."\nAcme Steel,,Sheffield,,,,,,,,,,,,\nNew Co,,Leeds,,,,,,,,,,,,\n");

        $this->artisan('cet:import-prospects', ['path' => $path, '--owner' => 'maj@cet.test'])
            ->expectsOutputToContain('1 added')
            ->assertSuccessful();
        @unlink($path);

        $this->assertSame($maj->id, Prospect::where('company', 'New Co')->value('owner_id'));
        $this->assertSame('do_not_contact', Prospect::where('company', 'Acme Steel')->value('status'));
    }

    public function test_refresh_drafts_only_replaces_unsent_unedited_emails(): void
    {
        $fresh = $this->prospect();
        $sent = $this->prospect(['company' => 'Sent Co', 'status' => 'emailed']);
        $edited = $this->prospect(['company' => 'Edited Co']);
        $edited->log('edited', 'Edited: email_body');
        $dnc = $this->prospect(['company' => 'Gone Co', 'status' => 'do_not_contact']);

        $path = tempnam(sys_get_temp_dir(), 'pr');
        $rows = '';
        foreach (['Acme Steel', 'Sent Co', 'Edited Co', 'Gone Co'] as $c) {
            $rows .= "{$c},,Sheffield,,,,,,,,,,,New subject,\"Hello,\\n\\nNew copy.\"\n";
        }
        file_put_contents($path, self::HEADER."\n".$rows);

        // Plain re-import leaves existing drafts alone.
        $stats = app(ProspectImporter::class)->import($path);
        $this->assertSame(0, $stats['refreshed']);
        $this->assertSame('Visitors to Acme', $fresh->fresh()->email_subject);

        $stats = app(ProspectImporter::class)->import($path, null, null, refreshDrafts: true);
        @unlink($path);

        $this->assertSame(1, $stats['refreshed']);
        $this->assertSame('New subject', $fresh->fresh()->email_subject);
        $this->assertSame("Hello,\n\nNew copy.", $fresh->fresh()->email_body);
        $this->assertSame('Visitors to Acme', $sent->fresh()->email_subject);
        $this->assertSame('Visitors to Acme', $edited->fresh()->email_subject);
        $this->assertSame('Visitors to Acme', $dnc->fresh()->email_subject);
    }

    public function test_company_and_town_are_unique_case_insensitively_in_the_database(): void
    {
        $this->prospect();
        $this->expectException(QueryException::class);
        Prospect::create(['company' => ' acme STEEL ', 'town' => 'SHEFFIELD']);
    }

    // ── mailto ───────────────────────────────────────────────────────────

    public function test_mailto_is_url_encoded_with_crlf_line_breaks(): void
    {
        $p = $this->prospect();
        $url = app(OutreachEmailComposer::class)->mailto($p, 'Fixed prices & tracking?', "Hello Jane,\n\nLine 2 = 100%\\nLine 3");

        $this->assertSame(
            'mailto:jane@acme.test?subject=Fixed%20prices%20%26%20tracking%3F'
            .'&body=Hello%20Jane%2C%0D%0A%0D%0ALine%202%20%3D%20100%25%0D%0ALine%203',
            $url
        );
        $this->assertStringNotContainsString('+', $url);
    }

    public function test_prospect_page_shows_mailto_and_hides_it_for_do_not_contact(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Maj']);
        $p = $this->prospect(['email_body' => "Hello [Name],\n\nHi.\n\n[Signature] · [Opt-out line]"]);

        $this->actingAs($admin)->get(route('outreach.show', $p))
            ->assertOk()
            ->assertSee('mailto:jane@acme.test?subject=Visitors%20to%20Acme&amp;body=Hello%20Jane%2C', false)
            ->assertSee('Open email')
            ->assertSee('Copy email')
            ->assertSee('Mark emailed')
            ->assertSee('Day-4 follow-up')
            ->assertSee('Day-10 follow-up')
            ->assertSee('Directors, Central Executive Transfers');

        // The merged draft expands the kit placeholders (the raw text stays editable).
        $merged = app(OutreachEmailComposer::class)->firstEmail($p->fresh(), $admin)['body'];
        $this->assertStringStartsWith("Hello Jane,\n\nHi.\n\nAbdi & Maj\nDirectors", $merged);
        $this->assertStringNotContainsString('[', $merged);
        $this->assertStringContainsString('just let us know', $merged);

        $p->update(['status' => 'do_not_contact']);
        $this->actingAs($admin)->get(route('outreach.show', $p))
            ->assertOk()
            ->assertSee('Do not contact.')
            ->assertDontSee('mailto:', false)
            ->assertDontSee('Copy email')
            ->assertDontSee('Mark emailed');
    }

    // ── Mark emailed / status ────────────────────────────────────────────

    public function test_mark_emailed_walks_the_sequence_and_sets_dates(): void
    {
        $admin = User::factory()->admin()->create();
        $p = $this->prospect();

        $this->actingAs($admin)->post(route('outreach.emailed', $p))->assertRedirect();
        $p->refresh();
        $this->assertSame('emailed', $p->status);
        $this->assertSame('2026-10-28 10:00', $p->last_contacted_at->format('Y-m-d H:i'));
        $this->assertSame('2026-11-01', $p->next_action_at->toDateString());
        $this->assertSame($admin->id, $p->owner_id, 'whoever sends owns the follow-ups');
        $this->assertSame('emailed', $p->activities()->first()->type);

        $this->travelTo(Carbon::parse('2026-11-01 09:00'));
        $this->actingAs($admin)->post(route('outreach.emailed', $p));
        $p->refresh();
        $this->assertSame('follow_up_1', $p->status);
        $this->assertSame('2026-11-07', $p->next_action_at->toDateString());

        $this->travelTo(Carbon::parse('2026-11-07 09:00'));
        $this->actingAs($admin)->post(route('outreach.emailed', $p));
        $p->refresh();
        $this->assertSame('follow_up_2', $p->status);
        $this->assertSame('2026-11-15', $p->next_action_at->toDateString());
        $this->assertFalse($p->suggestCloseNoReply());

        // Sequence finished — nothing more to mark.
        $this->actingAs($admin)->post(route('outreach.emailed', $p))->assertSessionHasErrors('status');
        $this->assertSame('follow_up_2', $p->fresh()->status);

        // 8 quiet days later → suggest closing as no reply.
        $this->travelTo(Carbon::parse('2026-11-15 09:00'));
        $this->assertTrue($p->fresh()->suggestCloseNoReply());
        $this->actingAs($admin)->get(route('outreach.show', $p))->assertSee('Close — no reply');
        $this->actingAs($admin)->post(route('outreach.status', $p), ['status' => 'closed_no_reply']);
        $this->assertSame('not_interested', $p->fresh()->status);
        $this->assertNull($p->fresh()->next_action_at);
        $this->assertSame(4, $p->activities()->where('type', '!=', 'imported')->count());
    }

    public function test_do_not_contact_is_permanent(): void
    {
        $admin = User::factory()->admin()->create();
        $p = $this->prospect();

        $this->actingAs($admin)->post(route('outreach.status', $p), ['status' => 'do_not_contact']);
        $this->assertSame('do_not_contact', $p->fresh()->status);

        $this->actingAs($admin)->post(route('outreach.emailed', $p))->assertSessionHasErrors('status');
        $this->actingAs($admin)->post(route('outreach.status', $p), ['status' => 'replied'])->assertSessionHasErrors('status');
        $this->actingAs($admin)->put(route('outreach.update', $p), ['company' => 'Acme Steel', 'town' => 'Sheffield', 'status' => 'new']);
        $this->assertSame('do_not_contact', $p->fresh()->status);
        $this->assertNull($p->fresh()->last_contacted_at);
    }

    public function test_replied_counts_for_reply_rate_and_is_due_today(): void
    {
        $admin = User::factory()->admin()->create();
        $p = $this->prospect(['status' => 'emailed', 'last_contacted_at' => now(), 'next_action_at' => '2026-11-01']);
        $this->prospect(['company' => 'Other', 'status' => 'emailed', 'last_contacted_at' => now()]);

        $this->actingAs($admin)->post(route('outreach.status', $p), ['status' => 'replied']);
        $p->refresh();
        $this->assertNotNull($p->replied_at);
        $this->assertSame('2026-10-28', $p->next_action_at->toDateString());

        $this->actingAs($admin)->get(route('outreach.index'))->assertOk()->assertSee('50%');
    }

    public function test_edit_saves_all_fields_and_rejects_a_duplicate_company_town(): void
    {
        $admin = User::factory()->admin()->create();
        $p = $this->prospect();
        $this->prospect(['company' => 'Taken', 'town' => 'Leeds']);

        $this->actingAs($admin)->put(route('outreach.update', $p), [
            'company' => 'Acme Steel', 'town' => 'Sheffield', 'status' => 'proposal', 'fit_score' => 4,
            'contact_phone' => '0114 123', 'next_action_at' => '2026-11-03', 'notes' => 'Sent rate card',
            'owner_id' => $admin->id,
        ])->assertRedirect(route('outreach.show', $p));
        $p->refresh();
        $this->assertSame('proposal', $p->status);
        $this->assertSame('2026-11-03', $p->next_action_at->toDateString(), 'form-set date is kept');
        $this->assertSame('Sent rate card', $p->notes);

        $this->actingAs($admin)->put(route('outreach.update', $p), ['company' => 'TAKEN', 'town' => 'leeds', 'status' => 'new'])
            ->assertSessionHasErrors('company');
    }

    // ── Follow-ups due ───────────────────────────────────────────────────

    public function test_follow_ups_due_lists_today_and_overdue_open_prospects_only(): void
    {
        $admin = User::factory()->admin()->create();
        $this->prospect(['company' => 'Due Today Co', 'status' => 'emailed', 'next_action_at' => '2026-10-28']);
        $this->prospect(['company' => 'Overdue Co', 'status' => 'follow_up_1', 'next_action_at' => '2026-10-20']);
        $this->prospect(['company' => 'Future Co', 'status' => 'emailed', 'next_action_at' => '2026-10-29']);
        $this->prospect(['company' => 'Closed Co', 'status' => 'not_interested', 'next_action_at' => '2026-10-20']);
        $this->prospect(['company' => 'Dnc Co', 'status' => 'do_not_contact', 'next_action_at' => '2026-10-20']);
        $this->prospect(['company' => 'Undated Co', 'status' => 'new']);

        $due = Prospect::followUpsDue()->pluck('company')->sort()->values()->all();
        $this->assertSame(['Due Today Co', 'Overdue Co'], $due);

        $this->actingAs($admin)->get(route('outreach.index'))
            ->assertOk()
            ->assertSee('Follow-ups due today')
            ->assertSeeInOrder(['Follow-ups due today', 'Overdue Co', 'Due Today Co', 'Filter']);
    }

    public function test_index_filters_and_search(): void
    {
        $admin = User::factory()->admin()->create();
        $this->prospect(['company' => 'Alpha Aero', 'segment' => 'Aerospace', 'fit_score' => 5]);
        $this->prospect(['company' => 'Beta Bank', 'segment' => 'Finance', 'fit_score' => 2, 'status' => 'replied']);

        $this->actingAs($admin)->get(route('outreach.index', ['segment' => 'Aerospace']))->assertSee('Alpha Aero')->assertDontSee('Beta Bank');
        $this->actingAs($admin)->get(route('outreach.index', ['status' => 'replied']))->assertSee('Beta Bank')->assertDontSee('Alpha Aero');
        $this->actingAs($admin)->get(route('outreach.index', ['fit' => 4]))->assertSee('Alpha Aero')->assertDontSee('Beta Bank');
        $this->actingAs($admin)->get(route('outreach.index', ['q' => 'beta']))->assertSee('Beta Bank')->assertDontSee('Alpha Aero');
        $this->actingAs($admin)->get(route('outreach.index', ['owner' => 'none']))->assertSee('Alpha Aero');
    }

    // ── Access ───────────────────────────────────────────────────────────

    public function test_outreach_is_admin_only(): void
    {
        $p = $this->prospect();

        $this->get(route('outreach.index'))->assertRedirect(route('login'));

        foreach ([User::factory()->driver()->create(), User::factory()->corporateClient()->create()] as $u) {
            $this->actingAs($u)->get(route('outreach.index'))->assertForbidden();
            $this->actingAs($u)->get(route('outreach.show', $p))->assertForbidden();
            $this->actingAs($u)->post(route('outreach.emailed', $p))->assertForbidden();
            $this->actingAs($u)->post(route('outreach.status', $p), ['status' => 'replied'])->assertForbidden();
            $this->actingAs($u)->post(route('outreach.import'), ['file' => $this->csv(['X,,Y,,,,,,,,,,,,'])])->assertForbidden();
        }
        $this->assertSame('new', $p->fresh()->status);
        $this->assertSame(1, Prospect::count());

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('outreach.index'))->assertOk()->assertSee('Outreach');
    }
}
