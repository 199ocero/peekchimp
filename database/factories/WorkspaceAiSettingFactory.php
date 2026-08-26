<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkspaceAiSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceAiSetting>
 */
class WorkspaceAiSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_owner_id' => User::factory(),
            'provider' => 'openai',
            'model' => null,
            'api_key' => null,
            'base_url' => null,
            'is_enabled' => false,
            'status' => 'not_configured',
            'last_tested_at' => null,
            'last_error' => null,
        ];
    }
}
