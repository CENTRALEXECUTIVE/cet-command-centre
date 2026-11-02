<?php

namespace Tests\Feature;

use App\Models\Prospect;
use App\Models\User;
use App\Services\Outreach\ProspectImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * docs/growth/sales/contacts.csv: published contact emails for prospects that
 * already exist. Fill-only — it must never create prospects, never overwrite an
 * address, never touch do-not-contact, and the email must show on /outreach.
 */
class OutreachContactsImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'ref,company,town,fit_score,contact_name,contact_email,email_type,email_source_url';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));
    }

    private function file(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'contacts');
        file_put_contents($path, self::HEADER."\n".implode("\n", $rows)."\n");

        return $path;
    }

    private function prospect(array $attrs): Prospect
    {
        return Prospect::create($attrs + [
            'segment' => 'Manufacturing', 'status' => 'new', 'fit_score' => 3, 'email_body' => 'Body',
        ]);
    }

    public function test_contacts_file_fills_blank_emails_on_existing_prospects_only(): void
    {
        $acme = $this->prospect(['company' => 'Acme Steel', 'town' => 'Brightside Lane, Sheffield', 'contact_email' => null, 'contact_name' => null]);
        $kept = $this->prospect(['company' => 'Beta Labs', 'town' => 'Rotherham', 'contact_email' => 'events@beta.test']);
        $dnc = $this->prospect(['company' => 'Gone Ltd', 'town' => 'Leeds', 'contact_email' => null, 'status' => 'do_not_contact']);

        $path = $this->file([
            'A01,Acme Steel,"Brightside Lane, Sheffield",5,,enquiries@acme.test,general,https://acme.test/contact',
            'A02,Beta Labs,Rotherham,4,,other@beta.test,general,https://beta.test/contact',
            'A03,Gone Ltd,Leeds,3,,info@gone.test,general,https://gone.test/contact',
            'A04,Not In The App Ltd,Doncaster,5,,hello@new.test,general,https://new.test/contact',
            'A05,Acme Steel,Somewhere Else,5,,wrong@acme.test,general,https://acme.test/contact',
        ]);

        $stats = app(ProspectImporter::class)->import($path);

        $this->assertSame('enquiries@acme.test', $acme->refresh()->contact_email);
        $this->assertSame(3, $acme->fit_score, 'an existing fit score is never overwritten');
        $this->assertSame('events@beta.test', $kept->refresh()->contact_email, 'an existing address is never overwritten');
        $this->assertNull($dnc->refresh()->contact_email, 'do-not-contact is never touched');
        $this->assertSame('do_not_contact', $dnc->status);
        $this->assertSame(3, Prospect::count(), 'a contacts file never creates prospects');
        $this->assertSame(0, $stats['created']);
        $this->assertSame(2, $stats['unmatched']);
        $this->assertSame(1, $stats['filled']);
        $this->assertSame(1, $stats['dnc_skipped']);

        $log = $acme->activities()->first();
        $this->assertSame('edited', $log->type);
        $this->assertStringContainsString('contact_email', $log->body);
        $this->assertStringContainsString('https://acme.test/contact', $log->body, 'the published source is recorded');
        $this->assertTrue($acme->canEmail());
        $this->assertSame('new', $acme->status, 'status is never changed');
    }

    public function test_named_contact_fills_a_blank_name_and_drafts_are_not_treated_as_hand_edited(): void
    {
        $p = $this->prospect(['company' => 'Acme Steel', 'town' => 'Sheffield', 'contact_email' => null, 'contact_name' => null]);

        app(ProspectImporter::class)->import($this->file([
            'A01,Acme Steel,Sheffield,5,Jane Smith,jane.smith@acme.test,named,https://acme.test/team',
        ]));

        $p->refresh();
        $this->assertSame('Jane Smith', $p->contact_name);
        $this->assertSame('jane.smith@acme.test', $p->contact_email);
        $this->assertStringStartsWith('Re-import', $p->activities()->first()->body, 'so a later draft refresh still applies');
    }

    public function test_uploading_a_contacts_file_on_outreach_is_fill_only_and_the_email_shows_in_the_list(): void
    {
        $admin = User::factory()->admin()->create();
        $this->prospect(['company' => 'Acme Steel', 'town' => 'Sheffield', 'contact_email' => null]);

        $csv = self::HEADER."\n"
            ."A01,Acme Steel,Sheffield,5,,enquiries@acme.test,general,https://acme.test/contact\n"
            ."A02,Stray Co,Leeds,3,,info@stray.test,general,https://stray.test\n";

        $this->actingAs($admin)
            ->post(route('outreach.import'), ['file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv)])
            ->assertRedirect(route('outreach.index'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, '0 added') && str_contains($s, '1 unmatched'));

        $this->assertSame(1, Prospect::count());

        $this->actingAs($admin)->get(route('outreach.index'))
            ->assertOk()
            ->assertSee('enquiries@acme.test');
    }

    public function test_do_not_contact_email_is_not_shown_in_the_list(): void
    {
        $admin = User::factory()->admin()->create();
        $this->prospect(['company' => 'Gone Ltd', 'town' => 'Leeds', 'contact_email' => 'info@gone.test', 'status' => 'do_not_contact']);

        $this->actingAs($admin)->get(route('outreach.index'))
            ->assertOk()
            ->assertDontSee('info@gone.test');
    }

    public function test_the_committed_contacts_file_is_well_formed(): void
    {
        $path = base_path('docs/growth/sales/contacts.csv');
        $this->assertFileExists($path);

        $fh = fopen($path, 'r');
        $header = fgetcsv($fh, 0, ',', '"', '');
        foreach (['ref', 'company', 'town', 'contact_email', 'email_type', 'email_source_url'] as $col) {
            $this->assertContains($col, $header);
        }
        $idx = array_flip($header);
        $line = 1;
        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $line++;
            if ($row === [null]) {
                continue;
            }
            $email = $row[$idx['contact_email']] ?? '';
            $type = $row[$idx['email_type']] ?? '';
            $src = $row[$idx['email_source_url']] ?? '';
            if ($email === '') {
                $this->assertSame('', $type, "line {$line}: a type without an email");
                continue;
            }
            $this->assertNotFalse(filter_var($email, FILTER_VALIDATE_EMAIL), "line {$line}: invalid email {$email}");
            $this->assertContains($type, ['named', 'role', 'general'], "line {$line}: email_type");
            $this->assertMatchesRegularExpression('#^https?://#', $src, "line {$line}: every email needs its source URL");
        }
        fclose($fh);
    }
}
