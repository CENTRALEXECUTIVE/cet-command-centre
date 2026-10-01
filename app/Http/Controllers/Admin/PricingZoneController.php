<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PricingZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Manage pricing ZONES — the pickup areas that fixed fares are keyed to, each with
 * the postcode outcodes it covers (e.g. Barnsley = S70,S73,S74; Barnsley Deep = S71).
 * The widget resolves a pickup's postcode to the most specific matching zone, so the
 * office can carve out dearer "deep" areas here without a code change. Fares per zone
 * are set on the Fixed prices page.
 */
class PricingZoneController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.zones.index', [
            'zones' => PricingZone::orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'postcode_prefixes' => ['nullable', 'string', 'max:2000'],
        ]);

        $slug = $this->uniqueSlug($data['name']);
        PricingZone::create([
            'name' => trim($data['name']),
            'slug' => $slug,
            'postcode_prefixes' => $this->parsePrefixes($data['postcode_prefixes'] ?? ''),
            'is_active' => true,
        ]);

        return back()->with('status', "Zone “{$data['name']}” added. Set its fares on the Fixed prices page.");
    }

    public function update(Request $request, PricingZone $zone): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'postcode_prefixes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $zone->update([
            'name' => trim($data['name']),
            'postcode_prefixes' => $this->parsePrefixes($data['postcode_prefixes'] ?? ''),
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', "Zone “{$zone->name}” updated.");
    }

    /** "s71, S70 S73; dn6" → ['S71','S70','S73','DN6'] (uppercased, de-duped). */
    private function parsePrefixes(string $raw): array
    {
        return collect(preg_split('/[\s,;]+/', strtoupper(trim($raw))))
            ->map(fn ($p) => trim($p))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'zone';
        $slug = $base;
        $i = 2;
        while (PricingZone::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
