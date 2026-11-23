<?php

use App\Models\SocialPost;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Directors, 23 Nov 2026: nine new photos (boot with two large cases, rear seats with
 * water and USB, E-Class on a country driveway at dawn, E-Class at a hotel entrance).
 * Batch 3 covers Sat 16 to Fri 29 Jan 2027, NIGHT theme. Images: resources/social-media/b3/.
 * House rules: book online only, no phone numbers, no prices, plates blurred, no venue named.
 */
return new class extends Migration
{
    private const NOTE = 'Batch 3 (new photos), 23 Nov 2026';

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
        $range = fn (string $d, int $n) => array_map(fn ($i) => "b3/$d/$i.jpg", range(1, $n));

        $air = '#AirportTransfer #ManchesterAirport #Sheffield #ChauffeurService #MercedesEClass';
        $lux = '#ChauffeurService #MercedesEClass #ExecutiveTravel #Sheffield #SouthYorkshire';
        return [
            ['2027-01-16 19:00', $car, 'airport', 'Two big cases? Easy (carousel)',
                'Two big cases? Easy.',
                'Both large suitcases fit in the boot of our E-Class, with room for 4 passengers. Travelling heavier? The V-Class takes 7 people and 7 bags. Add your luggage when you book online and we send the right car.',
                'How many cases do you usually take on holiday?', $air, $range('01', 2)],
            ['2027-01-18 19:00', $car, 'cars', 'Your seat, ready (carousel)',
                'Your seat, ready.',
                'Fresh water in the armrest, USB charging in the back, and the car cleaned inside and out before every job.',
                'Water or a phone charge: which do you reach for first?', $lux, $range('02', 2)],
            ['2027-01-20 08:00', ['linkedin' => 'Image post'], 'business', 'Business travel, sorted',
                'Business travel, sorted.',
                "VAT-registered business accounts with monthly invoicing on 30-day terms. Your visitors are met, flights are tracked, and the booker gets the driver's details and a live tracking link before pickup. Book online any time.",
                'Who books travel for your team?', $biz, ['b3/05/1.jpg']],
            ['2027-01-21 19:00', $photo3, 'airport', "Early start? We're outside",
                "Early start? We're outside.",
                "Collected from your door, whatever the weather. You get the driver's details before pickup, so you know exactly who is coming.",
                'What is the earliest flight you have ever caught?', $air, ['b3/03/1.jpg']],
            ['2027-01-22 18:00', $story, 'cars', 'Story: Early start / waiting / bags / sit back', null, null, null, null, $range('06', 4)],
            ['2027-01-23 12:00', ['gbp' => 'Update'], 'cars', 'GBP: new photos (entrance, door, rear seats)', null,
                'Mercedes E-Class chauffeur transfers from Sheffield, Rotherham, Barnsley, Doncaster and Chesterfield. Flights tracked, meet and greet in arrivals, fresh water and USB charging on board. Book online any time.',
                null, null, $range('07', 3)],
            ['2027-01-26 19:00', $photo3, 'airport', 'Dropped at the entrance',
                'Dropped at the entrance.',
                'Hotels, terminals and offices: we pull up at the door, help with the bags and you walk straight in.',
                'Where do we drop you most often?', $lux, ['b3/04/1.jpg']],
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
