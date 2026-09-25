<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Surcharges;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Office control of the extras / surcharge price list (meet & greet, child/
 * booster/infant seats, ribbons, stopover, …) — mirrors ETO's Item Surcharge
 * settings, editable with no code changes. Saved to the `surcharges` Setting,
 * read live by App\Support\Surcharges::rates().
 */
class ExtraPriceController extends Controller
{
    /** Friendly labels for each surcharge key, in display order. */
    private const LABELS = [
        'meet_greet' => 'Meet & greet',
        'child_seat' => 'Child seat',
        'booster_seat' => 'Booster seat',
        'infant_seat' => 'Infant seat',
        'wheelchair' => 'Wheelchair',
        'stopover' => 'Extra stop / stopover',
        'waiting_after_landing' => 'Waiting after landing (per hr)',
        'ribbons_car' => 'Wedding ribbons — car',
        'ribbons_minibus' => 'Wedding ribbons — minibus / V-Class',
    ];

    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $rates = Surcharges::rates();
        $rows = [];
        foreach (self::LABELS as $key => $label) {
            $rows[] = ['key' => $key, 'label' => $label, 'amount' => (float) ($rates[$key] ?? 0)];
        }

        return view('admin.extras.index', ['rows' => $rows]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'extras' => ['required', 'array'],
            'extras.*' => ['required', 'numeric', 'min:0', 'max:10000'],
        ]);

        // Only persist keys we actually manage, so no stray values sneak in.
        $save = [];
        foreach (array_keys(self::LABELS) as $key) {
            if (isset($data['extras'][$key])) {
                $save[$key] = round((float) $data['extras'][$key], 2);
            }
        }

        Setting::set('surcharges', $save, 'json', 'pricing');

        return back()->with('status', 'Extra prices saved.');
    }
}
