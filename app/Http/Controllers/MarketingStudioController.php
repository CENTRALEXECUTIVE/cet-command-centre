<?php

namespace App\Http\Controllers;

use App\Services\Marketing\ContentStudio;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Marketing Studio (admin): generate on-brand social posts, ad copy, a weekly
 * plan and SEO content on demand, powered by the Command Centre's Claude AI.
 */
class MarketingStudioController extends Controller
{
    public function __construct(private readonly ContentStudio $studio) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('marketing.studio', [
            'types' => ContentStudio::TYPES,
            'available' => $this->studio->available(),
            'result' => null,
            'type' => 'social_posts',
            'topic' => null,
        ]);
    }

    public function generate(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(ContentStudio::TYPES))],
            'topic' => ['nullable', 'string', 'max:160'],
        ]);

        return view('marketing.studio', [
            'types' => ContentStudio::TYPES,
            'available' => $this->studio->available(),
            'result' => $this->studio->available()
                ? $this->studio->generate($data['type'], $data['topic'] ?? null)
                : null,
            'type' => $data['type'],
            'topic' => $data['topic'] ?? null,
            'failed' => $this->studio->available(),   // if available but result null → model failed
        ]);
    }
}
