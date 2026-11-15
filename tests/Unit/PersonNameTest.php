<?php

namespace Tests\Unit;

use App\Support\PersonName;
use PHPUnit\Framework\TestCase;

/** 15 Nov 2026: a pay statement read "Mr MAN" — a title is never a first name. */
class PersonNameTest extends TestCase
{
    public function test_short_names(): void
    {
        $this->assertSame('John', PersonName::short('John Smith'));
        $this->assertSame('Mr Smith', PersonName::short('Mr Smith'));
        $this->assertSame('Mrs Patel', PersonName::short('Mrs. J Patel'));
        $this->assertSame('Dr KHAN', PersonName::short('DR KHAN'));
        $this->assertSame('there', PersonName::short('Mr', 'there'));
        $this->assertSame('there', PersonName::short('  ', 'there'));
        $this->assertSame('KEITH', PersonName::short('KEITH BOLDOCK'));
    }
}
