<?php

namespace Database\Factories;

use App\Models\Goal;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Goal>
 */
class GoalFactory extends Factory
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
            'name' => 'Signup',
            'event_name' => 'signup',
            'property_match' => null,
            'is_active' => true,
        ];
    }
}
