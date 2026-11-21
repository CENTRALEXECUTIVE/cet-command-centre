<?php

use App\Models\SocialPost;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Directors, 21 Nov 2026: "redo all the captions". Every planned post that
 * hasn't gone out yet gets a shorter, human caption: a scroll-stopping first
 * line, one or two plain sentences, and a question people can answer in the
 * comments (comments are what the apps reward). Posted posts are never touched.
 *
 * Also adds the Holiday Inn Manchester Airport night photos (carousel Sun 22 Nov,
 * LinkedIn Wed 25 Nov, Google Thu 26 Nov). No partnership with the hotel is
 * implied: it's simply where we collected. House rules kept: no prices, no
 * customer names or faces, nothing invented.
 */
return new class extends Migration
{
    private const WA = '+44 7405 172435';

    private const PHONE = '0114 399 8242';

    /** title => [first line, body, question]. Same text adapts per platform. */
    private function captions(): array
    {
        return [
            // ---- November plan ----
            'How a business account is set up' => ['Finance teams ask us one thing first: what will the invoice look like?',
                "So we ask them before we set anything up. One invoice a month, a PO or cost-centre on every journey line, 30-day terms, or simply pay by card when you book. Central Executive Transfers Ltd is VAT registered.",
                'If you book travel for your team, what makes the invoices painful today?'],
            'Business travel accounts' => [null,
                'Business accounts for Sheffield and South Yorkshire companies: VAT invoices, monthly billing, your PO on every journey and 30-day terms. Licensed chauffeurs, flight tracking on airport runs and the phone answered 24/7.', null],
            'Before every job (your own footage)' => ['This happens before every single pickup.',
                'Car cleaned inside and out, water in the back, route checked. Our chauffeurs are council-licensed and enhanced-DBS checked.',
                'What matters most to you when someone picks you up?'],
            'Illustrative airport transfer on an account' => ['Your visitor lands at 07:10. The flight is 40 minutes late. What happens?',
                "(An example, not a real client.) We track the flight, so the driver already knows and adjusts. They meet your visitor in arrivals. You get the driver's details and a live tracking link. Finance gets one VAT invoice with your PO on it.",
                'Who in your company books travel for visitors?'],
            'Book Christmas travel early' => ['Christmas is five weeks away. The cars go fast.',
                'Work party, family night out, airport run over the holidays: if the date is fixed, book it now. A V-Class takes 7 people and 7 bags, and we have minibuses for bigger groups.',
                "What's the first thing in your diary this December?"],
            '5 things before every airport pickup (your own footage)' => ['5 things we do before every airport pickup.',
                "1. Track your flight\n2. Clean the car inside and out\n3. Put fresh water in\n4. Send you the driver's details\n5. Meet you in arrivals (with meet and greet)",
                'Which one matters most to you?'],
            'Partner operators: cover both ways' => ['Operators: what do you do when a job comes in and every car is out?',
                'We are a licensed operator in Sheffield (Operator Licence OP037, Sheffield City Council). We cover work for other operators when we can, and pass work on when we are full. Executive saloons and V-Class.',
                'Would cover in both directions help you? Comment or message me.'],
            'Your ride around Sheffield (your own fleet photos)' => ['Your ride around Sheffield.',
                'Mercedes E-Class for business and airport runs, V-Class when the whole group is going. Licensed chauffeurs, live tracking, the phone answered 24/7.',
                'Saloon or V-Class: which one are you booking?'],
            'Festive airport travel, book early' => [null,
                "Flying over Christmas or New Year? We work right through, Christmas Day included. Flight tracking so a late landing is covered, meet and greet in arrivals, phone answered 24/7. Book early.", null],
            'Which car for which trip (7 slides)' => ['Which car do you actually need? Swipe.',
                "Saloon: 4 people, 2 cases\nEstate: 4 people, 4 cases\nV-Class: 7 people, 7 cases\nMinibus: 7 people, 5 cases\nMinibus XL: 8 people, 8 cases",
                'How many of you, and how many bags? Tell us and we will pick the car.'],
            'Which car for which trip (same slides)' => ['Not sure which car you need? Swipe.',
                "Saloon for 4 people and 2 cases, Estate for 4 and 4, V-Class for 7 and 7, minibuses for bigger groups.",
                'Comment your numbers and we will tell you which one.'],
            'Which airport do you fly from?' => ['Manchester, Leeds Bradford or East Midlands?',
                'From South Yorkshire you have a few choices, plus Heathrow, Gatwick and Liverpool for the long hauls. We drive to all of them.',
                'Which one do you fly from, and what is the worst part of getting there?'],
            'Sheffield winter evening' => ['Dark by half four. Wet roads. Early flight.',
                'If you would rather not drive this winter, we are on the phone and WhatsApp 24/7 across Sheffield, Rotherham, Barnsley, Doncaster and Chesterfield.',
                'Early flights or late landings: which do you dread more?'],
            'Flight running late? (5 slides)' => ['Your flight is late. Do you need to ring us? No.',
                "We track every airport pickup. If you land late, the driver already knows and adjusts, then meets you in arrivals. You get their details before pickup and a live tracking link.",
                'What is the longest delay you have sat through?'],
            'Flight running late? (same slides)' => ['What happens if your flight lands late?',
                "Nothing you need to do. We track it, the driver adjusts and meets you in arrivals. Their details and a live tracking link come to your phone.",
                'Ever been stranded at arrivals? Tell us where.'],
            'Looking after visitors to your site' => ['When a visitor flies in, the worry is rarely the car.',
                "It is whether anyone knows where they are. We track the flight, meet them in arrivals, and send the booker the driver's details and a live tracking link. One VAT invoice at the end, with your PO on it.",
                'How does your team handle visitor travel now?'],
            'Christmas parties, book early' => [null,
                'Planning a Christmas party or work night out in Sheffield? A V-Class takes 7, minibuses take bigger groups, so everyone arrives and gets home together. We work right through the holidays.', null],
            'Five questions to ask any chauffeur firm' => ['Before you open an account with any chauffeur firm, ask these five questions.',
                "1. Is the operator licensed? (Ours: OP037, Sheffield City Council.)\n2. Are drivers enhanced-DBS checked?\n3. Do they track flights?\n4. Will the booker get driver details and live tracking?\n5. Can invoices carry your PO?",
                'What would you add to the list?'],
            'Christmas party transport home' => ['The best part of the work do? Not worrying about getting home.',
                'One booking gets the group there and home together. A V-Class takes 7, minibuses take more.',
                'Where is your Christmas do this year?'],
            'Staff party and client evening, one invoice' => ['Transport is the last thing booked and the first thing people remember.',
                'For a staff party or client evening, one booking covers the group, from a V-Class for 7 to minibuses for more. One VAT invoice with your PO on it.',
                'Who organises the Christmas do in your office?'],
            'Airport day: 3 things (slideshow)' => ['Airport day? Three things.',
                'Pick a car that fits your bags. Book the return when you book the outward trip. Keep your phone on for the driver details.',
                'Which one do people forget?'],
            'Pay your way and VAT invoices' => [null,
                'Pay by card, secure payment link or cash, with a VAT invoice if your business needs one. Price fixed up front, flights tracked, phone answered 24/7 on '.self::PHONE.'.', null],
            'Your first booking, step by step (6 slides)' => ['Never booked a chauffeur before? This is all it takes.',
                "1. Get your price online\n2. Pay your way: card, link or cash\n3. Driver details arrive before pickup\n4. Track your driver live",
                'What would you want to know before your first booking?'],
            'Your first booking, step by step (same slides)' => ['First time booking with us? Here is how it goes.',
                'Get your price online, pay by card, link or cash, receive your driver details before pickup and track them live.',
                'Any questions? Ask in the comments.'],
            'Where is your work party this year?' => ['Where is your work party this year?',
                'Town, out in the Peaks, or somewhere closer to home? A V-Class takes 7 and minibuses take more, so nobody has to drive.',
                'Tell us in the comments.'],
            'Working through Christmas and New Year' => ['Christmas Day, Boxing Day, New Year. We are working.',
                'Airport runs, family visits and party nights, with licensed chauffeurs and the phone answered 24/7. December is filling.',
                'Travelling over Christmas or staying put?'],
            'Your wedding day, arriving in style' => ['The car is in every photo. Make it a good one.',
                'Ribbons on, matching cars, and a chauffeur who knows the timings matter. Weddings across Sheffield and South Yorkshire.',
                'Getting married in 2027? Drop your month below.'],
            // ---- Courtyard photos ----
            'Night pickup at Courtyard by Marriott Sheffield (Reel)' => ['Your car is outside.',
                'Collected from Courtyard by Marriott, Sheffield. Hotel pickups across Sheffield for airports, meetings and nights out, in a Mercedes E-Class with a licensed chauffeur.',
                'Staying in Sheffield this month? Where?'],
            'Night pickup at Courtyard by Marriott Sheffield (carousel)' => ['Friday night, Sheffield.',
                'An evening collection from Courtyard by Marriott. Hotel pickups across the city, Mercedes E-Class, licensed chauffeur.',
                'Best hotel in Sheffield? Go.'],
            // ---- December plan ----
            'Christmas airport runs: book early' => ['Flying over Christmas? Read this first.',
                'We track every flight, so a delay is covered, and with meet and greet your driver is waiting in arrivals. Saloons, and a V-Class for the family and the bags. We work right through.',
                'Where are you flying this Christmas?'],
            'Year-end visitors: book their cars now' => ['Visitors flying in before Christmas?',
                "December flights fill up, and so do cars. If clients or engineers are visiting before the break, book their transfers now. We track the flight, meet them in arrivals, and send the booker the driver details. Finance gets a VAT invoice with your PO on it.",
                'How many visitors does your site get in December?'],
            'Party night? Sort the ride first (carousel)' => ['Party night? Sort the ride first.',
                'Book before the night. One car for the group (a V-Class takes 7). Book the ride home when you book the ride there. Agree the pickup point.',
                'Tag the person organising your work do.'],
            'Night out in Sheffield? Home together' => ['Night out in Sheffield?',
                'Picked up at the door, home together at the end. No taxi queue, nobody left behind. Saloons, V-Class for 7, minibuses for more.',
                'Where are you heading out this Christmas?'],
            'Christmas markets day out' => ['Christmas markets, and nobody has to drive.',
                'York, Manchester, Leeds: a V-Class takes 7, so the whole group travels together.',
                'York or Manchester markets? Pick one.'],
            'GBP: Christmas and New Year airport transfers' => [null,
                'Christmas and New Year airport transfers from Sheffield and South Yorkshire. Flight tracking, meet and greet in arrivals, phone answered 24/7. We work right through. Tap Book or call '.self::PHONE.'.', null],
            'Start January with an account in place' => ['Set it up now, and January is one message.',
                'If your team travels in the new year, open the account before the break. Monthly VAT invoices, a PO or cost-centre on every journey, 30-day terms. Chauffeurs are council-licensed and enhanced-DBS checked.',
                'Does your company use one travel supplier or book ad hoc?'],
            'Winter weddings' => ['Winter weddings hit different.',
                'Matching cars, ribbons on request, and a chauffeur who keeps you on time. Booking for 2027? Ask about your date.',
                'Winter or summer wedding? Settle it.'],
            "New Year's Eve: plan the ride home (carousel)" => ["New Year's Eve: plan the ride home now.",
                "One of the busiest nights of the year. Book early, choose the car for your group, fix the pickup time, keep your phone on for the driver details.",
                "Send this to whoever's organising."],
            'Which car for your group this Christmas' => ['How many of you are going?',
                'Saloon for 4 people and 2 bags, V-Class for 7 people and 7 bags, minibuses for bigger groups. Tell us your numbers and we pick the car.',
                'Comment your group size.'],
            'Working Christmas Day, Boxing Day and New Year' => ["We're working through the holidays.",
                "Christmas Day, Boxing Day and New Year's Day included. Airport runs, family visits and party nights, phone answered 24/7. The holidays fill up, so book early.",
                'Working over Christmas too? Say hi.'],
            'GBP: New Year airport transfers, book early' => [null,
                'Flying out for New Year or back after the holidays? Book your airport transfer from Sheffield and South Yorkshire now. Saloons and V-Class, flight tracking, meet and greet. Call '.self::PHONE.' or tap Book.', null],
            'Thank you to our business clients' => ['Thank you.',
                'To every business that travelled with us this year: visitors met at arrivals, teams to site, client evenings home safely. If you want an account ready for January, message me before the break.',
                'What is your team planning for 2027?'],
            // ---- New: Holiday Inn Manchester Airport ----
            'Manchester Airport hotel pickup (carousel)' => ['Staying at the airport the night before?',
                'Collected from Holiday Inn Manchester Airport. Early flight, late landing or a night near the terminal before a long trip: your car is outside when you need it. Mercedes E-Class, licensed chauffeur, flight tracked.',
                'Do you stay over before an early flight, or drive in on the day?'],
            'Visitors staying at the airport hotel' => ['Your visitor lands late and stays at the airport hotel. Then what?',
                "We collect from the Manchester Airport hotels too, so a visitor can land, sleep and be on site by nine. Flight tracked, driver details sent to the booker, one VAT invoice with your PO.",
                'Do your visitors stay near the airport or in town?'],
            'GBP: Manchester Airport hotel pickups' => [null,
                'Staying at a Manchester Airport hotel? We collect from the hotel door for early flights, late landings and trips home to Sheffield and South Yorkshire. Mercedes E-Class, licensed chauffeur. Call '.self::PHONE.' or tap Book.', null],
        ];
    }

    private function caption(string $platform, ?string $hook, string $body, ?string $ask): string
    {
        $lead = $hook ? $hook."\n\n" : '';
        $q = $ask ? "\n\n".$ask : '';

        return match ($platform) {
            'tiktok' => trim(($hook ? $hook.' ' : '').str_replace("\n", ' ', $body).($ask ? ' '.$ask : '')),
            'instagram' => $lead.$body.$q."\n\nBook: link in bio or WhatsApp ".self::WA,
            'facebook' => $lead.$body.$q."\n\nWhatsApp ".self::WA.' · call '.self::PHONE.' · or book online',
            'linkedin' => $lead.$body.$q,
            default => $body, // gbp
        };
    }

    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('social_posts')) {
            return;
        }

        $this->addHotelPosts();

        foreach ($this->captions() as $title => [$hook, $body, $ask]) {
            SocialPost::where('title', $title)->where('status', '!=', 'posted')->whereNotNull('caption')
                ->get()->each(function (SocialPost $p) use ($hook, $body, $ask) {
                    $p->forceFill(['caption' => $this->caption((string) $p->platform, $hook, $body, $ask)])->save();
                });
        }
    }

    private function addHotelPosts(): void
    {
        if (SocialPost::where('title', 'Manchester Airport hotel pickup (carousel)')->exists()) {
            return;
        }
        $tz = config('app.timezone');
        $photos = ['lib:hotel/4.jpg', 'lib:hotel/5.jpg', 'lib:hotel/2.jpg', 'lib:hotel/3.jpg', 'lib:hotel/1.jpg'];
        $tags = '#ManchesterAirport #AirportTransfer #Sheffield #MercedesEClass #ChauffeurService';
        $note = 'Holiday Inn photos, 21 Nov 2026';

        foreach (['instagram' => 'Carousel', 'facebook' => 'Carousel', 'tiktok' => 'Photo carousel'] as $platform => $format) {
            SocialPost::create([
                'platform' => $platform, 'status' => 'ready', 'format' => $format, 'pillar' => 'airport',
                'title' => 'Manchester Airport hotel pickup (carousel)', 'caption' => 'x', 'hashtags' => $tags,
                'media' => $photos,
                'media_note' => $platform === 'tiktok' ? 'Post as a TikTok photo carousel and add a trending sound.' : 'Post the photos in this order.',
                'scheduled_at' => Carbon::parse('2026-11-22 19:00', $tz), 'notes' => $note,
            ]);
        }
        SocialPost::create([
            'platform' => 'linkedin', 'status' => 'ready', 'format' => 'Image post', 'pillar' => 'business',
            'title' => 'Visitors staying at the airport hotel', 'caption' => 'x',
            'hashtags' => '#CorporateTravel #ManchesterAirport #SheffieldBusiness #SouthYorkshire #BusinessTravel',
            'media' => ['lib:hotel/4.jpg'], 'scheduled_at' => Carbon::parse('2026-11-25 08:00', $tz), 'notes' => $note,
        ]);
        SocialPost::create([
            'platform' => 'gbp', 'status' => 'ready', 'format' => 'Update', 'pillar' => 'airport',
            'title' => 'GBP: Manchester Airport hotel pickups', 'caption' => 'x',
            'media' => ['lib:hotel/gbp.jpg'], 'media_note' => 'Button: Book.',
            'scheduled_at' => Carbon::parse('2026-11-26 12:00', $tz), 'notes' => $note,
        ]);
    }

    public function down(): void {}
};
