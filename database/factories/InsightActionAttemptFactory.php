<?php

namespace Database\Factories;

use App\Models\Insight;
use App\Models\InsightActionAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InsightActionAttempt>
 */
class InsightActionAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'insight_id' => Insight::factory(),
            'user_id' => User::factory(),
            'action_key' => 'mark_done',
            'status' => 'completed',
            'outcome' => null,
            'metadata' => [],
            'acted_at' => now(),
        ];
    }
}
