<?php

namespace App\Http\Controllers;

use App\Models\ComplianceItem;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\View\View;

/**
 * Fleet & compliance dashboard (admin). Lists every renewable item grouped by
 * urgency so MOT/insurance/PHV/DBS renewals are never missed.
 */
class ComplianceController extends Controller
{
    public function index(): View
    {
        $items = ComplianceItem::orderBy('due_on')->get();

        // Resolve a friendly subject label for each item.
        $vehicles = Vehicle::pluck('registration', 'id');
        $drivers = User::pluck('name', 'id');

        $items->each(function (ComplianceItem $item) use ($vehicles, $drivers) {
            $item->subject_label = $item->subject_type === 'vehicle'
                ? ($vehicles[$item->subject_id] ?? 'Vehicle #'.$item->subject_id)
                : ($drivers[$item->subject_id] ?? 'Driver #'.$item->subject_id);
        });

        return view('compliance.index', [
            'expired' => $items->where('status', 'expired')->values(),
            'dueSoon' => $items->where('status', 'due_soon')->values(),
            'valid' => $items->where('status', 'valid')->values(),
        ]);
    }
}
