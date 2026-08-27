<?php

namespace App\Http\Controllers;

use App\Queries\Analytics\DashboardQuery;
use App\Services\Analytics\AiInsightGenerationCoordinator;
use App\Services\Websites\CurrentWebsiteResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardQuery $dashboardQuery,
        private readonly AiInsightGenerationCoordinator $aiInsights,
        private readonly CurrentWebsiteResolver $websiteResolver,
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $project = $this->websiteResolver->resolve($user);

        if ($project === null) {
            return redirect()->route('onboarding.show');
        }

        $filters = $request->only([
            'range', 'from', 'to', 'country', 'device', 'browser', 'referrer', 'page', 'utm_campaign', 'refresh',
        ]);
        $analytics = $this->dashboardQuery->run($project, $filters);

        return Inertia::render('Dashboard', [
            'project' => [
                'id' => $project->getKey(),
                'name' => $project->name,
                'siteKey' => $project->site_key,
                'timezone' => $project->timezone,
                'domains' => $project->domains->pluck('domain')->values(),
            ],
            'analytics' => $analytics,
            'aiInsightRun' => fn (): ?array => $this->aiInsights->status(
                $project,
                $analytics['actionableInsights'] ?? [],
            ),
            'filters' => $filters,
        ]);
    }
}
