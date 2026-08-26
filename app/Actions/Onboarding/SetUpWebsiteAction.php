<?php

namespace App\Actions\Onboarding;

use App\Models\Project;
use App\Models\User;
use App\Services\Websites\WebsiteDomainNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SetUpWebsiteAction
{
    public function __construct(private readonly WebsiteDomainNormalizer $domainNormalizer) {}

    /**
     * @param  array{name: string, url: string, timezone: string}  $website
     */
    public function handle(User $user, array $website): Project
    {
        return DB::transaction(function () use ($user, $website): Project {
            $workspaceOwner = $user->workspaceOwnerUser();
            $project = $workspaceOwner->projects()
                ->where('is_active', true)
                ->oldest()
                ->lockForUpdate()
                ->first();

            if ($project === null) {
                $project = $workspaceOwner->projects()->create([
                    'name' => $website['name'],
                    'site_key' => Str::random(40),
                    'timezone' => $website['timezone'],
                    'is_active' => true,
                    'settings' => [],
                ]);
            } else {
                $project->update([
                    'name' => $website['name'],
                    'timezone' => $website['timezone'],
                ]);
            }

            $project->domains()->delete();
            $project->domains()->create([
                'domain' => $this->domainNormalizer->normalize($website['url']),
                'is_verified' => false,
            ]);

            if ($user->current_project_id === null) {
                $user->currentProject()->associate($project);
                $user->save();
            }

            return $project->load('domains');
        });
    }
}
