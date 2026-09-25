<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\VehicleType;
use App\Services\Pricing\FreeRoamPricer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Office control of the distance-based ("free roam") fares — the per-vehicle
 * minimum fare + per-mile tiers, plus the VAT uplift and the Estate uplift — with
 * no code changes. Airport/fixed routes are priced in the Fixed-price editor.
 * Estate is derived (Executive + uplift) and Rolls Royce is quote-only, so
 * neither is edited here.
 */
class FreeRoamRateController extends Controller
{
    /** Vehicle slugs that carry their own free-roam rate row. */
    private const EDITABLE = ['executive', 'minibus-8', 'minibus-8-xl', 'v-class'];

    public function index(Request $request, FreeRoamPricer $pricer): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $rates = $pricer->rates();
        $names = VehicleType::pluck('name', 'slug');

        $rows = [];
        foreach (self::EDITABLE as $slug) {
            $rows[] = [
                'slug' => $slug,
                'name' => $names[$slug] ?? ucwords(str_replace('-', ' ', $slug)),
                'flat' => $rates[$slug][0] ?? 0,
                'tier1' => $rates[$slug][1] ?? 0,
                'tier2' => $rates[$slug][2] ?? 0,
            ];
        }

        return view('admin.free-roam.index', [
            'rows' => $rows,
            'vatUplift' => $pricer->vatUplift(),
            'estateUplift' => $pricer->estateUplift(),
            'samplePrices' => $this->samples($pricer),
        ]);
    }

    public function update(Request $request, FreeRoamPricer $pricer): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'rates' => ['required', 'array'],
            'rates.*.flat' => ['required', 'numeric', 'min:0', 'max:100000'],
            'rates.*.tier1' => ['required', 'numeric', 'min:0', 'max:1000'],
            'rates.*.tier2' => ['required', 'numeric', 'min:0', 'max:1000'],
            'vat_uplift' => ['required', 'numeric', 'min:0', 'max:1000'],
            'estate_uplift' => ['required', 'numeric', 'min:0', 'max:1000'],
        ]);

        $rates = [];
        foreach ($data['rates'] as $slug => $row) {
            if (! in_array($slug, self::EDITABLE, true)) {
                continue;
            }
            $rates[$slug] = [
                round((float) $row['flat'], 2),
                round((float) $row['tier1'], 2),
                round((float) $row['tier2'], 2),
            ];
        }

        Setting::set('freeroam_rates', $rates, 'json', 'pricing');
        Setting::set('freeroam_vat_uplift', (string) round((float) $data['vat_uplift'], 2), 'string', 'pricing');
        Setting::set('freeroam_estate_uplift', (string) round((float) $data['estate_uplift'], 2), 'string', 'pricing');

        return back()->with('status', 'Free-roam rates saved.');
    }

    /**
     * A few worked example fares so the office can sanity-check a change before it
     * goes live to customers.
     *
     * @return array<int, array{miles: int, prices: array<string, ?float>}>
     */
    private function samples(FreeRoamPricer $pricer): array
    {
        $out = [];
        foreach ([5, 25, 60, 120] as $miles) {
            $prices = [];
            foreach (['executive', 'estate', 'v-class'] as $slug) {
                $prices[$slug] = $pricer->price($slug, (float) $miles);
            }
            $out[] = ['miles' => $miles, 'prices' => $prices];
        }

        return $out;
    }
}
