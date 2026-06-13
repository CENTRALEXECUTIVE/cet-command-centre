<?php

namespace Database\Factories;

use App\Models\AdMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdMetric>
 */
class AdMetricFactory extends Factory
{
    public function definition(): array
    {
        $spend = fake()->randomFloat(2, 20, 200);

        return [
            'date' => fake()->unique()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'campaign' => 'CET Airport Transfers',
            'spend' => $spend,
            'revenue' => $spend * fake()->randomFloat(2, 2, 6),
            'conversions' => fake()->numberBetween(0, 10),
            'jobs' => fake()->numberBetween(0, 8),
            'clicks' => fake()->numberBetween(10, 200),
            'impressions' => fake()->numberBetween(500, 5000),
        ];
    }
}
