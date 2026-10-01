<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Manage discount codes (mirrors ETO's Voucher Discounts): a % or £ off the fare,
 * optionally capped to a number of uses and a date window. The booking widget already
 * accepts a code at checkout; this is where the office creates and manages them.
 */
class VoucherController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.vouchers.index', [
            'vouchers' => Voucher::orderByDesc('is_active')->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $this->validated($request);
        $data['code'] = strtoupper(trim($data['code']));

        if (Voucher::whereRaw('UPPER(code) = ?', [$data['code']])->exists()) {
            return back()->withInput()->withErrors(['code' => "A voucher with the code {$data['code']} already exists."]);
        }

        Voucher::create($data + ['used_count' => 0, 'is_active' => $request->boolean('is_active', true)]);

        return back()->with('status', "Voucher {$data['code']} created.");
    }

    public function update(Request $request, Voucher $voucher): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $this->validated($request);
        $data['code'] = strtoupper(trim($data['code']));

        if (Voucher::whereRaw('UPPER(code) = ?', [$data['code']])->where('id', '!=', $voucher->id)->exists()) {
            return back()->withInput()->withErrors(['code' => "Another voucher already uses the code {$data['code']}."]);
        }

        $voucher->update($data + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', "Voucher {$voucher->code} updated.");
    }

    public function destroy(Request $request, Voucher $voucher): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $code = $voucher->code;
        $voucher->delete();

        return back()->with('status', "Voucher {$code} deleted.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'type' => ['required', Rule::in(['percent', 'fixed'])],
            'value' => ['required', 'numeric', 'min:0', 'max:100000'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
