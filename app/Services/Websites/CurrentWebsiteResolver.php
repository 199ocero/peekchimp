<?php

namespace App\Services\Websites;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CurrentWebsiteResolver
{
    public function resolve(User $user): ?Project
    {
        $currentProject = $user->currentProject()
            ->where('is_active', true)
            ->whereHas('domains', fn ($query) => $query->where('is_verified', true))
            ->with('domains')
            ->first();

        if ($currentProject !== null) {
            return $currentProject;
        }

        return $this->verifiedProjects($user)->oldest()->first();
    }

    /**
     * @return Collection<int, Project>
     */
    public function activeWebsites(User $user): Collection
    {
        return $user->projects()
            ->where('is_active', true)
            ->with('domains')
            ->oldest()
            ->get();
    }

    /**
     * @return array{current: array{id: int, name: string, domain: string|null}|null, items: array<int, array{id: int, name: string, domain: string|null, status: string}>}
     */
    public function sharedData(User $user): array
    {
        $websites = $this->activeWebsites($user);
        $current = $this->resolve($user);

        return [
            'current' => $current === null ? null : $this->summary($current),
            'items' => $websites->map(fn (Project $project): array => [
                ...$this->summary($project),
                'status' => $this->isVerified($project) ? 'ready' : 'setup_required',
            ])->values()->all(),
        ];
    }

    /**
     * @return array{id: int, name: string, domain: string|null}
     */
    public function summary(Project $project): array
    {
        return [
            'id' => $project->getKey(),
            'name' => $project->name,
            'domain' => $project->domains->first()?->domain,
        ];
    }

    /** @return HasMany<Project, $this> */
    private function verifiedProjects(User $user): HasMany
    {
        return $user->projects()
            ->where('is_active', true)
            ->whereHas('domains', fn ($query) => $query->where('is_verified', true))
            ->with('domains');
    }

    private function isVerified(Project $project): bool
    {
        return $project->domains->contains(fn ($domain): bool => $domain->is_verified);
    }
}
