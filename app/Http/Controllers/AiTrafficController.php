<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Queries\Analytics\DashboardQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AiTrafficController extends Controller
{
    public function __invoke(Request $request, Project $project, DashboardQuery $dashboard): Response
    {
        Gate::authorize('view', $project);
        $analytics = $dashboard->run($project, $request->only(['range', 'from', 'to']), false);

        return Inertia::render('AiTraffic', [
            'project' => [
                'id' => $project->getKey(),
                'name' => $project->name,
                'timezone' => $project->timezone,
            ],
            'range' => $analytics['range'],
            'traffic' => $analytics['aiTraffic'],
        ]);
    }
}
