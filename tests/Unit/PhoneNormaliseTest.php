<?php

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\TestCase;

/**
 * Directors (11 Nov 2026): the contact number must be recognised to its country
 * and saved in the right format, like ETO did (+447…, +1…).
 */
class PhoneNormaliseTest extends TestCase
{
    public function test_uk_numbers_in_any_typed_form_become_plus_44(): void
    {
        foreach (['07700 900123', '7700900123', '447700900123', '+44 (0)7700 900123', '0044 7700 900123', '+44 7700 900123'] as $typed) {
            $this->assertSame('+447700900123', Phone::normalise($typed, 'GB'), $typed);
        }
        $this->assertSame('+441143998242', Phone::normalise('0114 399 8242', 'GB'));
    }

    public function test_a_us_number_picked_as_united_states_is_not_mistaken_for_uk(): void
    {
        $this->assertSame('+13478820640', Phone::normalise('(347) 882-0640', 'US'));
        $this->assertSame('+13478820640', Phone::normalise('1 347 882 0640', 'US'));
        $this->assertSame('+13478820640', Phone::normalise('+1 347 882 0640', 'GB')); // "+" wins over the picker
    }

    public function test_other_countries_drop_the_trunk_zero_except_italy(): void
    {
        $this->assertSame('+353860889895', Phone::normalise('086 088 9895', 'IE'));
        $this->assertSame('+353860889895', Phone::normalise('353 86 088 9895', 'IE'));
        $this->assertSame('+33612345678', Phone::normalise('06 12 34 56 78', 'FR'));
        $this->assertSame('+390612345678', Phone::normalise('06 1234 5678', 'IT'));
    }

    public function test_blank_and_junk_are_left_alone(): void
    {
        $this->assertNull(Phone::normalise('  ', 'GB'));
        $this->assertSame('ext 12', Phone::normalise('ext 12', 'GB'));
    }

    public function test_picker_region_and_variants(): void
    {
        $this->assertSame('US', Phone::pickerRegion('+13478820640'));
        $this->assertSame('IE', Phone::pickerRegion('+353860889895'));
        $this->assertSame('GB', Phone::pickerRegion('07700 900123'));
        $this->assertSame('GB', Phone::pickerRegion(null));
        $this->assertContains('07700 900123', Phone::variants('+447700900123'));
        $this->assertContains('07700900123', Phone::variants('+447700900123'));
        $this->assertSame('🇬🇧', Phone::flag('GB'));
    }
}
