<?php

use App\Models\SocialPost;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * One-off (directors, 18 Nov 2026): plan the Courtyard by Marriott Sheffield
 * night photos — a photo Reel (Mon 23 Nov 19:00, TikTok/Instagram/Facebook)
 * and a 4-photo carousel (Tue 24 Nov 19:30, Instagram/Facebook). Plates
 * blurred. Files: docs/growth/data/video/2026-11-18-courtyard (not in git).
 * No partnership with the hotel is implied — it's simply where we collected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('social_posts')) {
            return;
        }
        if (SocialPost::where('title', 'like', 'Night pickup at Courtyard%')->exists()) {
            return;
        }
        $tz = config('app.timezone');
        $tags = '#Sheffield #ChauffeurService #MercedesEClass #ExecutiveTravel #HotelTransfer';
        $reel = 'Ready: cet-courtyard-reel.mp4 (9s photo Reel, plates blurred, music-free). Add a trending sound in the app.';
        $body = "Collected from Courtyard by Marriott, Sheffield. Hotel pickups across Sheffield and South Yorkshire for airports, meetings and nights out, in a Mercedes E-Class with a licensed chauffeur.";

        foreach ([
            ['tiktok', 'Video', 'Your car is waiting. Hotel pickups across Sheffield in a Mercedes E-Class. Book at the link in bio.', $reel],
            ['instagram', 'Reel', "Your car is waiting.\n\n{$body}\n\nBook via the link in bio or WhatsApp +44 7405 172435.", $reel],
            ['facebook', 'Reel', "Your car is waiting.\n\n{$body}\n\nWhatsApp +44 7405 172435, call 0114 399 8242, or book online.", $reel],
        ] as [$platform, $format, $caption, $media]) {
            SocialPost::create([
                'platform' => $platform, 'status' => 'ready', 'format' => $format, 'pillar' => 'cars',
                'title' => 'Night pickup at Courtyard by Marriott Sheffield (Reel)',
                'caption' => $caption, 'hashtags' => $tags, 'media_note' => $media,
                'scheduled_at' => Carbon::parse('2026-11-23 19:00', $tz),
                'notes' => 'Courtyard photos, 18 Nov 2026',
            ]);
        }

        $car = 'Ready: cet-courtyard-carousel-1.jpg to -4.jpg (4:5, plates blurred). Post in that order.';
        foreach ([
            ['instagram', "Evening collection in Sheffield.\n\n{$body}\n\nBook via the link in bio or WhatsApp +44 7405 172435."],
            ['facebook', "Evening collection in Sheffield.\n\n{$body}\n\nWhatsApp +44 7405 172435, call 0114 399 8242, or book online."],
        ] as [$platform, $caption]) {
            SocialPost::create([
                'platform' => $platform, 'status' => 'ready', 'format' => 'Carousel', 'pillar' => 'cars',
                'title' => 'Night pickup at Courtyard by Marriott Sheffield (carousel)',
                'caption' => $caption, 'hashtags' => $tags, 'media_note' => $car,
                'scheduled_at' => Carbon::parse('2026-11-24 19:30', $tz),
                'notes' => 'Courtyard photos, 18 Nov 2026',
            ]);
        }
    }

    public function down(): void {}
};
