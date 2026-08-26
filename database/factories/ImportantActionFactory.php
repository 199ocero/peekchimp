<?php

namespace Database\Factories;

use App\Models\ImportantAction;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportantAction>
 */
class ImportantActionFactory extends Factory
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
            'name' => 'Get started',
            'event_name' => 'get_started_clicked',
            'page_path' => '/',
            'is_active' => true,
        ];
    }
}
