<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use Illuminate\Support\Carbon;

/**
 * The first two weeks of posts for an account that has posted nothing yet,
 * built from the Reels already edited (prep, fleet, wedding) and the bookings
 * plan (docs/growth/social/plan-2026-11-13-bookings.md). House rules: no
 * prices, no customer faces/names/flights, nothing invented, never say the
 * directors drive or "owner-run". Loaded by the office with one tap; nothing
 * is posted anywhere — the office posts from each app and marks it done.
 */
class SocialStarterPack
{
    public const MARKER = 'Starter plan';

    private const PHONE = '0114 399 8242';

    private const WA = '+44 7405 172435';

    /** Reels, in the order they go out. Each goes to TikTok, Instagram and Facebook. */
    private function reels(): array
    {
        return [
            [
                'title' => 'Prepping the car before every job',
                'pillar' => 'behind_scenes',
                'media' => 'Ready: cet-prep-reel-final.mp4 (docs/growth/data). Add a trending sound in each app.',
                'hook' => 'Every car, every job, before you even see it.',
                'body' => 'Washed, valeted, water on board and ready to roll. This is what happens before a Central Executive Transfers pickup.',
                'tags' => '#Sheffield #ChauffeurService #CarDetailing #BehindTheScenes #SouthYorkshire',
            ],
            [
                'title' => 'Executive travel from Sheffield: the fleet',
                'pillar' => 'cars',
                'media' => 'Ready: cet-fleet-reel-final.mp4. Add a trending sound in each app.',
                'hook' => 'Executive travel from Sheffield.',
                'body' => 'Black Mercedes executive saloons and V-Class people carriers for airport runs, business travel and group trips across South Yorkshire.',
                'tags' => '#Sheffield #MercedesVClass #ExecutiveTravel #AirportTransfer #SouthYorkshire',
            ],
            [
                'title' => 'Your wedding day, arriving in style',
                'pillar' => 'weddings',
                'media' => 'Ready: cet-wedding-reel-final.mp4. Add a soft trending sound in each app.',
                'hook' => 'Arriving in style on the big day.',
                'body' => 'Ribbons on, matching cars, and a chauffeur who knows the timings matter. Wedding cars across Sheffield and South Yorkshire.',
                'tags' => '#SheffieldWedding #WeddingCars #YorkshireWedding #ChauffeurService #WeddingDay',
            ],
            [
                'title' => 'What happens when your flight is delayed?',
                'pillar' => 'airport',
                'media' => 'Film: 10-15s, phone showing a flight tracker (no real customer flight), cut to car waiting at arrivals. Text on screen carries the story. Send to Claude to edit.',
                'hook' => 'Your flight is delayed. Now what?',
                'body' => 'We track every flight, so when you land late the driver already knows. No extra phone calls, no rushing. They are in arrivals when you walk through.',
                'tags' => '#AirportTransfer #ManchesterAirport #Sheffield #FlightDelay #TravelTips',
            ],
            [
                'title' => 'Before and after: the interior clean',
                'pillar' => 'behind_scenes',
                'media' => 'Film: 8-12s, same seat before and after valeting, 0.5x lens, steady. No plates. Send to Claude to edit.',
                'hook' => 'Wait for the after.',
                'body' => 'Every car is cleaned between jobs. Satisfying for us, comfortable for you.',
                'tags' => '#CarDetailing #Satisfying #Sheffield #ChauffeurService #CleanCar',
            ],
            [
                'title' => 'Christmas airport runs: book early',
                'pillar' => 'seasonal',
                'media' => 'Film or photo: car at dusk with lights on, no people, no plate close-up. Text: "Flying over Christmas? Book early."',
                'hook' => 'Flying over Christmas? Book your car now.',
                'body' => 'We are working right through Christmas and New Year. Flight tracking, meet and greet in arrivals, and the phone answered 24/7.',
                'tags' => '#ChristmasTravel #AirportTransfer #Sheffield #SouthYorkshire #ManchesterAirport',
            ],
        ];
    }

    private function linkedin(): array
    {
        return [
            [
                'title' => 'Why companies use us: one invoice, your PO on every line',
                'caption' => "When we set up a business account, the first question we ask is how your finance team wants the invoice to look.\n\nCentral Executive Transfers Ltd is VAT registered. Accounts get monthly invoicing, a PO or cost-centre reference on each journey line, and 30-day terms. Or pay by card at booking.\n\nOur chauffeurs are council-licensed and enhanced-DBS checked, and the phone is answered 24/7.\n\nIf you book travel for a team in South Yorkshire, message me to see how an account would work.",
                'tags' => '#SheffieldBusiness #CorporateTravel #SouthYorkshire #ChauffeurService #VATInvoice',
                'media' => 'Canva 1080x1350: black, gold headline "One invoice. Your PO on every line." White Inter body, small logo. No client names.',
            ],
            [
                'title' => 'Visitors landing at Manchester: how it works',
                'caption' => "An illustrative example, not a real client.\n\nA visiting engineer lands at Manchester at 07:10. We track the flight, so if it is late the driver already knows. They meet the visitor in arrivals, help with bags and drive to site. The booker gets the driver's details before pickup and a live tracking link. At month end, one VAT invoice with the PO on that journey's line.\n\nIf your team has visitors flying in, we would be glad to help.",
                'tags' => '#SheffieldBusiness #CorporateTravel #ManchesterAirport #SouthYorkshire #ExecutiveTravel',
                'media' => 'Canva 1080x1080: 4 steps on black with gold numbers: Flight tracked, Driver in arrivals, Details sent, One VAT invoice.',
            ],
        ];
    }

    private function gbp(): array
    {
        return [
            [
                'title' => 'GBP: airport transfers from Sheffield',
                'caption' => 'Airport transfers from Sheffield and South Yorkshire to Manchester, Leeds Bradford, East Midlands, Heathrow and more. Flight tracking so a late landing is covered, meet and greet in arrivals, and the phone answered 24/7. Tap Book to reserve online or call '.self::PHONE.'.',
                'media' => 'Landscape 4:3 fleet photo, daylight, no people, no plate close-up. Button: Book.',
            ],
            [
                'title' => 'GBP: book early for Christmas and New Year',
                'caption' => 'Flying over Christmas or New Year? We are working right through the festive period, so book your airport transfer early. Executive saloons and V-Class for up to 7. Call '.self::PHONE.' or tap Book.',
                'media' => 'Landscape 4:3 photo: car at dusk with lights on, no people. Button: Book.',
            ],
        ];
    }

    private function caption(array $reel, string $platform): string
    {
        return match ($platform) {
            'tiktok' => $reel['hook'].' '.$reel['body'].' Book at the link in bio.',
            'instagram' => $reel['hook']."\n\n".$reel['body']."\n\nBook online: link in bio.",
            default => $reel['hook']."\n\n".$reel['body']."\n\nBook online at centralexecutivetransfers.co.uk",
        };
    }

    public function alreadyLoaded(): bool
    {
        return SocialPost::where('notes', 'like', self::MARKER.'%')->exists();
    }

    /**
     * Create two weeks of planned posts starting from $from (default
     * tomorrow), plus the idea bank. Returns how many posts were created.
     */
    public function load(?Carbon $from = null): int
    {
        $from = ($from ?? now()->addDay())->copy()->startOfDay();
        $until = $from->copy()->addDays(14);
        $reels = $this->reels();
        $li = $this->linkedin();
        $gbp = $this->gbp();
        $n = 0;

        // Weekly rhythm (UK time): Mon GBP, Tue LinkedIn + Reel, Thu Reel, Sat Reel.
        $slots = [
            ['dow' => Carbon::MONDAY, 'time' => '12:00', 'kind' => 'gbp'],
            ['dow' => Carbon::TUESDAY, 'time' => '08:00', 'kind' => 'linkedin'],
            ['dow' => Carbon::TUESDAY, 'time' => '19:00', 'kind' => 'reel'],
            ['dow' => Carbon::THURSDAY, 'time' => '19:00', 'kind' => 'reel'],
            ['dow' => Carbon::SATURDAY, 'time' => '11:00', 'kind' => 'reel'],
        ];

        for ($d = $from->copy(); $d->lt($until); $d->addDay()) {
            foreach ($slots as $slot) {
                if ($d->dayOfWeek !== $slot['dow']) {
                    continue;
                }
                $at = Carbon::parse($d->toDateString().' '.$slot['time'], config('app.timezone'));
                if ($slot['kind'] === 'reel' && $reel = array_shift($reels)) {
                    foreach (['tiktok', 'instagram', 'facebook'] as $platform) {
                        $this->make([
                            'platform' => $platform,
                            'format' => $platform === 'tiktok' ? 'Video' : 'Reel',
                            'pillar' => $reel['pillar'],
                            'title' => $reel['title'],
                            'caption' => $this->caption($reel, $platform),
                            'hashtags' => $reel['tags'],
                            'media_note' => $reel['media'],
                            'scheduled_at' => $at,
                        ]);
                        $n++;
                    }
                } elseif ($slot['kind'] === 'linkedin' && $post = array_shift($li)) {
                    $this->make([
                        'platform' => 'linkedin', 'format' => 'Image post', 'pillar' => 'business',
                        'title' => $post['title'], 'caption' => $post['caption'], 'hashtags' => $post['tags'],
                        'media_note' => $post['media'], 'scheduled_at' => $at,
                    ]);
                    $n++;
                } elseif ($slot['kind'] === 'gbp' && $post = array_shift($gbp)) {
                    $this->make([
                        'platform' => 'gbp', 'format' => 'Update', 'pillar' => 'airport',
                        'title' => $post['title'], 'caption' => $post['caption'],
                        'media_note' => $post['media'], 'scheduled_at' => $at,
                    ]);
                    $n++;
                }
            }
        }

        foreach ($this->ideas() as [$title, $platform, $pillar]) {
            $this->make(['platform' => $platform, 'status' => 'idea', 'pillar' => $pillar, 'title' => $title]);
            $n++;
        }

        return $n;
    }

    /** The rest of the 20 ready ideas from the bookings plan. */
    public function ideas(): array
    {
        return [
            ['Meet and greet board at arrivals (sample name board, 5s clip)', 'instagram', 'airport'],
            ['New Year\'s Eve: get home safely with friends (V-Class)', 'facebook', 'seasonal'],
            ['A day in the life of a CET chauffeur (POV, no customer faces)', 'tiktok', 'behind_scenes'],
            ['Sheffield Cathedral and the E-Class (photo)', 'instagram', 'sheffield'],
            ['Three V-Classes for a wedding party (photo)', 'instagram', 'weddings'],
            ['Water on board for every guest (short clip)', 'tiktok', 'behind_scenes'],
            ['Rain or shine, we\'re there (Fox Valley photos)', 'facebook', 'sheffield'],
            ['Live tracking link: screen-record with sample data', 'tiktok', 'trust'],
            ['We cover every airport: MAN, LBA, EMA, LHR, LGW (map graphic)', 'instagram', 'airport'],
            ['Customer review card (with permission)', 'facebook', 'trust'],
            ['How to book in 60 seconds (screen-record of the booking form)', 'tiktok', 'trust'],
            ['Cars lined up at night (The Grand photos)', 'instagram', 'cars'],
            ['Ask us about a business account', 'linkedin', 'business'],
            ['Which car for which trip? (carousel: saloon, estate, V-Class, minibus)', 'instagram', 'cars'],
        ];
    }

    private function make(array $attrs): SocialPost
    {
        return SocialPost::create($attrs + [
            'status' => 'planned',
            'notes' => self::MARKER.' (Claude, '.now()->format('j M Y').')',
        ]);
    }
}
