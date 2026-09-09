<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `cet:alert-call-status` diagnostic reports whether the emergency at-risk
 * auto-call is wired up — without placing a call or printing any secret.
 */
class AlertCallStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_fully_wired_up_when_twilio_and_a_mobile_are_set(): void
    {
        config([
            'services.twilio.sid' => 'AC', 'services.twilio.token' => 'tok',
            'services.twilio_masking.driver_line' => '+447576574083',
            'cet.alert_call_from' => null,
            'cet.office_call_number' => '+447405172435',
        ]);
        User::factory()->admin()->create(['name' => 'Abdi', 'phone' => '+447534283126']);

        $this->artisan('cet:alert-call-status')
            ->expectsOutputToContain('driver line (TWILIO_DRIVER_LINE)')
            ->expectsOutputToContain('•••• 4083')      // from, masked to last 4
            ->expectsOutputToContain('•••• 3126')      // director's mobile, masked
            ->expectsOutputToContain('fully wired up')
            ->assertSuccessful();
    }

    public function test_it_reports_not_active_when_twilio_is_missing(): void
    {
        config([
            'services.twilio.sid' => null, 'services.twilio.token' => null,
            'services.twilio_masking.driver_line' => null,
            'services.twilio_masking.customer_line' => null,
            'services.twilio_masking.proxy_number' => null,
            'cet.alert_call_from' => null,
        ]);

        $this->artisan('cet:alert-call-status')
            ->expectsOutputToContain('NOT active yet')
            ->assertSuccessful();
    }

    public function test_it_never_prints_a_full_number(): void
    {
        config([
            'services.twilio.sid' => 'AC', 'services.twilio.token' => 'tok',
            'services.twilio_masking.driver_line' => '+447576574083',
            'cet.office_call_number' => '+447405172435',
        ]);
        User::factory()->admin()->create(['name' => 'Maj', 'phone' => '+447730437557']);

        $this->artisan('cet:alert-call-status')
            ->doesntExpectOutputToContain('447576574083')
            ->doesntExpectOutputToContain('447730437557')
            ->assertSuccessful();
    }
}
