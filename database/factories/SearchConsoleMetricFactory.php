<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\SearchConsoleMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchConsoleMetric>
 */
class SearchConsoleMetricFactory extends Factory
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
            'report_date' => $this->faker->dateTimeBetween('-90 days'),
            'search_type' => 'web',
            'dimension_type' => 'property',
            'dimension_value' => '',
            'dimension_hash' => sha1(''),
            'clicks' => $this->faker->numberBetween(0, 1000),
            'impressions' => $this->faker->numberBetween(1, 10000),
            'position' => $this->faker->randomFloat(4, 1, 100),
        ];
    }
}
