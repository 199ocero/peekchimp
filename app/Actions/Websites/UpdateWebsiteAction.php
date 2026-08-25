<?php

namespace App\Actions\Websites;

use App\Models\Project;
use App\Services\Websites\WebsiteDomainNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateWebsiteAction
{
    public function __construct(private readonly WebsiteDomainNormalizer $domainNormalizer) {}

    /**
     * @param  array{name: string, url: string, timezone: string}  $website
     */
    public function handle(Project $project, array $website): Project
    {
        return DB::transaction(function () use ($project, $website): Project {
            $domain = $this->domainNormalizer->normalize($website['url']);
            $duplicate = Project::query()
                ->whereBelongsTo($project->user, 'user')
                ->where('is_active', true)
                ->whereKeyNot($project->getKey())
                ->whereRelation('domains', 'domain', $domain)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'url' => 'This website is already connected to your account.',
                ]);
            }

            $project->update([
                'name' => $website['name'],
                'timezone' => $website['timezone'],
            ]);

            $project->domains()->delete();
            $project->domains()->create([
                'domain' => $domain,
                'is_verified' => false,
            ]);

            return $project->load('domains');
        });
    }
}
