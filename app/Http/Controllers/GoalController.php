<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGoalRequest;
use App\Http\Requests\UpdateGoalRequest;
use App\Jobs\BackfillGoalConversions;
use App\Models\Goal;
use App\Models\Project;
use App\Services\Analytics\GoalAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class GoalController extends Controller
{
    public function index(Project $project, GoalAnalyticsService $analytics): Response
    {
        Gate::authorize('manage', $project);
        $to = CarbonImmutable::now($project->timezone)->endOfDay();
        $from = $to->subDays(6)->startOfDay();

        return Inertia::render('websites/Goals', [
            'website' => ['id' => $project->getKey(), 'name' => $project->name],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'goals' => $project->goals()->latest()->get()->map(function (Goal $goal) use ($analytics, $from, $to): array {
                return [
                    'id' => $goal->getKey(),
                    'name' => $goal->name,
                    'type' => $goal->type ?: 'event',
                    'eventName' => $goal->event_name,
                    'path' => $goal->path,
                    'pathOperator' => $goal->path_operator,
                    'propertyMatch' => $goal->property_match,
                    'isActive' => $goal->is_active,
                    'analytics' => $analytics->summary($goal, $from, $to),
                ];
            })->values()->all(),
        ]);
    }

    public function store(StoreGoalRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $data = $this->normalized($request->validated());
        $goal = $project->goals()->create($data);
        BackfillGoalConversions::dispatch($goal);

        return to_route('websites.goals.index', $project);
    }

    public function update(UpdateGoalRequest $request, Project $project, Goal $goal): RedirectResponse
    {
        Gate::authorize('manage', $project);
        abort_unless($goal->project_id === $project->getKey(), 404);
        $goal->conversions()->delete();
        $goal->update($this->normalized($request->validated(), $goal));
        BackfillGoalConversions::dispatch($goal->fresh());

        return to_route('websites.goals.index', $project);
    }

    public function destroy(Project $project, Goal $goal): RedirectResponse
    {
        Gate::authorize('manage', $project);
        abort_unless($goal->project_id === $project->getKey(), 404);
        $goal->delete();

        return to_route('websites.goals.index', $project);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalized(array $data, ?Goal $existing = null): array
    {
        $type = (string) ($data['type'] ?? ($existing === null ? 'event' : $existing->type));
        $properties = is_array($data['property_match'] ?? null) ? array_slice($data['property_match'], 0, 5, true) : null;
        $properties = $properties === null ? null : array_filter($properties, 'is_scalar');

        return [
            ...$data,
            'type' => $type,
            'event_name' => $type === 'event' ? ($data['event_name'] ?? ($existing === null ? null : $existing->event_name)) : null,
            'path' => $type === 'url' ? ($data['path'] ?? ($existing === null ? null : $existing->path)) : null,
            'path_operator' => $type === 'url' ? ($data['path_operator'] ?? ($existing === null ? 'exact' : $existing->path_operator)) : 'exact',
            'property_match' => $properties,
        ];
    }
}
