<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Back-office report: every Google review request the system has raised, and who
 * it went to. Review requests are stored as `messages` of type `review_request`
 * (queued when prepared, "sent" once the office taps the wa.me / mailto link).
 * This is the single place to fetch, check and review who's been asked.
 *
 * Deliberately NOT in the main nav — reached by URL or a discreet link on the
 * Review page — so it stays out of the day-to-day view.
 */
class ReviewsSentController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        // Optional filter: all | sent | pending. Defaults to sent (the "reviews sent").
        $filter = in_array($request->query('show'), ['all', 'sent', 'pending'], true)
            ? $request->query('show') : 'sent';

        $query = Message::where('type', 'review_request')
            ->with(['booking.customer', 'customer']);

        if ($filter === 'sent') {
            $query->where('status', 'sent');
        } elseif ($filter === 'pending') {
            $query->whereIn('status', ['queued', 'failed']);
        }

        // Newest first — sent time if we have it, else when it was scheduled/created.
        $messages = $query
            ->orderByRaw('COALESCE(sent_at, scheduled_for, created_at) DESC')
            ->paginate(50)
            ->withQueryString();

        // Headline counts across ALL review requests (not just this page/filter).
        $counts = [
            'sent' => Message::where('type', 'review_request')->where('status', 'sent')->count(),
            'pending' => Message::where('type', 'review_request')->whereIn('status', ['queued', 'failed'])->count(),
        ];
        $counts['total'] = $counts['sent'] + $counts['pending'];

        return view('admin.reviews-sent.index', [
            'messages' => $messages,
            'filter' => $filter,
            'counts' => $counts,
        ]);
    }
}
