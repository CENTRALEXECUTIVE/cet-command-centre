<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\DataErasureRequest;
use App\Services\Gdpr\DataErasureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * UK GDPR right-to-erasure workflow (admin). A request is raised against a
 * customer (identity verified out of band), then processed to wipe their data.
 */
class ErasureController extends Controller
{
    public function __construct(private readonly DataErasureService $erasure) {}

    public function index(): View
    {
        return view('gdpr.erasure', [
            'requests' => DataErasureRequest::with(['customer', 'processedBy'])
                ->orderByDesc('requested_at')->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:160'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $customer = Customer::where('email', $data['identifier'])
            ->orWhere('phone', $data['identifier'])
            ->first();

        if (! $customer) {
            return back()->withErrors(['identifier' => 'No customer found with that email or phone.'])->withInput();
        }

        DataErasureRequest::create([
            'customer_id' => $customer->id,
            'requested_by_email' => $request->user()->email,
            'status' => 'pending',
            'reason' => $data['reason'] ?? null,
            'requested_at' => now(),
        ]);

        return back()->with('status', "Erasure request raised for {$customer->name}. Verify identity, then process.");
    }

    public function process(Request $request, DataErasureRequest $erasureRequest): RedirectResponse
    {
        if ($erasureRequest->status === 'completed') {
            return back()->with('status', 'This request has already been completed.');
        }

        if (! $erasureRequest->customer) {
            return back()->withErrors(['process' => 'Customer record no longer exists.']);
        }

        $summary = $this->erasure->eraseCustomer($erasureRequest->customer, $request->user(), $erasureRequest);

        return back()->with('status', 'Erasure completed: '.collect($summary)
            ->map(fn ($n, $k) => "{$n} {$k}")->implode(', ').'.');
    }
}
