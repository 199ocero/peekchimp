<?php

namespace Database\Factories;

use App\Models\AnalyticsRollup;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsRollup>
 */
class AnalyticsRollupFactory extends Factory
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
            'granularity' => 'day',
            'bucket_start' => CarbonImmutable::now()->startOfDay(),
            'dimension' => 'overall',
            'dimension_value' => '',
            'pageviews' => 0,
            'visitors' => 0,
            'visits' => 0,
            'events' => 0,
            'bounces' => 0,
            'duration_seconds' => 0,
            'conversions' => 0,
        ];
    }
}
