<?php

namespace App\Services\Mcp;

use App\Models\Project;
use App\Services\Websites\WebsiteSnapshotService;

class GrowthContextService
{
    public function __construct(private readonly WebsiteSnapshotService $snapshots) {}

    /** @return array<string, mixed> */
    public function get(Project $project): array
    {
        $context = $project->growthContext();
        $missing = collect($context)
            ->filter(fn (mixed $value): bool => $value === '' || $value === [])
            ->keys()
            ->values()
            ->all();
        $homepage = $this->snapshots->latestForPath($project, '/');
        $heading = $homepage === null ? null : collect($homepage->headings)->firstWhere('level', 1);

        return [
            'status' => $missing === [] ? 'ok' : 'incomplete_context',
            'website' => [
                'id' => (int) $project->getKey(),
                'name' => (string) $project->name,
                'timezone' => (string) $project->timezone,
                'domains' => $project->domains()->where('is_verified', true)->pluck('domain')->values()->all(),
            ],
            'context' => $context,
            'missingFields' => $missing,
            'configuredGoals' => $project->goals()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn ($goal): array => [
                    'id' => (int) $goal->getKey(),
                    'name' => (string) $goal->name,
                    'type' => (string) $goal->type,
                    'path' => $goal->path,
                    'eventName' => $goal->event_name,
                ])->values()->all(),
            'observedHomepage' => $homepage === null ? null : [
                'title' => $homepage->title,
                'description' => $homepage->meta_description,
                'primaryHeading' => is_array($heading) ? $heading['text'] : null,
                'ctaCandidates' => $homepage->cta_candidates ?? [],
                'evidenceRef' => 'snapshot:/@'.$homepage->crawled_at->toIso8601String(),
            ],
            'freshness' => $this->snapshots->freshness($project),
        ];
    }
}
