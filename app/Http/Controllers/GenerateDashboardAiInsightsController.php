<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateInsightRecommendations;
use App\Models\AiInsightRun;
use App\Queries\Analytics\DashboardQuery;
use App\Services\Analytics\AiInsightContextBuilder;
use App\Services\Websites\CurrentWebsiteResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class GenerateDashboardAiInsightsController extends Controller
{
    public function __construct(
        private readonly DashboardQuery $dashboardQuery,
        private readonly AiInsightContextBuilder $contextBuilder,
        private readonly CurrentWebsiteResolver $websiteResolver,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return to_route('login');
        }

        Gate::authorize('manageMembers', $user);

        $project = $this->websiteResolver->resolve($user);

        abort_if($project === null, 404);

        $filters = $request->only([
            'range', 'from', 'to', 'country', 'device', 'browser', 'referrer', 'page', 'utm_campaign',
        ]);
        $analytics = $this->dashboardQuery->run($project, $filters);
        $candidates = $analytics['actionableInsights'] ?? [];
        $periodStart = data_get($candidates, '0.period_start');
        $periodEnd = data_get($candidates, '0.period_end');

        if ($candidates === [] || ! is_string($periodStart) || ! is_string($periodEnd)) {
            Inertia::flash('aiInsightGeneration', [
                'queued' => false,
                'message' => 'No meaningful analytics changes are ready for AI yet.',
            ]);
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => 'No meaningful analytics changes are ready for AI yet.',
            ]);

            return back();
        }

        $context = $this->contextBuilder->build($project, $candidates, $periodStart, $periodEnd);
        $hasCompletedRun = AiInsightRun::query()
            ->where('project_id', $project->getKey())
            ->where('context_hash', $this->contextBuilder->hash($context))
            ->where('status', 'completed')
            ->exists();

        if ($hasCompletedRun) {
            Inertia::flash('aiInsightGeneration', [
                'queued' => false,
                'message' => 'AI insights are already up to date for this data.',
            ]);
            Inertia::flash('toast', [
                'type' => 'info',
                'message' => 'AI insights are already up to date. No new AI request was made.',
            ]);

            return back();
        }

        GenerateInsightRecommendations::dispatch(
            $project,
            $candidates,
            $periodStart,
            $periodEnd,
            true,
        );

        Inertia::flash('aiInsightGeneration', [
            'queued' => true,
            'message' => 'AI insight generation queued.',
        ]);
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'AI insight generation queued. The dashboard will update when it is ready.',
        ]);

        return back();
    }
}
