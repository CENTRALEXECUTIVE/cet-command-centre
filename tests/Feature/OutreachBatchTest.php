<?php

namespace Tests\Feature;

use App\Mail\OutreachMail;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OutreachBatchTest extends TestCase
{
    use RefreshDatabase;

    private function prospect(array $a = []): Prospect
    {
        static $n = 0;
        $n++;

        return Prospect::create($a + [
            'company' => 'Firm '.$n, 'town' => 'Sheffield', 'contact_email' => "firm{$n}@example.test",
            'email_subject' => 'Executive travel', 'email_body' => "Dear [Name],\n\nHello.\n\nKind regards,",
            'status' => 'new', 'fit_score' => 3,
        ]);
    }

    public function test_new_emails_go_to_businesses_first_with_at_most_one_partner_a_day(): void
    {
        $this->travelTo(Carbon::parse('2026-11-12 08:00'));
        config(['outreach.batch.daily_limit' => 5, 'outreach.batch.max_new_partners' => 1]);
        foreach (['Manchester chauffeur company', 'Travel management company (TMC)', 'Partner - global chauffeur network', 'Airport transfer platform'] as $seg) {
            $this->prospect(['segment' => $seg, 'fit_score' => 5]);   // partners score higher…
        }
        foreach (['Law', 'Accountancy', 'Steel castings (rail, defence)', 'Private hospital', 'Council home-to-school (SEND) transport'] as $seg) {
            $this->prospect(['segment' => $seg, 'fit_score' => 4]);   // …but businesses come first
        }

        $batch = app(\App\Services\Outreach\OutreachBatch::class)->candidates();
        $this->assertCount(5, $batch);
        $this->assertSame(1, $batch->filter->isPartner()->count(), 'one partner a day, max');
        $this->assertSame(4, $batch->reject->isPartner()->count());
        $this->assertFalse(Prospect::where('segment', 'Council home-to-school (SEND) transport')->first()->isPartner(), 'a council is a client, not a partner');
    }

    public function test_batch_page_lists_due_follow_ups_first_then_best_new_prospects_up_to_the_limit(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00')); // Tuesday
        config(['outreach.batch.daily_limit' => 3]);
        $admin = User::factory()->admin()->create();
        $due = $this->prospect(['company' => 'Due Ltd', 'status' => 'emailed', 'next_action_at' => '2026-11-09']);
        $this->prospect(['company' => 'Low Fit', 'fit_score' => 1]);
        $this->prospect(['company' => 'Top Fit', 'fit_score' => 5]);
        $this->prospect(['company' => 'Mid Fit', 'fit_score' => 4]);
        $this->prospect(['company' => 'No Email', 'contact_email' => null, 'fit_score' => 5]);
        $this->prospect(['company' => 'Opted Out', 'status' => 'do_not_contact', 'fit_score' => 5]);

        $this->actingAs($admin)->get(route('outreach.batch'))->assertOk()
            ->assertSeeInOrder(['Due Ltd', 'Top Fit', 'Mid Fit'])
            ->assertDontSee('Low Fit')->assertDontSee('No Email')->assertDontSee('Opted Out')
            ->assertSee('Day-4 follow-up');
    }

    public function test_approving_sends_with_signature_and_moves_the_sequence_on_weekdays_only(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-10 10:00')); // Tuesday
        $admin = User::factory()->admin()->create(['name' => 'Abdi']);
        $p = $this->prospect(['company' => 'Acme', 'contact_name' => 'Jane Smith', 'contact_email' => 'jane.smith@example.test', 'cc_email' => 'boss@example.test']);

        $this->actingAs($admin)->post(route('outreach.batch.send'), ['ids' => [$p->id]])
            ->assertSessionHasErrors('confirm');
        Mail::assertNothingSent();

        $this->actingAs($admin)->post(route('outreach.batch.send'), ['ids' => [$p->id], 'confirm' => '1'])
            ->assertRedirect(route('outreach.batch'));

        Mail::assertSent(OutreachMail::class, function (OutreachMail $m) use ($p) {
            $html = $m->render();

            return $m->hasTo($p->contact_email)
                && $m->hasCc('boss@example.test')
                && str_contains($m->bodyText, 'Dear Jane,')
                && str_contains($html, 'Matthew Green')
                && str_contains($html, 'cet-email-signature.png');
        });
        $p->refresh();
        $this->assertSame('emailed', $p->status);
        $this->assertSame('2026-11-16', $p->next_action_at->toDateString(), 'Tue + 4 = Sat, so Monday');
        $this->assertStringStartsWith('Sent from the Command Centre', $p->activities()->first()->body);

        // Weekend: nothing sends.
        $q = $this->prospect(['company' => 'Weekend Ltd']);
        $this->travelTo(Carbon::parse('2026-11-14 10:00')); // Saturday
        $this->actingAs($admin)->get(route('outreach.batch'))->assertSee("It's the weekend", false);
        $this->actingAs($admin)->post(route('outreach.batch.send'), ['ids' => [$q->id], 'confirm' => '1']);
        Mail::assertSentCount(1);
        $this->assertSame('new', $q->fresh()->status);
    }

    public function test_daily_limit_holds_across_the_day_and_unlisted_ids_are_ignored(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        config(['outreach.batch.daily_limit' => 2]);
        $admin = User::factory()->admin()->create();
        $a = $this->prospect(['fit_score' => 5]);
        $b = $this->prospect(['fit_score' => 5]);
        $c = $this->prospect(['fit_score' => 5]);
        $dnc = $this->prospect(['status' => 'do_not_contact']);

        $this->actingAs($admin)->post(route('outreach.batch.send'), ['ids' => [$a->id, $dnc->id], 'confirm' => '1']);
        $this->actingAs($admin)->post(route('outreach.batch.send'), ['ids' => [$b->id, $c->id], 'confirm' => '1']);

        Mail::assertSentCount(2);
        $this->assertSame('do_not_contact', $dnc->fresh()->status);
        $this->actingAs($admin)->get(route('outreach.batch'))->assertSee('Nothing to send right now');
    }

    public function test_drivers_cannot_open_or_send(): void
    {
        $driver = User::factory()->driver()->create();
        $this->actingAs($driver)->get(route('outreach.batch'))->assertStatus(403);
        $this->actingAs($driver)->post(route('outreach.batch.send'), ['ids' => [1], 'confirm' => '1'])->assertStatus(403);
    }

    public function test_the_batch_is_mostly_new_with_at_most_three_follow_ups(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00')); // Tuesday
        foreach (range(1, 5) as $i) {
            $this->prospect(['status' => 'emailed', 'next_action_at' => '2026-11-09', 'email_subject' => 'Hi']);
        }
        foreach (range(1, 12) as $i) {
            $this->prospect();
        }

        $batch = app(\App\Services\Outreach\OutreachBatch::class)->candidates();

        $this->assertCount(10, $batch);
        $this->assertSame(3, $batch->where('status', 'emailed')->count());
        $this->assertSame(7, $batch->where('status', 'new')->count());
    }

    public function test_approving_spaced_sends_one_every_five_minutes_and_rechecks_each(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-10 10:00')); // Tuesday
        $admin = User::factory()->admin()->create(['name' => 'Abdi']);
        $a = $this->prospect(['company' => 'Alpha', 'fit_score' => 5]);
        $b = $this->prospect(['company' => 'Bravo', 'fit_score' => 4]);
        $c = $this->prospect(['company' => 'Charlie', 'fit_score' => 3]);

        $this->actingAs($admin)->post(route('outreach.batch.send'), ['ids' => [$a->id, $b->id, $c->id], 'confirm' => '1', 'spacing' => 5])
            ->assertRedirect(route('outreach.batch'));
        Mail::assertNothingSent();

        // Queued ones leave the batch list and show as waiting.
        $this->actingAs($admin)->get(route('outreach.batch'))->assertOk()
            ->assertSee('Approved, sending one at a time')->assertSee('10:00')->assertSee('10:05')->assertSee('10:10');

        $this->artisan('cet:outreach-send-queued')->assertSuccessful();
        Mail::assertSent(OutreachMail::class, 1);
        $this->assertSame('emailed', $a->fresh()->status);

        $this->travelTo(Carbon::parse('2026-11-10 10:05'));
        // Bravo was marked replied by hand meanwhile: it must NOT be sent.
        $b->forceFill(['status' => 'replied'])->save();
        $this->artisan('cet:outreach-send-queued');
        Mail::assertSent(OutreachMail::class, 1);

        $this->travelTo(Carbon::parse('2026-11-10 10:10'));
        $this->artisan('cet:outreach-send-queued');
        Mail::assertSent(OutreachMail::class, 2);
        $this->assertSame('emailed', $c->fresh()->status);
        $this->assertSame([], app(\App\Services\Outreach\OutreachBatch::class)->queued());

        // Running again sends nothing twice.
        $this->artisan('cet:outreach-send-queued');
        Mail::assertSent(OutreachMail::class, 2);
    }
}
