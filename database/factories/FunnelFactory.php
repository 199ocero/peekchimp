<?php

namespace Database\Factories;

use App\Models\Funnel;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Funnel>
 */
class FunnelFactory extends Factory
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
            'name' => 'Signup funnel',
            'is_active' => true,
        ];
    }
}
