<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The live extras / surcharge price list (meet & greet, child/booster/infant
 * seats, ribbons, stopover, …). Office-editable from the Extras admin — a saved
 * override merges over the config defaults, so an unset extra keeps its default
 * and pricing never breaks if nothing has been saved.
 */
class Surcharges
{
    /**
     * @return array<string, float>
     */
    public static function rates(): array
    {
        $defaults = (array) config('cet.surcharges', []);
        $saved = (array) Setting::get('surcharges', []);

        foreach ($saved as $key => $val) {
            if (array_key_exists($key, $defaults) && is_numeric($val)) {
                $defaults[$key] = (float) $val;
            }
        }

        return $defaults;
    }
}
