<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Queries\Analytics\DashboardQuery;
use App\Services\Analytics\PublicDashboardDataBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicDashboardController extends Controller
{
    public function __invoke(
        Request $request,
        string $token,
        DashboardQuery $dashboardQuery,
        PublicDashboardDataBuilder $publicDashboardData,
    ): Response {
        $project = Project::query()
            ->where('public_share_token_hash', hash('sha256', $token))
            ->whereNotNull('public_share_enabled_at')
            ->where('is_active', true)
            ->whereHas('domains', fn ($query) => $query->where('is_verified', true))
            ->with('domains')
            ->firstOrFail();
        $sections = $project->publicDashboardSections();
        $range = $request->string('range')->toString();
        $range = in_array($range, ['today', 'yesterday', '7d', '30d', 'month'], true) ? $range : '7d';
        $analytics = $publicDashboardData->build(
            $dashboardQuery->run($project, ['range' => $range], false),
            $sections,
        );

        return Inertia::render('public/Dashboard', [
            'shareToken' => $token,
            'project' => [
                'name' => $project->name,
                'domain' => $project->domains->first()?->domain,
                'timezone' => $project->timezone,
            ],
            'analytics' => $analytics,
            'visibleSections' => $sections,
        ]);
    }
}
