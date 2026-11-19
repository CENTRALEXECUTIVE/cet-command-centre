<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A social media post in the Social Media Command Centre. Times are UK local.
 */
class SocialPost extends Model
{
    public const PLATFORMS = [
        'tiktok' => 'TikTok',
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'linkedin' => 'LinkedIn',
        'gbp' => 'Google Business',
        'whatsapp' => 'WhatsApp Status',
    ];

    /** Chart / dot colour per platform. */
    public const COLOURS = [
        'tiktok' => '#00b3a8',
        'instagram' => '#e1306c',
        'facebook' => '#4f6bff',
        'linkedin' => '#0a66c2',
        'gbp' => '#2f9e44',
        'whatsapp' => '#25d366',
    ];

    public const STATUSES = [
        'idea' => 'Idea',
        'planned' => 'Planned',
        'ready' => 'Ready to post',
        'posted' => 'Posted',
        'skipped' => 'Skipped',
    ];

    public const PILLARS = [
        'behind_scenes' => 'Behind the scenes',
        'cars' => 'The cars',
        'airport' => 'Airport runs',
        'weddings' => 'Weddings & events',
        'trust' => 'Trust',
        'sheffield' => 'Sheffield',
        'business' => 'Business travel',
        'seasonal' => 'Seasonal offer',
    ];

    /** The engagement numbers typed in from each app's insights. */
    public const METRICS = ['views', 'likes', 'comments', 'shares', 'saves', 'follows', 'enquiries'];

    protected $fillable = [
        'platform', 'status', 'format', 'pillar', 'title', 'caption', 'hashtags', 'media_note', 'media',
        'scheduled_at', 'posted_at', 'url', 'views', 'likes', 'comments', 'shares', 'saves',
        'follows', 'enquiries', 'notes',
    ];

    protected $casts = [
        'media' => 'array',
        'scheduled_at' => 'datetime',
        'posted_at' => 'datetime',
        'views' => 'integer',
        'likes' => 'integer',
        'comments' => 'integer',
        'shares' => 'integer',
        'saves' => 'integer',
        'follows' => 'integer',
        'enquiries' => 'integer',
    ];

    public function platformLabel(): string
    {
        return self::PLATFORMS[$this->platform] ?? ucfirst((string) $this->platform);
    }

    public function colour(): string
    {
        return self::COLOURS[$this->platform] ?? '#999999';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function pillarLabel(): ?string
    {
        return $this->pillar ? (self::PILLARS[$this->pillar] ?? $this->pillar) : null;
    }

    /** When it went out, or when it's planned for. */
    public function when(): ?\Illuminate\Support\Carbon
    {
        return $this->posted_at ?? $this->scheduled_at;
    }

    /** Likes + comments + shares + saves (each app's "engagements"). */
    public function engagements(): int
    {
        return (int) $this->likes + (int) $this->comments + (int) $this->shares + (int) $this->saves;
    }

    /** Engagement rate as a percentage of views, or null with no views. */
    public function engagementRate(): ?float
    {
        return $this->views ? round($this->engagements() / $this->views * 100, 1) : null;
    }

    public function hasNumbers(): bool
    {
        foreach (self::METRICS as $m) {
            if ($this->{$m} !== null) {
                return true;
            }
        }

        return false;
    }

    /** Caption + hashtags, ready to paste into the app. */
    public function copyText(): string
    {
        return trim(trim((string) $this->caption)."\n\n".trim((string) $this->hashtags));
    }

    /*
     * Media: a list of keys. "lib:p10/2.jpg" = a finished file shipped with the app
     * (resources/social-media, privacy-checked, plates blurred); "up:12/x.jpg" = a
     * file the office uploaded (storage/app/social-uploads). Served admin-only.
     */
    public const LIBRARY_DIR = 'social-media';

    public const UPLOAD_DIR = 'social-uploads';

    /** Absolute path for a media key, or null when it's invalid or missing. */
    public static function mediaPath(string $key): ?string
    {
        if (! preg_match('#^(lib|up):([A-Za-z0-9_\-/]+\.(jpe?g|png|webp|mp4|mov))$#i', $key, $m)
            || str_contains($m[2], '..')) {
            return null;
        }
        $base = $m[1] === 'lib' ? resource_path(self::LIBRARY_DIR) : \Illuminate\Support\Facades\Storage::disk('local')->path(self::UPLOAD_DIR);
        $path = $base.'/'.$m[2];

        return is_file($path) ? $path : null;
    }

    public static function isVideoKey(string $key): bool
    {
        return (bool) preg_match('/\.(mp4|mov)$/i', $key);
    }

    /** @return list<string> media keys that exist on disk */
    public function mediaKeys(): array
    {
        return array_values(array_filter((array) ($this->media ?? []), fn ($k) => is_string($k) && self::mediaPath($k)));
    }

    public function firstImageKey(): ?string
    {
        foreach ($this->mediaKeys() as $k) {
            if (! self::isVideoKey($k)) {
                return $k;
            }
        }

        return null;
    }
}
