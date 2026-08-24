<?php

namespace App\Actions\Websites;

use App\Models\Project;

class UpdateWebsiteSettingsAction
{
    /**
     * @param  array{name: string, timezone: string}  $settings
     */
    public function handle(Project $project, array $settings): Project
    {
        $project->update($settings);

        return $project->refresh()->load('domains');
    }
}
