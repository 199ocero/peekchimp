<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreImportantActionRequest;
use App\Http\Requests\UpdateImportantActionRequest;
use App\Models\ImportantAction;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ImportantActionController extends Controller
{
    public function index(Project $project): Response
    {
        Gate::authorize('manage', $project);

        return Inertia::render('websites/Actions', [
            'website' => ['id' => $project->getKey(), 'name' => $project->name],
            'actions' => $project->importantActions()->latest()->get([
                'id', 'name', 'event_name', 'page_path', 'is_active',
            ]),
        ]);
    }

    public function store(StoreImportantActionRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('manage', $project);
        $project->importantActions()->create($request->validated());

        return to_route('websites.actions.index', $project);
    }

    public function update(UpdateImportantActionRequest $request, Project $project, ImportantAction $action): RedirectResponse
    {
        Gate::authorize('manage', $project);
        abort_unless($action->project_id === $project->getKey(), 404);
        $action->update($request->validated());

        return to_route('websites.actions.index', $project);
    }

    public function destroy(Project $project, ImportantAction $action): RedirectResponse
    {
        Gate::authorize('manage', $project);
        abort_unless($action->project_id === $project->getKey(), 404);
        $action->delete();

        return to_route('websites.actions.index', $project);
    }
}
