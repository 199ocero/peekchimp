<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectDomain>
 */
class ProjectDomainFactory extends Factory
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
            'domain' => fake()->domainName(),
            'is_verified' => false,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => true,
        ]);
    }
}
