<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\TimeSurcharges;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Time-based pricing: a night surcharge window and dated uplifts (Christmas, NYE, …).
 * The surcharge is added to the fare for the pickup time, consistently on the widget
 * cards, the quote summary and the stored fare.
 */
class TimeSurchargeController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $rules = TimeSurcharges::rules();
        // Render the dated uplifts as editable text: one per line "date | label | type | value".
        $datesText = collect($rules['dates'])
            ->map(fn ($d) => implode(' | ', [$d['date'] ?? '', $d['label'] ?? '', $d['type'] ?? 'percent', $d['value'] ?? 0]))
            ->implode("\n");

        return view('admin.time-surcharges.index', ['night' => $rules['night'], 'datesText' => $datesText]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'night_enabled' => ['nullable', 'boolean'],
            'night_from' => ['required', 'date_format:H:i'],
            'night_to' => ['required', 'date_format:H:i'],
            'night_type' => ['required', 'in:percent,fixed'],
            'night_value' => ['required', 'numeric', 'min:0', 'max:100000'],
            'dates' => ['nullable', 'string', 'max:5000'],
        ]);

        $night = [
            'enabled' => $request->boolean('night_enabled'),
            'from' => $data['night_from'],
            'to' => $data['night_to'],
            'type' => $data['night_type'],
            'value' => (float) $data['night_value'],
        ];

        TimeSurcharges::save($night, $this->parseDates($data['dates'] ?? ''));

        return back()->with('status', 'Time-based pricing saved.');
    }

    /** Parse "YYYY-MM-DD | Label | percent|fixed | value" lines into date-uplift rules. */
    private function parseDates(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $date = $parts[0] ?? '';
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue; // skip malformed rows rather than erroring the whole save
            }
            $type = in_array(($parts[2] ?? 'percent'), ['percent', 'fixed'], true) ? $parts[2] : 'percent';
            $out[] = [
                'date' => $date,
                'label' => $parts[1] ?: 'Holiday rate',
                'type' => $type,
                'value' => (float) ($parts[3] ?? 0),
            ];
        }

        return $out;
    }
}
