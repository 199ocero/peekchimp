<?php

namespace App\Actions\Websites;

use App\Models\Project;
use App\Models\User;
use App\Services\Websites\WebsiteDomainNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateWebsiteAction
{
    public function __construct(private readonly WebsiteDomainNormalizer $domainNormalizer) {}

    /**
     * @param  array{name: string, url: string, timezone: string}  $website
     */
    public function handle(User $user, array $website): Project
    {
        return DB::transaction(function () use ($user, $website): Project {
            $workspaceOwner = $user->workspaceOwnerUser();
            $domain = $this->domainNormalizer->normalize($website['url']);

            $duplicate = $workspaceOwner->projects()
                ->where('is_active', true)
                ->whereHas('domains', fn ($query) => $query->where('domain', $domain))
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'url' => 'This website is already connected to your account.',
                ]);
            }

            $project = $workspaceOwner->projects()->create([
                'name' => $website['name'],
                'site_key' => Str::random(40),
                'timezone' => $website['timezone'],
                'is_active' => true,
                'settings' => [],
            ]);

            $project->domains()->create([
                'domain' => $domain,
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
