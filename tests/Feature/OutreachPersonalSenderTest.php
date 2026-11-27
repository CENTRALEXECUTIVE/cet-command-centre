<?php

namespace Tests\Feature;

use App\Mail\OutreachMail;
use Tests\TestCase;

/** Personal warm-lead emails go out as Abdi, not Matthew Green (27 Nov 2026). */
class OutreachPersonalSenderTest extends TestCase
{
    public function test_a_personal_email_uses_abdis_name_and_signature(): void
    {
        $m = new OutreachMail('Executive travel', "Dear Matt,\n\nHello.\n\nKind regards,", null,
            'Abdi | Central Executive Transfers', ['name' => 'Abdi', 'company' => 'Director, Central Executive Transfers']);
        $this->assertSame('Abdi | Central Executive Transfers', $m->envelope()->from->name);
        $html = $m->render();
        $this->assertStringContainsString('<strong>Abdi</strong>', $html);
        $this->assertStringNotContainsString('Matthew Green', $html);

        // The normal outreach email is unchanged.
        $this->assertStringContainsString('Matthew Green', (new OutreachMail('Executive travel', 'Dear Team,'))->render());
    }
}
