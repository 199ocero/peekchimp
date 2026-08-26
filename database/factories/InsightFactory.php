<?php

namespace Database\Factories;

use App\Models\Insight;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Insight>
 */
class InsightFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'fingerprint' => fake()->unique()->sha1(),
            'category' => 'traffic',
            'type' => 'traffic_change',
            'severity' => 'info',
            'metric' => 'visitors',
            'current_value' => 100,
            'previous_value' => 80,
            'percentage_change' => 25,
            'confidence' => 'medium',
            'summary' => 'Visitors increased 25%.',
            'recommendation' => 'Review the sources driving the increase.',
            'metadata' => [],
            'period_start' => now()->subDays(6)->startOfDay(),
            'period_end' => now()->endOfDay(),
            'generated_at' => now(),
            'expires_at' => now()->addDay(),
        ];
    }
}
