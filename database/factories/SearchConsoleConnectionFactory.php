<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\SearchConsoleConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchConsoleConnection>
 */
class SearchConsoleConnectionFactory extends Factory
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
            'connected_by_user_id' => User::factory(),
            'property_site_url' => 'sc-domain:'.$this->faker->domainName(),
            'property_type' => 'domain',
            'permission_level' => 'siteOwner',
            'access_token' => $this->faker->sha256(),
            'refresh_token' => $this->faker->sha256(),
            'access_token_expires_at' => now()->addHour(),
            'status' => 'connected',
        ];
    }
}
