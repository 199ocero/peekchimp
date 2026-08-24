<?php

namespace App\Actions\Websites;

use App\Models\Project;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdateWebsiteSharingAction
{
    /**
     * @param  array<int, string>  $sections
     */
    public function handle(Project $project, bool $enabled, array $sections): Project
    {
        return DB::transaction(function () use ($project, $enabled, $sections): Project {
            $project = Project::query()->whereKey($project)->lockForUpdate()->firstOrFail();

            if ($enabled && $project->public_share_token === null) {
                [$token, $tokenHash] = $this->newTokenPair();
                $project->public_share_token = $token;
                $project->public_share_token_hash = $tokenHash;
            }

            $settings = $project->getAttribute('settings');

            if (! is_array($settings)) {
                $settings = [];
            }

            Arr::set($settings, 'public_dashboard.sections', array_values($sections));
            $project->setAttribute('settings', $settings);
            $project->public_share_enabled_at = $enabled
                ? ($project->public_share_enabled_at ?? now())
                : null;
            $project->save();

            return $project->load('domains');
        });
    }

    public function rotate(Project $project): Project
    {
        return DB::transaction(function () use ($project): Project {
            $project = Project::query()->whereKey($project)->lockForUpdate()->firstOrFail();
            [$token, $tokenHash] = $this->newTokenPair();
            $project->public_share_token = $token;
            $project->public_share_token_hash = $tokenHash;
            $project->save();

            return $project->load('domains');
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function newTokenPair(): array
    {
        do {
            $token = Str::random(64);
            $tokenHash = hash('sha256', $token);
        } while (Project::query()->where('public_share_token_hash', $tokenHash)->exists());

        return [$token, $tokenHash];
    }
}
