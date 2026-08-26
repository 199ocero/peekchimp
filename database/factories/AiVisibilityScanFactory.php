<?php

namespace Database\Factories;

use App\Models\AiVisibilityScan;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiVisibilityScan>
 */
class AiVisibilityScanFactory extends Factory
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
            'status' => 'completed',
            'score' => 75,
            'findings' => [],
            'error' => null,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ];
    }
}
