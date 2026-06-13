<?php

namespace App\Services\Pricing;

use App\Models\Quote;
use App\Models\VehicleType;
use App\Services\Ai\AnthropicService;
use Illuminate\Support\Carbon;

/**
 * AI pricing engine (built on claude-opus-4-8).
 *
 * Produces a quote by first calculating a transparent rule-based fare
 * (distance, time, surcharges, bank holidays) and then asking Claude to review
 * it against demand and context, returning an adjusted price with a rationale.
 * The AI price is clamped to a safe band around the rule price, and the engine
 * falls back to the rule price whenever the AI is unavailable — a quote is
 * always produced.
 */
class AiPricingEngine
{
    public function __construct(
        private readonly DistanceService $distance,
        private readonly PricingRuleEngine $rules,
        private readonly AnthropicService $ai,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *                                       Required: pickup_address, destination_address, vehicle_type_id, pickup_at
     *                                       Optional: is_airport, distance_miles, duration_minutes, customer_id
     */
    public function quote(array $input): Quote
    {
        $vehicleType = VehicleType::findOrFail($input['vehicle_type_id']);
        $pickupAt = Carbon::parse($input['pickup_at']);
        $isAirport = (bool) ($input['is_airport'] ?? false);

        $distance = $this->distance->resolve(
            $input['pickup_address'],
            $input['destination_address'],
            $input['distance_miles'] ?? null,
            $input['duration_minutes'] ?? null,
        );

        $ruleResult = $this->rules->quote($vehicleType, $distance['miles'], $distance['minutes'], $pickupAt, $isAirport);
        $rulePrice = $ruleResult['total'];

        $ai = $this->reviewWithAi($vehicleType, $distance, $pickupAt, $isAirport, $rulePrice, $ruleResult['breakdown']);

        $finalPrice = $ai['price'] ?? $rulePrice;

        return Quote::create([
            'reference' => 'QUO-'.strtoupper(bin2hex(random_bytes(3))),
            'customer_id' => $input['customer_id'] ?? null,
            'vehicle_type_id' => $vehicleType->id,
            'pickup_address' => $input['pickup_address'],
            'destination_address' => $input['destination_address'],
            'distance_miles' => $distance['miles'],
            'duration_minutes' => $distance['minutes'],
            'pickup_at' => $pickupAt,
            'price' => $finalPrice,
            'ai_generated' => $ai['used'],
            'breakdown' => [
                'rule_price' => $rulePrice,
                'distance_source' => $distance['source'],
                'rule_breakdown' => $ruleResult['breakdown'],
                'ai_rationale' => $ai['rationale'],
                'model' => $ai['used'] ? $this->ai->model() : null,
            ],
            'expires_at' => now()->addHours(24),
        ]);
    }

    /**
     * Ask Claude to review the rule price. Returns the (clamped) AI price, a
     * rationale, and whether the AI was actually used.
     *
     * @return array{price: float|null, rationale: ?string, used: bool}
     */
    private function reviewWithAi(VehicleType $vehicleType, array $distance, Carbon $pickupAt, bool $isAirport, float $rulePrice, array $breakdown): array
    {
        if (! $this->ai->configured()) {
            return ['price' => null, 'rationale' => 'AI unavailable — rule-based price used.', 'used' => false];
        }

        $system = 'You are the pricing analyst for Central Executive Transfers, a Sheffield executive '
            .'chauffeur company. Given a rule-based fare and journey context, return a final GBP price that '
            .'reflects demand, time of day, day of week and bank holidays. Respond ONLY with JSON: '
            .'{"price": number, "rationale": string}. Keep within 25% below and 50% above the rule price.';

        $prompt = json_encode([
            'vehicle_type' => $vehicleType->name,
            'distance_miles' => $distance['miles'],
            'duration_minutes' => $distance['minutes'],
            'pickup_at' => $pickupAt->toIso8601String(),
            'day_of_week' => $pickupAt->format('l'),
            'is_airport' => $isAirport,
            'rule_price_gbp' => $rulePrice,
            'rule_breakdown' => $breakdown,
        ], JSON_PRETTY_PRINT);

        $result = $this->ai->completeJson($prompt, $system, ['max_tokens' => 512]);

        if (! $result || ! isset($result['price']) || ! is_numeric($result['price'])) {
            return ['price' => null, 'rationale' => 'AI response invalid — rule-based price used.', 'used' => false];
        }

        return [
            'price' => $this->clamp((float) $result['price'], $rulePrice),
            'rationale' => $result['rationale'] ?? null,
            'used' => true,
        ];
    }

    /** Keep the AI price within a safe band and rounded to the nearest 50p. */
    private function clamp(float $price, float $rulePrice): float
    {
        $price = max($rulePrice * 0.75, min($rulePrice * 1.5, $price));

        return round($price * 2) / 2;
    }
}
