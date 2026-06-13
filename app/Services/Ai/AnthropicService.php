<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for the Anthropic Claude Messages API. All AI features in the CET
 * Command Centre run on claude-opus-4-8 (config cet.ai_model). When no API key
 * is configured the client reports unavailable so callers can fall back to a
 * deterministic path.
 */
class AnthropicService
{
    public function configured(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    public function model(): string
    {
        return config('cet.ai_model', 'claude-opus-4-8');
    }

    /**
     * Send a single-turn prompt and return the assistant's text, or null on
     * failure / when unconfigured.
     *
     * @param  array<string, mixed>  $options
     */
    public function complete(string $prompt, ?string $system = null, array $options = []): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => config('services.anthropic.key'),
                'anthropic-version' => config('services.anthropic.version', '2023-06-01'),
                'content-type' => 'application/json',
            ])->timeout($options['timeout'] ?? 20)
                ->post(rtrim(config('services.anthropic.base_url'), '/').'/v1/messages', [
                    'model' => $this->model(),
                    'max_tokens' => $options['max_tokens'] ?? 1024,
                    'system' => $system,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if ($response->successful()) {
                return $response->json('content.0.text');
            }

            Log::warning('Anthropic request failed', ['status' => $response->status(), 'body' => $response->body()]);
        } catch (\Throwable $e) {
            Log::warning('Anthropic request exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Convenience: request a JSON object back and decode it. Returns null if the
     * model is unavailable or the response is not valid JSON.
     *
     * @return array<string, mixed>|null
     */
    public function completeJson(string $prompt, ?string $system = null, array $options = []): ?array
    {
        $text = $this->complete($prompt, $system, $options);

        if (! $text) {
            return null;
        }

        // Tolerate prose around the JSON by extracting the first {...} block.
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
