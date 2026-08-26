<?php

namespace Database\Factories;

use App\Models\AiInsightRun;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiInsightRun>
 */
class AiInsightRunFactory extends Factory
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
            'context_hash' => sha1((string) Str::uuid()),
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'status' => 'completed',
            'candidate_count' => 1,
            'input_tokens' => null,
            'output_tokens' => null,
            'error' => null,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ];
    }
}
