<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Messaging\CustomerBookingMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ETO-style "Web Widgets" settings: copy-paste iframe snippets for the marketing
 * site (mini price, full booking, customer account), plus the master switch for
 * automatic customer confirmation emails (web-widget bookings only).
 */
class WebWidgetController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('admin.web-widgets', [
            'emailsOn' => CustomerBookingMailer::enabled(),
            'urls' => [
                'mini' => route('widget.mini'),
                'book' => route('widget.book'),
                'account' => route('widget.account'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        Setting::set(CustomerBookingMailer::SETTING, $request->boolean('customer_emails'), 'boolean', 'widgets');

        return back()->with('status', $request->boolean('customer_emails')
            ? 'Automatic customer confirmation emails are ON — for website-widget bookings only.'
            : 'Automatic customer emails are OFF. Nothing is sent to customers automatically.');
    }
}
