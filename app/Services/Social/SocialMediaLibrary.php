<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use Illuminate\Support\Carbon;

/**
 * Links the finished photos/videos shipped in resources/social-media to the
 * planned posts (matched by title), marks those posts "Ready to post", and adds
 * the six story posts (WhatsApp Status, Instagram and Facebook Stories). Every
 * file is a real CET photo or a CET text graphic; plates blurred where needed.
 * Safe to run twice: posts that already have media are left alone.
 */
class SocialMediaLibrary
{
    /** Post title => media keys, in posting order. */
    public const BY_TITLE = [
        'How a business account is set up' => ['p01/1.jpg'],
        'Business travel accounts' => ['p02/1.jpg'],
        'Before every job (your own footage)' => ['reels/prep.mp4'],
        'Illustrative airport transfer on an account' => ['p05/1.jpg'],
        'Book Christmas travel early' => ['p06/1.jpg'],
        '5 things before every airport pickup (your own footage)' => ['p05r/reel.mp4'],
        'Partner operators: cover both ways' => ['p07/1.jpg'],
        'Your ride around Sheffield (your own fleet photos)' => ['reels/fleet.mp4'],
        'Festive airport travel, book early' => ['p09/1.jpg'],
        'Which car for which trip (7 slides)' => ['p10/1.jpg', 'p10/2.jpg', 'p10/3.jpg', 'p10/4.jpg', 'p10/5.jpg', 'p10/6.jpg', 'p10/7.jpg'],
        'Which car for which trip (same slides)' => ['p10/1.jpg', 'p10/2.jpg', 'p10/3.jpg', 'p10/4.jpg', 'p10/5.jpg', 'p10/6.jpg', 'p10/7.jpg'],
        'Which airport do you fly from?' => ['p12/1.jpg'],
        'Sheffield winter evening' => ['p13/1.jpg'],
        'Flight running late? (5 slides)' => ['p14/1.jpg', 'p14/2.jpg', 'p14/3.jpg', 'p14/4.jpg', 'p14/5.jpg'],
        'Flight running late? (same slides)' => ['p14/1.jpg', 'p14/2.jpg', 'p14/3.jpg', 'p14/4.jpg', 'p14/5.jpg'],
        'Looking after visitors to your site' => ['p16/1.jpg'],
        'Christmas parties, book early' => ['p17/1.jpg'],
        'Five questions to ask any chauffeur firm' => ['p18/1.jpg', 'p18/2.jpg', 'p18/3.jpg', 'p18/4.jpg', 'p18/5.jpg', 'p18/6.jpg', 'p18/7.jpg'],
        'Christmas party transport home' => ['p19/1.jpg'],
        'Staff party and client evening, one invoice' => ['p20/1.jpg'],
        'Airport day: 3 things (slideshow)' => ['p21/reel.mp4'],
        'Pay your way and VAT invoices' => ['p22/1.jpg'],
        'Your first booking, step by step (6 slides)' => ['p23/1.jpg', 'p23/2.jpg', 'p23/3.jpg', 'p23/4.jpg', 'p23/5.jpg', 'p23/6.jpg'],
        'Your first booking, step by step (same slides)' => ['p23/1.jpg', 'p23/2.jpg', 'p23/3.jpg', 'p23/4.jpg', 'p23/5.jpg', 'p23/6.jpg'],
        'Where is your work party this year?' => ['p25/1.jpg'],
        'Working through Christmas and New Year' => ['p26/1.jpg'],
        'Your wedding day, arriving in style' => ['reels/wedding.mp4'],
        'Night pickup at Courtyard by Marriott Sheffield (Reel)' => ['courtyard/reel.mp4'],
        'Night pickup at Courtyard by Marriott Sheffield (carousel)' => ['courtyard/1.jpg', 'courtyard/2.jpg', 'courtyard/3.jpg', 'courtyard/4.jpg'],
    ];

    /** The six stories: file, title, when (UK). Each goes to WhatsApp, Instagram and Facebook. */
    public const STORIES = [
        ['story-1-car-waiting.jpg', 'Story: Your car is waiting', '2026-11-20 18:00', 'cars'],
        ['story-3-flight.jpg', 'Story: Flight late? We already know', '2026-11-21 10:00', 'airport'],
        ['story-4-groups.jpg', 'Story: Up to 7 together (V-Class)', '2026-11-22 12:00', 'cars'],
        ['story-2-business.jpg', 'Story: Business accounts', '2026-11-23 08:30', 'business'],
        ['story-5-christmas.jpg', 'Story: Flying over Christmas? Book early', '2026-11-24 18:00', 'seasonal'],
        ['story-6-weddings.jpg', 'Story: Arrive in style (weddings)', '2026-11-25 12:00', 'weddings'],
    ];

    /** @return array{attached:int, stories:int} */
    public function apply(): array
    {
        $attached = 0;
        foreach (self::BY_TITLE as $title => $files) {
            $keys = array_map(fn ($f) => 'lib:'.$f, $files);
            if (! SocialPost::mediaPath($keys[0])) {
                continue; // file not shipped (e.g. tests without the library)
            }
            foreach (SocialPost::where('title', $title)->get() as $post) {
                if ($post->mediaKeys()) {
                    continue;
                }
                $update = ['media' => $keys];
                if ($post->status === 'planned') {
                    $update['status'] = 'ready';
                }
                // The Sheffield evening post now uses a real photo, not an illustration.
                if ($title === 'Sheffield winter evening') {
                    $update['caption'] = trim(str_replace('Illustrative image.', '', (string) $post->caption));
                    $update['media_note'] = 'Real photo, plate blurred.';
                }
                $post->update($update);
                $attached++;
            }
        }

        $stories = 0;
        if (! SocialPost::where('title', 'like', 'Story:%')->exists()) {
            foreach (self::STORIES as [$file, $title, $when, $pillar]) {
                if (! SocialPost::mediaPath('lib:stories/'.$file)) {
                    continue;
                }
                foreach (['whatsapp', 'instagram', 'facebook'] as $platform) {
                    SocialPost::create([
                        'platform' => $platform,
                        'status' => 'ready',
                        'format' => 'Story',
                        'pillar' => $pillar,
                        'title' => $title,
                        'caption' => null,
                        'media' => ['lib:stories/'.$file],
                        'media_note' => $platform === 'whatsapp'
                            ? 'Post as your WhatsApp Business Status (Updates tab → Add status). Seen by everyone who has the office number saved.'
                            : 'Post as a Story. Add a link sticker to centralexecutivetransfers.co.uk.',
                        'scheduled_at' => Carbon::parse($when, config('app.timezone')),
                        'notes' => 'Story pack, 19 Nov 2026',
                    ]);
                    $stories++;
                }
            }
        }

        return ['attached' => $attached, 'stories' => $stories];
    }
}
