<?php

use App\Models\SocialPost;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Directors, 23 Nov 2026: "use all the photos". Batch 2 covers Wed 16 Dec 2026 to
 * Fri 15 Jan 2027 with the photos that had not been used yet (Rolls-Royce Ghost,
 * The Grand at night, the rainy wedding cars, Sheffield landmarks, fleet line-ups,
 * airport drop-offs). Images live in resources/social-media/b2/<nn>/.
 *
 * House rules: the only call to action is booking online, no phone numbers, no PO
 * wording, no prices, no customer names or faces, plates blurred, no partnership
 * with any hotel or venue implied (just "collected from").
 */
return new class extends Migration
{
    private const NOTE = 'Batch 2 (unused photos), 23 Nov 2026';

    private const SITE = 'centralexecutivetransfers.co.uk';

    /**
     * [when, platforms => format, pillar, title, hook, body, question, hashtags, files].
     * Stories have no caption (hook/body null).
     */
    private function posts(): array
    {
        $xmas = '#Sheffield #ChristmasTravel #AirportTransfer #ChauffeurService #SouthYorkshire';
        $wed = '#SheffieldWedding #WeddingCars #YorkshireWedding #Wedding2027 #MercedesVClass';
        $biz = '#BusinessTravel #CorporateTravel #SheffieldBusiness #SouthYorkshire #ExecutiveTravel';
        $car = ['instagram' => 'Carousel', 'facebook' => 'Carousel', 'tiktok' => 'Photo carousel'];
        $photo3 = ['instagram' => 'Photo', 'facebook' => 'Photo', 'tiktok' => 'Photo carousel'];
        $photo2 = ['instagram' => 'Photo', 'facebook' => 'Photo'];
        $story = ['whatsapp' => 'Story', 'instagram' => 'Story', 'facebook' => 'Story'];
        $reel = ['tiktok' => 'Reel', 'instagram' => 'Reel'];
        $range = fn (string $d, int $n) => array_map(fn ($i) => "b2/$d/$i.jpg", range(1, $n));

        return [
            ['2026-12-16 19:00', $car, 'sheffield', 'Sheffield at Christmas (carousel)',
                'Sheffield at Christmas, from the back seat.',
                'City Hall, the Winter Garden, the Cathedral quarter and the streets in between. We collect across Sheffield, Rotherham, Barnsley, Doncaster and Chesterfield.',
                'Where should we pick you up this Christmas?',
                '#Sheffield #SheffieldChristmas #ChauffeurService #MercedesEClass #SouthYorkshire', $range('01', 8)],
            ['2026-12-17 08:00', ['linkedin' => 'Image post'], 'business', 'January travel, sorted before the break',
                'If your team travels in January, set the account up before the break.',
                "VAT-registered business accounts with monthly invoicing on 30-day terms. Flights tracked, visitors met in arrivals, and the booker gets the driver's details and a live tracking link before pickup. Chauffeurs are council-licensed and enhanced-DBS checked.",
                'Who books travel in your team, and how far ahead?', $biz, ['b2/02/1.jpg']],
            ['2026-12-18 12:00', ['gbp' => 'Update'], 'seasonal', 'GBP: Christmas and New Year transfers', null,
                'Christmas and New Year transfers across Sheffield, Rotherham, Barnsley, Doncaster and Chesterfield. Mercedes E-Class saloons, and V-Class for up to 7 people and 7 bags. Flights tracked, meet and greet in arrivals, driver details and a live tracking link before pickup. We work through Christmas and New Year.',
                null, null, ['b2/03/1.jpg']],
            ['2026-12-18 19:00', $photo2, 'weddings', 'Rolls-Royce Ghost at The Grand',
                'Big night? Arrive in the Ghost.',
                'Collected from The Grand. Our Rolls-Royce Ghost is available for weddings, anniversaries and big occasions, with the price on request for your date.',
                'What occasion would you book the Ghost for?',
                '#RollsRoyceGhost #WeddingCar #Sheffield #ChauffeurService #SpecialOccasion', ['b2/04/1.jpg']],
            ['2026-12-19 18:00', $story, 'seasonal', 'Story: Flying home for Christmas', null, null, null, null, $range('05', 4)],
            ['2026-12-19 19:00', $reel, 'airport', 'Flying home for Christmas (Reel)',
                'Flying home for Christmas?',
                "We track the flight, meet you in arrivals with meet and greet, and send your driver's details before pickup. A V-Class takes 7 people and 7 bags.",
                'Which airport are you flying into?',
                '#ChristmasTravel #AirportTransfer #ManchesterAirport #Sheffield #MercedesVClass', ['b2/05/reel.mp4']],
            ['2026-12-20 19:00', $photo3, 'airport', 'Landing late over Christmas?',
                'Landing late over Christmas?',
                "We track every airport pickup, so if your flight is delayed your driver already knows. With meet and greet they're waiting in arrivals.",
                "What's the latest you've ever landed?",
                '#AirportTransfer #ChristmasTravel #ManchesterAirport #Sheffield #ChauffeurService', ['b2/06/1.jpg']],
            ['2026-12-22 19:00', $photo2, 'seasonal', 'Working Christmas Day and Boxing Day',
                "Christmas Day, Boxing Day, New Year's Day. We're working.",
                'Airport runs, family visits and nights out right through the holidays. The busy days fill first, so book online as soon as your plans are set.',
                'Travelling over Christmas or staying put?', $xmas, ['b2/07/1.jpg']],
            ['2026-12-24 12:00', $photo2, 'seasonal', 'Merry Christmas from Central',
                'Merry Christmas from all of us at Central.',
                "Thank you to everyone who travelled with us this year. If you're on the road with us over the next few days, we'll see you soon.",
                'Where are you spending Christmas Day?',
                '#MerryChristmas #Sheffield #ChauffeurService #SouthYorkshire #MercedesEClass', ['b2/08/1.jpg']],
            ['2026-12-27 19:00', $car, 'weddings', 'The Rolls-Royce Ghost (carousel)',
                'Getting married in 2027? Meet the Ghost.',
                'Our Rolls-Royce Ghost is available for weddings and big occasions, with a chauffeur who knows the timings matter. Price on request for your date.',
                'Classic Rolls-Royce or modern Mercedes for the big day?',
                '#RollsRoyceGhost #SheffieldWedding #WeddingCar #YorkshireWedding #Wedding2027', $range('09', 5)],
            ['2026-12-28 18:00', $story, 'seasonal', "Story: New Year's Eve, book the ride home", null, null, null, null, $range('10', 3)],
            ['2026-12-29 19:00', $reel, 'seasonal', "New Year's Eve, home together (Reel)",
                "New Year's Eve?",
                'Book the ride home before the night, and the whole group gets home together. Picked up at the door, with your driver\'s details before pickup.',
                "Who's organising your night?",
                '#NewYearsEve #Sheffield #NightOut #ChauffeurService #MercedesEClass', ['b2/10/reel.mp4']],
            ['2026-12-30 12:00', ['gbp' => 'Update'], 'weddings', 'GBP: Rolls-Royce Ghost for weddings and occasions', null,
                'Rolls-Royce Ghost available for weddings, anniversaries and special occasions across Sheffield and South Yorkshire, with Mercedes E-Class and V-Class for the rest of the party. Licensed operator (Sheffield City Council OP037), chauffeurs council-licensed and enhanced-DBS checked. Price on request.',
                null, null, ['b2/12/1.jpg']],
            ['2026-12-30 19:00', $photo2, 'seasonal', "New Year's Eve: book the ride home now",
                "New Year's Eve is one of the busiest nights of the year for cars.",
                "Book the ride home when you book the night out. A V-Class takes 7, so the group travels together, and your driver's details arrive before pickup.",
                'Out in town or a night in this year?',
                '#NewYearsEve #Sheffield #NightOut #MercedesVClass #ChauffeurService', ['b2/11/1.jpg']],
            ['2027-01-04 08:00', ['linkedin' => 'Image post'], 'business', 'Back to work: set up the account first',
                'First week back. If your team will travel this quarter, set the account up now.',
                'VAT-registered business accounts with one invoice a month on 30-day terms, or pay by card when you book online. Airport runs with flight tracking, site visits and client pickups across South Yorkshire.',
                'Does your company use one travel supplier or book ad hoc?', $biz, ['b2/13/1.jpg']],
            ['2027-01-04 19:00', $photo3, 'airport', 'Flying out in January?',
                'Flying out in January?',
                "Manchester, Leeds Bradford, East Midlands or Heathrow. We drop you at the terminal, track your return flight, and send your driver's details before pickup.",
                'Where are you heading first in 2027?',
                '#AirportTransfer #ManchesterAirport #Sheffield #Travel2027 #ChauffeurService', ['b2/14/1.jpg']],
            ['2027-01-06 19:00', $car, 'weddings', 'Wedding day in the rain (carousel)',
                'It rained on the day. The cars still looked the part.',
                'V-Class and E-Class with ribbons on, parked up and ready for the wedding party. A V-Class takes 7, so the bridal party can travel together.',
                'Getting married in 2027? Drop your month below.', $wed, $range('15', 5)],
            ['2027-01-07 08:00', ['linkedin' => 'Image post'], 'business', 'Visitors flying in this month?',
                'Visitors flying in this month? Book their cars before they land.',
                "We track the flight and meet your visitor in arrivals. The booker gets the driver's details and a live tracking link before pickup, and finance gets one invoice a month from a VAT-registered company.",
                'How does your team look after visitors now?', $biz, ['b2/16/1.jpg']],
            ['2027-01-08 12:00', ['gbp' => 'Update'], 'cars', 'GBP: Executive cars for January travel', null,
                'Mercedes E-Class saloons for airport runs and business travel from Sheffield, Rotherham, Barnsley, Doncaster and Chesterfield. V-Class for up to 7 people and 7 bags, minibuses for bigger groups. Flights tracked, driver details and a live tracking link before pickup.',
                null, null, ['b2/17/1.jpg']],
            ['2027-01-10 19:00', $photo3, 'weddings', 'Ribbons on, seats for seven',
                'Ribbons on, seats for seven.',
                'The V-Class takes 7 people and 7 bags, so it suits the bridal party, the parents or the family run to the venue.',
                'Who rides with the bride on the day?', $wed, ['b2/18/1.jpg']],
            ['2027-01-12 08:00', ['linkedin' => 'Image post'], 'business', 'One invoice a month',
                'Finance teams want one invoice, not a pile of receipts.',
                'Our VAT-registered business accounts give you monthly invoicing on 30-day terms. Journeys are booked online, flights are tracked, and chauffeurs are council-licensed and enhanced-DBS checked.',
                'What would make travel admin easier for your team?', $biz, ['b2/19/1.jpg']],
            ['2027-01-13 19:00', $photo3, 'airport', 'Which airport do you fly from? (January)',
                'Manchester, Leeds Bradford or East Midlands?',
                'From South Yorkshire you have a choice, plus Heathrow for long haul. We drive to all of them, and every airport pickup is flight tracked.',
                'Which one do you fly from?',
                '#ManchesterAirport #LeedsBradfordAirport #EastMidlandsAirport #AirportTransfer #Sheffield', ['b2/20/1.jpg']],
            ['2027-01-14 12:00', ['gbp' => 'Update'], 'airport', 'GBP: Airport transfers in January', null,
                "Airport transfers from Sheffield and South Yorkshire to Manchester, Leeds Bradford, East Midlands, Heathrow and more. Flights tracked, meet and greet in arrivals, and your driver's details and a live tracking link before pickup.",
                null, null, ['b2/21/1.jpg']],
            ['2027-01-15 19:00', $photo2, 'sheffield', 'At the cathedral door',
                'Collected from Sheffield Cathedral.',
                'Weddings, services and special occasions across the city, in a Mercedes E-Class with a licensed chauffeur.',
                'Have you been to an event at the Cathedral?',
                '#Sheffield #SheffieldCathedral #WeddingCars #ChauffeurService #MercedesEClass', ['b2/22/1.jpg']],
        ];
    }

    private function caption(string $platform, ?string $hook, ?string $body, ?string $ask): ?string
    {
        if ($body === null) {
            return null; // Story: the cards carry the message
        }
        $lead = $hook ? $hook."\n\n" : '';
        $q = $ask ? "\n\n".$ask : '';

        return match ($platform) {
            'tiktok' => trim(($hook ? $hook.' ' : '').$ask),
            'instagram' => $lead.$body.$q."\n\nBook online: link in bio",
            'facebook', 'linkedin' => $lead.$body.$q."\n\nBook online at ".self::SITE,
            default => $body.' Tap Book to book online.', // gbp
        };
    }

    private function mediaNote(string $platform, string $format): ?string
    {
        return match (true) {
            $format === 'Story' && $platform === 'whatsapp' => 'Post as your WhatsApp Business Status (Updates tab → Add status). Post the cards in order.',
            $format === 'Story' => 'Post as a Story, cards in order. Add a link sticker to '.self::SITE.'.',
            $format === 'Reel' => 'Video is music-free: add a trending sound in the app.',
            $format === 'Photo carousel' => 'Post as a TikTok photo post (photos in this order) and add a trending sound.',
            $format === 'Carousel' => 'Post the photos in this order.',
            $platform === 'gbp' => 'Button: Book.',
            default => null,
        };
    }

    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasColumn('social_posts', 'media')) {
            return;
        }
        if (SocialPost::where('notes', self::NOTE)->exists()) {
            return;
        }
        $tz = config('app.timezone');

        foreach ($this->posts() as [$when, $platforms, $pillar, $title, $hook, $body, $ask, $tags, $files]) {
            foreach ($platforms as $platform => $format) {
                SocialPost::create([
                    'platform' => $platform,
                    'status' => 'ready',
                    'format' => $format,
                    'pillar' => $pillar,
                    'title' => $title,
                    'caption' => $this->caption($platform, $hook, $body, $ask),
                    'hashtags' => in_array($platform, ['gbp', 'whatsapp'], true) || $format === 'Story' ? null : $tags,
                    'media' => array_map(fn ($f) => 'lib:'.$f, $files),
                    'media_note' => $this->mediaNote($platform, $format),
                    'scheduled_at' => Carbon::parse($when, $tz),
                    'notes' => self::NOTE,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('social_posts')) {
            SocialPost::where('notes', self::NOTE)->where('status', '!=', 'posted')->delete();
        }
    }
};
