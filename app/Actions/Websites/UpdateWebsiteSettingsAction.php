<?php

namespace App\Actions\Websites;

use App\Models\Project;

class UpdateWebsiteSettingsAction
{
    public function __construct(private readonly UpdateGrowthContextAction $growthContext) {}

    /**
     * @param  array{name: string, timezone: string, growth_context?: array<string, mixed>}  $settings
     */
    public function handle(Project $project, array $settings): Project
    {
        $attributes = [
            'name' => $settings['name'],
            'timezone' => $settings['timezone'],
        ];

        if (isset($settings['growth_context'])) {
            $project->update($attributes);

            return $this->growthContext->handle($project, $settings['growth_context'])->load('domains');
        }

        $project->update($attributes);

        return $project->refresh()->load('domains');
    }
}
