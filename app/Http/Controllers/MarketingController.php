<?php

namespace App\Http\Controllers;

use App\Models\Keyword;
use App\Models\SeoPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Marketing management (admin): Google Ads keywords and SEO pages. The Google
 * Ads spend/conversion dashboard lives in ReportController@ads.
 */
class MarketingController extends Controller
{
    // ----- Keywords ------------------------------------------------------

    public function keywords(): View
    {
        $keywords = Keyword::orderByDesc('clicks')->get();

        return view('marketing.keywords', [
            'keywords' => $keywords,
            'totals' => [
                'clicks' => $keywords->sum('clicks'),
                'impressions' => $keywords->sum('impressions'),
                'conversions' => $keywords->sum('conversions'),
                'cost' => $keywords->sum('cost'),
            ],
        ]);
    }

    public function storeKeyword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:120'],
            'match_type' => ['required', Rule::in(['broad', 'phrase', 'exact'])],
            'campaign' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::in(['enabled', 'paused'])],
            'max_cpc' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);

        Keyword::create($data);

        return back()->with('status', "Keyword \"{$data['keyword']}\" added.");
    }

    public function destroyKeyword(Keyword $keyword): RedirectResponse
    {
        $keyword->delete();

        return back()->with('status', 'Keyword removed.');
    }

    /** Upload a Google Ads keyword report (CSV) to review keyword performance. */
    public function importKeywords(Request $request, \App\Services\Marketing\AdsSyncService $ads): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $count = $ads->importKeywordsCsv($request->file('file')->getRealPath());

        return back()->with('status', $count > 0
            ? "{$count} keyword(s) imported from the Google Ads report."
            : "No keywords found — make sure it's the Google Ads keyword report CSV (with a Keyword column).");
    }

    // ----- SEO -----------------------------------------------------------

    public function seo(): View
    {
        return view('marketing.seo', ['pages' => SeoPage::orderBy('path')->get()]);
    }

    public function storeSeo(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:191'],
            'title' => ['required', 'string', 'max:191'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'target_keyword' => ['nullable', 'string', 'max:120'],
            'current_rank' => ['nullable', 'integer', 'min:1', 'max:200'],
            'monthly_searches' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        SeoPage::updateOrCreate(['path' => $data['path']], $data);

        return back()->with('status', "SEO settings saved for {$data['path']}.");
    }

    public function destroySeo(SeoPage $seoPage): RedirectResponse
    {
        $seoPage->delete();

        return back()->with('status', 'Page removed.');
    }
}
