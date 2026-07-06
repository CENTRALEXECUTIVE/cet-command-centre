<?php

namespace App\Http\Controllers;

use App\Models\EmailEnquiry;
use App\Services\Inbox\EnquiryInboxService;
use App\Services\Inbox\GraphMailClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Email enquiries inbox: review the AI-prepared quote + draft reply, edit it, and
 * send it (verify-then-send). Sending goes through Graph when available, else the
 * operator uses the mailto/copy buttons from their own Outlook.
 */
class EnquiryController extends Controller
{
    public function __construct(private readonly GraphMailClient $mail) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('enquiries.index', [
            'open' => EmailEnquiry::whereIn('status', ['new', 'quoted'])->latest()->get(),
            'done' => EmailEnquiry::whereIn('status', ['replied', 'booked', 'dismissed'])->latest()->limit(30)->get(),
            'graphReady' => $this->mail->configured(),
        ]);
    }

    public function refresh(Request $request, EnquiryInboxService $enquiries): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $stats = $enquiries->ingest();

        return redirect()->route('enquiries.index')
            ->with('status', "Inbox checked — {$stats['created']} new enquiry(ies).");
    }

    public function show(Request $request, EmailEnquiry $enquiry): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('enquiries.show', [
            'enquiry' => $enquiry,
            'canGraphSend' => $this->mail->configured() && filled($enquiry->graph_message_id),
        ]);
    }

    /** Save edits to the quote / draft reply. */
    public function update(Request $request, EmailEnquiry $enquiry): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'quote_amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'draft_reply' => ['required', 'string', 'max:8000'],
        ]);

        $enquiry->update([
            'quote_amount' => $data['quote_amount'] ?? $enquiry->quote_amount,
            'draft_reply' => $data['draft_reply'],
        ]);

        return back()->with('status', 'Draft saved.');
    }

    /** Send the reply via Graph, or mark it sent after the operator sent it by hand. */
    public function send(Request $request, EmailEnquiry $enquiry): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $manual = $request->boolean('manual');

        if (! $manual && $enquiry->graph_message_id && $this->mail->sendReply($enquiry->graph_message_id, (string) $enquiry->draft_reply)) {
            $enquiry->update(['status' => 'replied', 'replied_at' => now()]);

            return back()->with('status', 'Reply sent from Outlook.');
        }

        // Manual path (mailto/copy) — or Graph send unavailable.
        $enquiry->update(['status' => 'replied', 'replied_at' => now()]);

        return back()->with('status', $manual
            ? 'Marked as replied.'
            : 'Could not send automatically — use “Open in Outlook” / Copy, then mark replied.');
    }

    public function dismiss(Request $request, EmailEnquiry $enquiry): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $enquiry->update(['status' => 'dismissed']);

        return redirect()->route('enquiries.index')->with('status', 'Enquiry dismissed.');
    }
}
