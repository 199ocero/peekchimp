<?php

namespace Database\Factories;

use App\Models\AnalyticsSession;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsSession>
 */
class AnalyticsSessionFactory extends Factory
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
            'session_id' => hash('sha256', fake()->uuid()),
            'visitor_id' => hash('sha256', fake()->uuid()),
            'started_at' => now()->subMinutes(5),
            'last_seen_at' => now(),
            'pageviews' => 2,
            'custom_events' => 0,
            'duration_seconds' => 300,
            'is_bounce' => false,
            'entry_path' => '/',
            'exit_path' => '/pricing',
            'referrer_host' => null,
            'country' => 'US',
            'latitude' => null,
            'longitude' => null,
            'device' => 'desktop',
            'browser' => 'Chrome',
            'operating_system' => 'macOS',
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
        ];
    }
}
