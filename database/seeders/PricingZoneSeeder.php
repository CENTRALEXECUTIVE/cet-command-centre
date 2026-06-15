<?php

namespace Database\Seeders;

use App\Models\PricingZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds CET's pricing zones with the postcode coverage from the zone brief, so
 * a customer's postcode can resolve to a zone for fixed pricing inside the
 * command centre (no ETO KML needed here).
 *
 * Only WHOLE districts are listed for postcode auto-matching. Districts that are
 * split across councils at sector level (S36, S63, S72, S75) are intentionally
 * omitted from auto-match — for those the office picks the zone on the booking
 * form, which is the reliable path. The precise sector polygons only matter for
 * the separate ETO KML (see tools/zones).
 */
class PricingZoneSeeder extends Seeder
{
    public function run(): void
    {
        $zones = [
            'Barnsley' => ['S70', 'S73', 'S74'],
            'Barnsley Deep' => ['S71'],
            'Penistone' => [],            // S36 (Barnsley sectors only) — pick on form
            'Chesterfield' => ['S40', 'S41'],
            'Chesterfield Deep' => ['S42', 'S44', 'S45'],
            'Doncaster' => ['DN1', 'DN2', 'DN3', 'DN4', 'DN5'],
            'Doncaster Deep' => ['DN6', 'DN7', 'DN8', 'DN9', 'DN12'],
            'Rotherham' => ['S60', 'S61', 'S62', 'S65', 'S66'],
            'Sheffield' => ['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9', 'S10', 'S11', 'S12', 'S13', 'S14', 'S17', 'S18', 'S19', 'S35'],
            'Sheffield S20' => ['S20'],
            'Worksop' => ['S80', 'S81'],
            'Matlock' => ['DE4'],
            'Hemsworth' => ['WF9'],
        ];

        foreach ($zones as $name => $prefixes) {
            PricingZone::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'postcode_prefixes' => $prefixes, 'is_active' => true],
            );
        }
    }
}
