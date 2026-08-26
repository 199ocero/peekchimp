<?php

namespace Database\Factories;

use App\Models\Goal;
use App\Models\GoalConversion;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoalConversion>
 */
class GoalConversionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'goal_id' => Goal::factory(),
            'project_id' => Project::factory(),
            'session_id' => fake()->sha256(),
            'event_id' => null,
            'occurred_at' => now(),
        ];
    }
}
