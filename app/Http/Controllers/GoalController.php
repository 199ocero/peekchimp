<?php

namespace App\Http\Controllers;

use App\Actions\Goals\CreateGoalAction;
use App\Actions\Goals\UpdateGoalAction;
use App\Http\Requests\StoreGoalRequest;
use App\Http\Requests\UpdateGoalRequest;
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

    public function store(StoreGoalRequest $request, Project $project, CreateGoalAction $createGoal): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $createGoal->handle($project, $request->validated());

        return to_route('websites.goals.index', $project);
    }

    public function update(UpdateGoalRequest $request, Project $project, Goal $goal, UpdateGoalAction $updateGoal): RedirectResponse
    {
        Gate::authorize('manage', $project);
        abort_unless($goal->project_id === $project->getKey(), 404);
        $updateGoal->handle($goal, $request->validated());

        return to_route('websites.goals.index', $project);
    }

    public function destroy(Project $project, Goal $goal): RedirectResponse
    {
        Gate::authorize('manage', $project);
        abort_unless($goal->project_id === $project->getKey(), 404);
        $goal->delete();

        return to_route('websites.goals.index', $project);
    }
}
