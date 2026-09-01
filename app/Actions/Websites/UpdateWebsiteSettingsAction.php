<?php

namespace App\Actions\Websites;

use App\Models\Project;

class UpdateWebsiteSettingsAction
{
    public function __construct(private readonly UpdateGrowthContextAction $growthContext) {}

    /**
     * @param  array{name: string, timezone: string, autocapture_enabled?: bool, growth_context?: array<string, mixed>}  $settings
     */
    public function handle(Project $project, array $settings): Project
    {
        $attributes = [
            'name' => $settings['name'],
            'timezone' => $settings['timezone'],
        ];

        if (array_key_exists('autocapture_enabled', $settings)) {
            $projectSettings = is_array($project->settings) ? $project->settings : [];
            $analyticsSettings = is_array($projectSettings['analytics'] ?? null) ? $projectSettings['analytics'] : [];
            $analyticsSettings['autocapture_enabled'] = $settings['autocapture_enabled'];
            $projectSettings['analytics'] = $analyticsSettings;
            $attributes['settings'] = $projectSettings;
        }

        if (isset($settings['growth_context'])) {
            $project->update($attributes);

            return $this->growthContext->handle($project, $settings['growth_context'])->load('domains');
        }

        $project->update($attributes);

        return $project->refresh()->load('domains');
    }
}
