<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFunnelRequest;
use App\Http\Requests\UpdateFunnelRequest;
use App\Models\Funnel;
use App\Models\FunnelStep;
use App\Models\Project;
use App\Services\Analytics\FunnelAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class FunnelController extends Controller
{
    public function index(Project $project, FunnelAnalyticsService $analytics): Response
    {
        Gate::authorize('manage', $project);
        $to = CarbonImmutable::now($project->timezone)->endOfDay();
        $from = $to->subDays(6)->startOfDay();

        return Inertia::render('websites/Funnels', [
            'website' => ['id' => $project->getKey(), 'name' => $project->name],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'funnels' => $project->funnels()->with('steps')->latest()->get()->map(fn (Funnel $funnel): array => [
                'id' => $funnel->getKey(),
                'name' => $funnel->name,
                'isActive' => $funnel->is_active,
                'steps' => $funnel->steps->map(fn (FunnelStep $step): array => [
                    'position' => $step->position,
                    'name' => $step->name,
                    'type' => $step->type,
                    'eventName' => $step->event_name,
                    'path' => $step->path,
                    'pathOperator' => $step->path_operator,
                ])->all(),
                'analytics' => $analytics->summary($funnel, $from, $to),
            ])->values()->all(),
        ]);
    }

    public function store(StoreFunnelRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('manage', $project);
        DB::transaction(function () use ($request, $project): void {
            $funnel = $project->funnels()->create($request->safe()->only(['name', 'is_active']));
            $this->replaceSteps($funnel, $request->validated('steps'));
        });

        return to_route('websites.funnels.index', $project);
    }

    public function update(UpdateFunnelRequest $request, Project $project, Funnel $funnel): RedirectResponse
    {
        Gate::authorize('manage', $project);
        abort_unless($funnel->project_id === $project->getKey(), 404);
        DB::transaction(function () use ($request, $funnel): void {
            $funnel->update($request->safe()->only(['name', 'is_active']));
            if ($request->has('steps')) {
                $this->replaceSteps($funnel, $request->validated('steps'));
            }
        });

        return to_route('websites.funnels.index', $project);
    }

    public function destroy(Project $project, Funnel $funnel): RedirectResponse
    {
        Gate::authorize('manage', $project);
        abort_unless($funnel->project_id === $project->getKey(), 404);
        $funnel->delete();

        return to_route('websites.funnels.index', $project);
    }

    /** @param array<int, array<string, mixed>>|null $steps */
    private function replaceSteps(Funnel $funnel, ?array $steps): void
    {
        $steps = array_values($steps ?? []);
        abort_if(count($steps) < 2 || count($steps) > 5, 422, 'Funnels need between two and five steps.');
        $funnel->steps()->delete();
        foreach ($steps as $position => $step) {
            $type = (string) ($step['type'] ?? 'event');
            $funnel->steps()->create([
                'position' => $position + 1,
                'name' => $step['name'],
                'type' => $type,
                'event_name' => $type === 'event' ? ($step['event_name'] ?? null) : null,
                'path' => $type === 'url' ? ($step['path'] ?? null) : null,
                'path_operator' => $type === 'url' ? ($step['path_operator'] ?? 'exact') : 'exact',
            ]);
        }
    }
}
