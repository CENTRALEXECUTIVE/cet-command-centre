<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Messaging\NotificationTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Edit the wording of the customer/driver notification templates (the ones the
 * "Send a notification" panel uses). Each is stored per key and falls back to the
 * built-in default; "Reset" clears the override.
 */
class MessageTemplateController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $templates = [];
        foreach (NotificationTemplates::TEMPLATES as $key => $label) {
            $templates[$key] = [
                'label' => $label,
                'text' => NotificationTemplates::rawTemplate($key),
                'customised' => filled(Setting::get(NotificationTemplates::settingKey($key))),
            ];
        }

        return view('admin.message-templates.index', [
            'templates' => $templates,
            'placeholders' => NotificationTemplates::PLACEHOLDERS,
        ]);
    }

    public function update(Request $request, string $template): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless(array_key_exists($template, NotificationTemplates::TEMPLATES), 404);

        if ($request->boolean('reset')) {
            Setting::set(NotificationTemplates::settingKey($template), '', 'string', 'templates');

            return back()->with('status', NotificationTemplates::label($template).' reset to the default wording.');
        }

        $data = $request->validate(['text' => ['required', 'string', 'max:2000']]);
        Setting::set(NotificationTemplates::settingKey($template), trim($data['text']), 'string', 'templates');

        return back()->with('status', NotificationTemplates::label($template).' template saved.');
    }
}
