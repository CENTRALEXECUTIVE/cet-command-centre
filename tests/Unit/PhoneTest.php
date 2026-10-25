<?php

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    /**
     * @dataProvider numbers
     */
    public function test_it_normalises_to_wa_international_digits(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, Phone::wa($input));
    }

    public static function numbers(): array
    {
        return [
            'uk mobile with trunk 0' => ['07700900123', '447700900123'],
            'e164 with plus' => ['+447700900123', '447700900123'],
            'already international' => ['447700900123', '447700900123'],
            // The classic "not on WhatsApp" cause: +44 (0)7…
            '+44 (0) style' => ['+44 (0)7700 900123', '447700900123'],
            'spaces and dashes' => ['07700-900 123', '447700900123'],
            '00 international prefix' => ['0044 7700 900123', '447700900123'],
            '00 44 with stray zero' => ['0044 (0)7700900123', '447700900123'],
            'bare uk mobile no code' => ['7700900123', '447700900123'],
            'empty' => ['', null],
            'null' => [null, null],
            'too short' => ['12345', null],
        ];
    }

    public function test_it_recognises_the_region_from_the_dialling_code(): void
    {
        $this->assertSame('GB', Phone::region('07700900123'));
        $this->assertSame('GB', Phone::region('+44 7700 900123'));
        $this->assertSame('IE', Phone::region('00353860889895')); // the Irish customer
        $this->assertSame('IE', Phone::region('+353 86 088 9895'));
        $this->assertSame('ES', Phone::region('+34 600 123 456'));
        $this->assertNull(Phone::region(null));
    }

    public function test_it_builds_an_e164_number_for_dialling(): void
    {
        $this->assertSame('+447700900123', Phone::e164('07700900123'));
        $this->assertSame('+353860889895', Phone::e164('00353860889895'));
        $this->assertNull(Phone::e164(''));
    }

    public function test_it_displays_numbers_recognised_and_grouped(): void
    {
        // UK shows the familiar national form.
        $this->assertSame('07700 900123', Phone::display('+447700900123'));
        // Foreign shows "+<code> …" so it reads as its country, not raw 00353….
        $this->assertSame('+353 860 889 895', Phone::display('00353860889895'));
        // Unparseable input is returned as-is, never blanked.
        $this->assertSame('ask the office', Phone::display('ask the office'));
        $this->assertNull(Phone::display(null));
    }
}
