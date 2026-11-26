<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookingAttempt;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Read-only: every website booking attempt and "On request" price (26 Nov 2026). */
class BookingLogController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);
        $filter = $request->query('show', 'problems');
        $q = BookingAttempt::query()->orderByDesc('id');
        if ($filter === 'problems') {
            $q->whereIn('outcome', ['refused', 'error', 'quote_request', 'price_on_request', 'submitted']);
        }
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('customer_name', 'like', "%$s%")->orWhere('contact', 'like', "%$s%")
                ->orWhere('reference', 'like', "%$s%")->orWhere('pickup', 'like', "%$s%")->orWhere('destination', 'like', "%$s%"));
        }
        try {
            $attempts = $q->paginate(50)->withQueryString();
        } catch (\Throwable) {
            $attempts = null; // not migrated yet
        }

        return view('admin.booking-log.index', compact('attempts', 'filter'));
    }
}
