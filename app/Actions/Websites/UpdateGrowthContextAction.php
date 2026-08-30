<?php

namespace App\Actions\Websites;

use App\Models\Project;

class UpdateGrowthContextAction
{
    /**
     * @param  array<string, mixed>  $growthContext
     */
    public function handle(Project $project, array $growthContext): Project
    {
        $settings = is_array($project->settings) ? $project->settings : [];
        $context = $project->growthContext();

        foreach (['audience', 'products_services', 'value_proposition', 'brand_voice', 'primary_conversion_goals'] as $field) {
            if (array_key_exists($field, $growthContext)) {
                $context[$field] = $growthContext[$field];
            }
        }

        $settings['growth_context'] = $context;
        $project->update(['settings' => $settings]);

        return $project->refresh();
    }
}
