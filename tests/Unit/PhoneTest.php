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
}
