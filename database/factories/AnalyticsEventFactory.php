<?php

namespace Database\Factories;

use App\Models\AnalyticsEvent;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AnalyticsEvent>
 */
class AnalyticsEventFactory extends Factory
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
            'event_id' => (string) Str::uuid(),
            'event_name' => 'page_view',
            'platform' => 'web',
            'visitor_id' => hash('sha256', fake()->uuid()),
            'session_id' => hash('sha256', fake()->uuid()),
            'path' => '/'.fake()->slug(),
            'referrer_host' => fake()->optional()->domainName(),
            'country' => fake()->countryCode(),
            'device' => fake()->randomElement(['desktop', 'mobile', 'tablet']),
            'browser' => fake()->randomElement(['Chrome', 'Safari', 'Firefox']),
            'operating_system' => fake()->randomElement(['Windows', 'macOS', 'Linux', 'iOS', 'Android']),
            'utm_source' => fake()->optional()->word(),
            'utm_medium' => fake()->optional()->randomElement(['organic', 'cpc', 'email']),
            'utm_campaign' => fake()->optional()->slug(),
            'properties' => ['path' => '/'],
            'occurred_at' => now(),
        ];
    }
}
