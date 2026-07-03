<?php

namespace App\Http\Controllers;

use App\Services\Reporting\ReviewService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The business Review page — bookings, revenue, vehicle mix, customers, ad spend
 * and an AI analysis (what's working, what to improve, next steps) for a period.
 */
class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $review) {}

    public function index(Request $request): View
    {
        // Default to a rolling last-30-days window so the page is useful the
        // moment it opens (a calendar-month default looks empty early in a month).
        // The date pickers let the operator choose any range.
        $start = ($request->date('start') ?? now()->subDays(29))->startOfDay();
        $end = ($request->date('end') ?? now())->endOfDay();

        return view('review.index', $this->review->build($start, $end));
    }
}
