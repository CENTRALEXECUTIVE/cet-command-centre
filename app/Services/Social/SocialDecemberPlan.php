<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use Illuminate\Support\Carbon;

/**
 * Weeks 3–4 (Wed 2 – Tue 15 Dec 2026): Christmas and New Year bookings. Every
 * post comes with its finished photos/video (resources/social-media/dNN) and
 * is created "Ready to post". House rules: no prices, no customer faces/names,
 * never "directors drive"/"owner-run", nothing invented. Idempotent.
 */
class SocialDecemberPlan
{
    public const MARKER = 'December plan (Claude, 19 Nov 2026)';

    private const WA = '+44 7405 172435';

    private const PHONE = '0114 399 8242';

    /** [date time, platforms, format, pillar, title, hook, body, hashtags, media files] */
    private function posts(): array
    {
        $xmas = '#ChristmasTravel #AirportTransfer #Sheffield #SouthYorkshire #ManchesterAirport';
        $party = '#ChristmasParty #Sheffield #NightOut #GroupTravel #SouthYorkshire';
        $biz = '#SheffieldBusiness #CorporateTravel #SouthYorkshire #ChauffeurService #VATInvoice';

        return [
            ['2026-12-02 19:00', ['tiktok', 'instagram', 'facebook'], 'Reel', 'seasonal', 'Christmas airport runs: book early',
                'Flying over Christmas?', 'We track every flight, so a delay is covered, and with meet and greet your driver is waiting in arrivals. Executive saloons, and V-Class for the family and the bags. We work right through Christmas and New Year.', $xmas, ['d01/reel.mp4']],
            ['2026-12-03 08:00', ['linkedin'], 'Image post', 'business', 'Year-end visitors: book their cars now',
                'Visitors flying in before Christmas?', "December flights fill up, and so do cars. If you have clients or engineers visiting your site before the break, book their transfers now.\n\nWe track every flight and meet visitors in arrivals. The booker gets the driver's details before pickup and a live tracking link. Your finance team gets a VAT invoice with your PO or cost-centre on the journey line.\n\nMessage me to book or to set up an account.", $biz, ['d02/1.jpg']],
            ['2026-12-03 19:30', ['instagram', 'facebook'], 'Carousel', 'seasonal', 'Party night? Sort the ride first (carousel)',
                'Party night? Sort the ride first.', "Four things that make the night easier: book before the night, one car for the group (a V-Class takes up to 7), book the ride home when you book the ride there, and agree the pickup point. Save this for your work do.", $party, ['d03/1.jpg', 'd03/2.jpg', 'd03/3.jpg', 'd03/4.jpg', 'd03/5.jpg', 'd03/6.jpg']],
            ['2026-12-04 18:00', ['whatsapp', 'instagram', 'facebook'], 'Story', 'seasonal', 'Story: Party nights, sorted',
                null, null, null, ['d04/1.jpg']],
            ['2026-12-05 11:00', ['tiktok', 'instagram', 'facebook'], 'Reel', 'sheffield', 'Night out in Sheffield? Home together',
                'Night out in Sheffield?', 'Picked up at the door, and home together at the end of the night. No taxi queue, no splitting up the group. Saloons, V-Class for 7 and minibuses for bigger groups.', $party, ['d05/reel.mp4']],
            ['2026-12-06 18:00', ['facebook', 'instagram'], 'Photo', 'seasonal', 'Christmas markets day out',
                'Day out at the Christmas markets?', 'Leave the car at home. York, Manchester, Leeds and more, with a V-Class for up to 7 so the whole group travels together and nobody has to drive.', '#ChristmasMarkets #DayOut #Sheffield #GroupTravel #MercedesVClass', ['d06/1.jpg']],
            ['2026-12-07 12:00', ['gbp'], 'Update', 'airport', 'GBP: Christmas and New Year airport transfers',
                null, 'Christmas and New Year airport transfers from Sheffield and South Yorkshire. Flight tracking so a late landing is covered, meet and greet in arrivals, and the phone answered 24/7. We work right through the holidays. Tap Book or call '.self::PHONE.'.', null, ['d07/1.jpg']],
            ['2026-12-08 08:00', ['linkedin'], 'Image post', 'business', 'Start January with an account in place',
                'Start January with an account in place.', "If your team travels in the new year, set the account up before the break so the first booking in January is simple.\n\nCentral Executive Transfers Ltd is VAT registered. Accounts get monthly invoicing, a PO or cost-centre reference on each journey line and 30-day terms. Our chauffeurs are council-licensed and enhanced-DBS checked.\n\nSend me a message and I'll set it up.", $biz, ['d08/1.jpg']],
            ['2026-12-09 19:00', ['tiktok', 'instagram', 'facebook'], 'Reel', 'weddings', 'Winter weddings',
                'Winter weddings.', 'Matching cars for the party, ribbons on request, and a chauffeur who knows the timings matter. Booking for 2027? Ask us about your date.', '#SheffieldWedding #WinterWedding #WeddingCars #YorkshireWedding #ChauffeurService', ['d09/reel.mp4']],
            ['2026-12-10 19:30', ['instagram', 'facebook'], 'Carousel', 'seasonal', "New Year's Eve: plan the ride home (carousel)",
                "New Year's Eve: plan the ride home.", "It's one of the busiest nights of the year. Book early, choose the car for the group, fix the pickup time and keep your phone on for the driver's details. Save this and send it to whoever's organising.", '#NewYearsEve #Sheffield #NightOut #GroupTravel #SouthYorkshire', ['d10/1.jpg', 'd10/2.jpg', 'd10/3.jpg', 'd10/4.jpg', 'd10/5.jpg', 'd10/6.jpg']],
            ['2026-12-11 18:00', ['whatsapp', 'instagram', 'facebook'], 'Story', 'seasonal', "Story: New Year's Eve, book your ride home",
                null, null, null, ['d11/1.jpg']],
            ['2026-12-12 11:00', ['tiktok', 'instagram', 'facebook'], 'Reel', 'cars', 'Which car for your group this Christmas',
                'Which car for your group?', 'Saloon for 4 people and 2 bags, V-Class for 7 people and 7 bags, minibuses for bigger groups. Tell us your numbers and we will pick the car.', '#Sheffield #GroupTravel #MercedesVClass #AirportTransfer #ChristmasTravel', ['d12/reel.mp4']],
            ['2026-12-13 18:00', ['instagram', 'facebook'], 'Photo', 'seasonal', 'Working Christmas Day, Boxing Day and New Year',
                "We're working through the holidays.", "Christmas Day, Boxing Day and New Year's Day included. Airport runs, family visits and party nights, with licensed chauffeurs and the phone answered 24/7. Book early: the holidays fill up.", $xmas, ['d13/1.jpg']],
            ['2026-12-14 12:00', ['gbp'], 'Update', 'airport', 'GBP: New Year airport transfers, book early',
                null, 'Flying out for New Year or back after the holidays? Book your airport transfer from Sheffield and South Yorkshire now. Executive saloons and V-Class, flight tracking and meet and greet. Call '.self::PHONE.' or tap Book.', null, ['d14/1.jpg']],
            ['2026-12-15 08:00', ['linkedin'], 'Image post', 'business', 'Thank you to our business clients',
                'Thank you.', "To every business that travelled with us this year: thank you. Visitors met at arrivals, teams to site, client evenings home safely.\n\nIf you'd like an account in place for January, with monthly VAT invoices and your PO on every journey, send me a message before the break.", $biz, ['d15/1.jpg']],
        ];
    }

    private function caption(?string $hook, ?string $body, string $platform): ?string
    {
        if ($body === null) {
            return null;
        }
        $lead = $hook ? $hook."\n\n" : '';

        return match ($platform) {
            'tiktok' => trim(($hook ? $hook.' ' : '').$body.' Book at the link in bio.'),
            'instagram' => $lead.$body."\n\nBook via the link in bio or WhatsApp ".self::WA.'.',
            'facebook' => $lead.$body."\n\nWhatsApp ".self::WA.', call '.self::PHONE.', or book online.',
            'linkedin' => $lead.$body,
            default => $body,
        };
    }

    public function loaded(): bool
    {
        return SocialPost::where('notes', self::MARKER)->exists();
    }

    /** Create the posts (skips if already loaded). Returns how many were created. */
    public function load(): int
    {
        if ($this->loaded()) {
            return 0;
        }
        $n = 0;
        foreach ($this->posts() as [$when, $platforms, $format, $pillar, $title, $hook, $body, $tags, $files]) {
            $media = array_map(fn ($f) => 'lib:'.$f, $files);
            foreach ($platforms as $platform) {
                $isStory = $format === 'Story';
                SocialPost::create([
                    'platform' => $platform,
                    'status' => 'ready',
                    'format' => $format,
                    'pillar' => $pillar,
                    'title' => $title,
                    'caption' => $this->caption($hook, $body, $platform),
                    'hashtags' => in_array($platform, ['gbp', 'whatsapp'], true) ? null : $tags,
                    'media' => $media,
                    'media_note' => $isStory
                        ? ($platform === 'whatsapp'
                            ? 'Post as your WhatsApp Business Status (Updates tab → Add status).'
                            : 'Post as a Story. Add a link sticker to centralexecutivetransfers.co.uk.')
                        : ($format === 'Reel' ? 'Video is music-free: add a trending sound in the app.' : null),
                    'scheduled_at' => Carbon::parse($when, config('app.timezone')),
                    'notes' => self::MARKER,
                ]);
                $n++;
            }
        }

        return $n;
    }
}
