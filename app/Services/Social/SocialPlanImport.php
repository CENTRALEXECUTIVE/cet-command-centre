<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use Illuminate\Support\Carbon;

/**
 * Loads the full prepared social plan (docs/growth/social/plan-2026-11-10.json,
 * 31 posts written by Claude for 10–22 Nov, never posted) into the Command
 * Centre, moved forward so the first post is tomorrow. Times and the order are
 * kept. Adds the wedding Reel (edited after the plan was written) and a set of
 * "answer a common complaint" ideas. Replaces any untouched starter-plan posts
 * so nothing is doubled. Nothing is posted anywhere.
 */
class SocialPlanImport
{
    public const FILE = 'docs/growth/social/plan-2026-11-10.json';

    private const PLATFORMS = [
        'linkedin' => 'linkedin', 'google' => 'gbp', 'instagram' => 'instagram',
        'facebook' => 'facebook', 'tiktok' => 'tiktok',
    ];

    private const PILLARS = [
        'b2b' => 'business', 'trust' => 'trust', 'festive' => 'seasonal',
        'useful' => 'airport', 'local' => 'sheffield',
    ];

    /** Reels already edited (music-free; add a trending sound in each app). */
    private const REEL_FILES = [
        'before every job' => 'cet-prep-reel-final.mp4',
        'your ride around sheffield' => 'cet-fleet-reel-final.mp4',
    ];

    public function available(): bool
    {
        return is_file(base_path(self::FILE));
    }

    /** @return array<int, array<string, mixed>> */
    private function posts(): array
    {
        $json = json_decode((string) @file_get_contents(base_path(self::FILE)), true);

        return is_array($json['posts'] ?? null) ? $json['posts'] : [];
    }

    /**
     * Load the plan starting on $from (default tomorrow). Returns the number of
     * posts/ideas created. Already-posted or edited posts are never touched.
     */
    public function load(?Carbon $from = null): int
    {
        $posts = $this->posts();
        if (! $posts) {
            return 0;
        }

        $from = ($from ?? now()->addDay())->copy()->startOfDay();
        $firstDate = Carbon::parse(collect($posts)->min('date'), config('app.timezone'))->startOfDay();
        $shift = (int) $firstDate->diffInDays($from, false);

        // Clear untouched starter-plan posts so nothing is doubled.
        SocialPost::where('notes', 'like', SocialStarterPack::MARKER.'%')
            ->whereIn('status', ['planned', 'idea'])
            ->whereColumn('created_at', 'updated_at')
            ->delete();

        $marker = SocialStarterPack::MARKER.' (prepared plan, moved to start '.$from->format('j M').')';
        $n = 0;
        foreach ($posts as $p) {
            $platform = self::PLATFORMS[strtolower((string) ($p['platform'] ?? ''))] ?? null;
            if (! $platform || empty($p['date'])) {
                continue;
            }
            $at = Carbon::parse($p['date'].' '.($p['time'] ?? '12:00'), config('app.timezone'))->addDays($shift);
            $headline = (string) ($p['headline'] ?? 'Post');
            $isReel = strcasecmp((string) ($p['format'] ?? ''), 'Reel') === 0;

            SocialPost::create([
                'platform' => $platform,
                'status' => 'planned',
                'format' => mb_substr((string) ($p['format'] ?? ''), 0, 40) ?: null,
                'pillar' => self::PILLARS[strtolower((string) ($p['theme'] ?? ''))] ?? null,
                'title' => mb_substr($headline, 0, 160),
                'caption' => $this->caption($p, $platform),
                'hashtags' => mb_substr((string) ($p['hashtags'] ?? ''), 0, 500) ?: null,
                'media_note' => $this->media($p, $headline, $isReel),
                'scheduled_at' => $at,
                'notes' => $marker,
            ]);
            $n++;
        }

        // The wedding Reel was edited after the plan was written: first Saturday a week in.
        $sat = $from->copy()->addDays(7)->next(Carbon::SATURDAY)->setTime(11, 0);
        foreach (['tiktok', 'instagram', 'facebook'] as $platform) {
            SocialPost::create([
                'platform' => $platform, 'status' => 'planned', 'format' => 'Reel', 'pillar' => 'weddings',
                'title' => 'Your wedding day, arriving in style',
                'caption' => "Arriving in style on the big day.\n\nRibbons on, matching cars, and a chauffeur who knows the timings matter. Wedding cars across Sheffield and South Yorkshire."
                    .($platform === 'instagram' ? "\n\nBook via the link in bio or WhatsApp +44 7405 172435." : ($platform === 'facebook' ? "\n\nWhatsApp +44 7405 172435, call 0114 399 8242, or book online." : ' Book at the link in bio.')),
                'hashtags' => '#SheffieldWedding #WeddingCars #YorkshireWedding #ChauffeurService #WeddingDay',
                'media_note' => 'Ready: cet-wedding-reel-final.mp4 (music-free). Add a soft trending sound in the app.',
                'scheduled_at' => $sat,
                'notes' => $marker,
            ]);
            $n++;
        }

        foreach (app(SocialStarterPack::class)->ideas() as [$title, $platform, $pillar]) {
            if (str_starts_with($title, 'Which car for which trip')) {
                continue; // already in the plan as a carousel
            }
            SocialPost::create(['platform' => $platform, 'status' => 'idea', 'pillar' => $pillar, 'title' => $title,
                'notes' => SocialStarterPack::MARKER.' (idea)']);
            $n++;
        }

        foreach ($this->complaintIdeas() as [$title, $platform, $hook, $shot]) {
            SocialPost::create([
                'platform' => $platform, 'status' => 'idea', 'pillar' => 'trust', 'format' => 'Reel',
                'title' => $title, 'caption' => 'Hook: '.$hook, 'media_note' => $shot,
                'notes' => SocialStarterPack::MARKER.' (idea: answer a common complaint)',
            ]);
            $n++;
        }

        return $n;
    }

    private function caption(array $p, string $platform): ?string
    {
        $c = trim((string) ($p['caption'] ?? ''));
        $link = (string) ($p['link'] ?? '');
        // Facebook shows clickable links in the caption; Instagram/TikTok don't (link in bio).
        if ($c !== '' && $link !== '' && $platform === 'facebook' && ! str_contains($c, 'http')) {
            $c .= "\n\n".$link;
        }

        return $c !== '' ? $c : null;
    }

    private function media(array $p, string $headline, bool $isReel): ?string
    {
        $bits = [];
        foreach (self::REEL_FILES as $needle => $file) {
            if ($isReel && str_contains(strtolower($headline), $needle)) {
                $bits[] = 'Ready: '.$file.' (music-free). Add a trending sound in the app.';
            }
        }
        if (! empty($p['on_image_text'])) {
            $bits[] = 'On-image text: '.$p['on_image_text'];
        }
        foreach ((array) ($p['image_prompts'] ?? []) as $i => $prompt) {
            if (is_string($prompt) && $prompt !== '') {
                $bits[] = 'Image '.($i + 1).': '.$prompt;
            } elseif (is_array($prompt)) {
                $bits[] = 'Image '.($i + 1).': '.implode(' ', array_filter(array_map(fn ($v) => is_string($v) ? $v : null, $prompt)));
            }
        }
        if (! empty($p['image_size'])) {
            $bits[] = 'Size: '.$p['image_size'];
        }
        if (! empty($p['first_comment'])) {
            $bits[] = 'First comment: '.$p['first_comment'];
        }
        if (! empty($p['link']) && in_array(strtolower((string) ($p['platform'] ?? '')), ['google', 'linkedin'], true)) {
            $bits[] = 'Link: '.$p['link'];
        }
        if (! empty($p['tip']) && ! str_starts_with($bits[0] ?? '', 'Ready:')) {
            $bits[] = 'Tip: '.$p['tip'];
        }

        return $bits ? mb_substr(implode("\n", $bits), 0, 2000) : null;
    }

    /**
     * Content ideas that answer the complaints people most often make about
     * airport taxis and transfers, each with what CET actually does about it.
     * No invented promises: only features CET already has.
     *
     * @return array<int, array{0:string,1:string,2:string,3:string}>
     */
    private function complaintIdeas(): array
    {
        return [
            ['Complaint: "the driver never turned up"', 'tiktok', 'Booked a car to the airport and it never came?',
                'Phone screen: the driver details message and live tracking link (sample data only). Then the car pulling up early.'],
            ['Complaint: "my flight was late and nobody knew"', 'instagram', 'Your flight lands an hour late. Does your driver know?',
                'Flight tracker on a phone (sample flight), cut to driver in arrivals with a name board (sample name).'],
            ['Complaint: "the price changed on the day"', 'facebook', 'The price you are quoted is the price you pay.',
                'Talking head or text-on-screen over the car: fixed price quoted up front, confirmed in writing.'],
            ['Complaint: "I couldn\'t get hold of anyone"', 'tiktok', 'It\'s 4am and your flight is cancelled. Who picks up?',
                'Phone ringing on the desk, answered. Text: phone and WhatsApp answered 24/7.'],
            ['Complaint: "our bags didn\'t fit"', 'instagram', 'Six people, seven suitcases. Which car?',
                'V-Class boot loaded with dummy bags, side by side with the saloon. No plates.'],
            ['Complaint: "I didn\'t know where to find the driver"', 'tiktok', 'Walking out of arrivals and no idea where your car is?',
                'Arrivals hall, chauffeur holding a sample name board, short walk to the car park.'],
            ['Complaint: "the car was dirty"', 'instagram', 'Would you get into this car?',
                'Re-use the prep Reel clips: wash, valet, water bottles, ready.'],
        ];
    }
}
