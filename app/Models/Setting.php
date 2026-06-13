<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'type', 'description'];

    /** Fetch a setting value with light caching and type casting. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = Cache::rememberForever("setting:$key", fn () => static::where('key', $key)->first());

        if (! $setting) {
            return $default;
        }

        return match ($setting->type) {
            'int' => (int) $setting->value,
            'bool' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($setting->value, true),
            default => $setting->value,
        };
    }

    public static function set(string $key, mixed $value, string $type = 'string', string $group = 'general'): void
    {
        $stored = $type === 'json' ? json_encode($value) : (string) $value;
        static::updateOrCreate(['key' => $key], ['value' => $stored, 'type' => $type, 'group' => $group]);
        Cache::forget("setting:$key");
    }
}
