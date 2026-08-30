<?php

namespace App\Http\Controllers;

use App\Jobs\RunAiVisibilityScan;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AiVisibilityController extends Controller
{
    public function show(Project $project): Response
    {
        Gate::authorize('view', $project);
        $scan = $project->aiVisibilityScans()->latest()->first();

        return Inertia::render('AiVisibility', [
            'project' => ['id' => $project->getKey(), 'name' => $project->name],
            'scan' => $scan === null ? null : [
                'status' => $scan->status,
                'score' => $scan->score,
                'findings' => $scan->findings,
                'error' => $scan->error,
                'startedAt' => $scan->started_at?->toIso8601String(),
                'completedAt' => $scan->completed_at?->toIso8601String(),
            ],
        ]);
    }

    public function scan(Project $project): RedirectResponse
    {
        Gate::authorize('manage', $project);
        if ($project->aiVisibilityScans()->whereIn('status', ['queued', 'running'])->exists()) {
            return back()->with('status', 'A website scan is already in progress.');
        }

        $scan = $project->aiVisibilityScans()->create(['status' => 'queued']);
        RunAiVisibilityScan::dispatch($project, $scan);

        return back()->with('status', 'Website content and technical scan queued.');
    }
}
