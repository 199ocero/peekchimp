<?php

namespace App\Http\Controllers;

use App\Queries\Analytics\DashboardQuery;
use App\Services\Analytics\AiInsightGenerationCoordinator;
use App\Services\Websites\CurrentWebsiteResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class GenerateDashboardAiInsightsController extends Controller
{
    public function __construct(
        private readonly DashboardQuery $dashboardQuery,
        private readonly AiInsightGenerationCoordinator $aiInsights,
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
        $analytics = $this->dashboardQuery->run($project, $filters, queueAiInsights: false);
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

        $generation = $this->aiInsights->request(
            $project,
            $candidates,
            $periodStart,
            $periodEnd,
            force: true,
        );
        $run = $generation['run'];

        if ($generation['reason'] === 'completed') {
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

        if ($generation['reason'] === 'failed') {
            $message = $run?->error ?: 'Peekchimp could not queue AI generation. Please try again.';
            Inertia::flash('aiInsightGeneration', [
                'queued' => false,
                'runId' => $run?->getKey(),
                'status' => 'failed',
                'message' => $message,
            ]);
            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back();
        }

        $alreadyInProgress = $generation['reason'] === 'in_progress';
        $message = $alreadyInProgress
            ? 'AI insight generation is already in progress.'
            : 'AI insight generation queued.';

        Inertia::flash('aiInsightGeneration', [
            'queued' => true,
            'runId' => $run?->getKey(),
            'status' => $run->status,
            'message' => $message,
        ]);
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $message.' The dashboard will update when it is ready.',
        ]);

        return back();
    }
}
