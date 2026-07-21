<?php

namespace App\Services\Marketing;

use App\Services\Ai\AnthropicService;

/**
 * Marketing "studio": generates on-brand social posts, ad copy, a weekly content
 * plan and SEO snippets for Central Executive Transfers, using the Claude AI the
 * Command Centre already runs on. Lets the office run their own marketing —
 * generate, tweak, post — with no agency retainer. Returns null (and the page
 * shows a friendly notice) when no AI key is configured.
 */
class ContentStudio
{
    /** Content types the studio can produce → label shown in the picker. */
    public const TYPES = [
        'social_posts' => 'Social media posts',
        'ad_copy' => 'Google / Facebook ad copy',
        'content_calendar' => 'One-week content plan',
        'seo_snippet' => 'Website SEO content',
    ];

    public function __construct(private readonly AnthropicService $ai) {}

    public function available(): bool
    {
        return $this->ai->configured();
    }

    /**
     * Generate content of the given type, optionally about a specific topic.
     * Returns the decoded JSON, or null if unavailable / the model misbehaved.
     *
     * @return array<string, mixed>|null
     */
    public function generate(string $type, ?string $topic = null): ?array
    {
        if (! isset(self::TYPES[$type])) {
            return null;
        }

        return $this->ai->completeJson($this->prompt($type, $topic), $this->brand(), ['max_tokens' => 2000]);
    }

    /** The brand voice + facts every prompt is grounded in. */
    private function brand(): string
    {
        return implode(' ', [
            'You write marketing content for Central Executive Transfers (CET), a premium executive-chauffeur and airport-transfer company in Sheffield, covering all of South Yorkshire.',
            'Brand: black & gold, refined, understated luxury. Taglines: "Driven by Excellence" and "Expectations. Exceeded." Font feel: Inter, clean and premium.',
            'Services: airport transfers (Manchester, Leeds Bradford, Doncaster, Heathrow, Birmingham, East Midlands), executive chauffeur, corporate travel, events & occasions, long distance.',
            'Fleet: executive saloons and V-Class/minibus, black Mercedes. Meet & greet at airports. Professional, punctual, discreet chauffeurs.',
            'Tone: confident, warm, professional — never cheap or salesy, never use excessive emojis or hype. British English.',
            'Booking: WhatsApp/phone +447405172435, website centralexecutivetransfers.co.uk.',
            'Always reply with ONLY valid JSON in the exact shape asked for — no preamble, no markdown.',
        ]);
    }

    private function prompt(string $type, ?string $topic): string
    {
        $about = $topic ? " The theme/topic to focus on: \"{$topic}\"." : '';

        return match ($type) {
            'social_posts' => 'Write 5 Facebook/Instagram posts for CET.'.$about
                .' Vary them (airport transfers, executive service, corporate, an occasion, a punctuality/peace-of-mind angle).'
                .' JSON: {"posts":[{"caption":"the post text, 2-4 short lines","hashtags":"5-8 relevant hashtags space-separated","image_idea":"one line describing the photo/graphic to pair with it"}]}',

            'ad_copy' => 'Write 4 Google/Facebook ad variations for CET.'.$about
                .' JSON: {"ads":[{"headline":"max 30 chars","long_headline":"max 90 chars","description":"max 90 chars","primary_text":"1-2 punchy sentences for a Facebook ad"}]}',

            'content_calendar' => 'Plan one week (Mon-Sun) of social content for CET.'.$about
                .' JSON: {"days":[{"day":"Monday","theme":"short theme","idea":"what to post","caption":"a ready-to-post caption"}]}',

            'seo_snippet' => 'Write SEO content for a CET website page.'.$about
                .' Target a realistic local keyword (e.g. "Sheffield to Manchester Airport transfers").'
                .' JSON: {"target_keyword":"the keyword","title":"SEO title max 60 chars","meta_description":"max 155 chars","heading":"H1 for the page","body":"2-3 paragraphs of on-page copy"}',

            default => '',
        };
    }
}
