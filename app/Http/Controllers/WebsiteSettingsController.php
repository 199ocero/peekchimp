<?php

namespace App\Http\Controllers;

use App\Actions\Websites\UpdateWebsiteSettingsAction;
use App\Http\Requests\UpdateWebsiteSettingsRequest;
use App\Models\Project;
use App\Services\SearchConsole\SearchConsoleAnalyticsService;
use App\Services\Websites\WebsiteSnapshotService;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class WebsiteSettingsController extends Controller
{
    public function edit(
        Request $request,
        Project $project,
        SearchConsoleAnalyticsService $searchConsoleAnalytics,
        WebsiteSnapshotService $snapshots,
    ): Response {
        Gate::authorize('manage', $project);
        $project->loadMissing(['domains', 'searchConsoleConnection', 'aiVisibilityScans']);
        $connection = $project->searchConsoleConnection;
        $latestCrawl = $project->aiVisibilityScans->sortByDesc('created_at')->first();
        $crawlFreshness = $snapshots->freshness($project);

        return Inertia::render('websites/Settings', [
            'website' => [
                'id' => $project->getKey(),
                'name' => $project->name,
                'timezone' => $project->timezone,
                'domain' => $project->domains->first()?->domain,
                'siteKey' => $project->site_key,
                'isVerified' => $project->domains->contains(fn ($domain): bool => $domain->is_verified),
                'growthContext' => $project->growthContext(),
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
            'trackerUrl' => asset('a.js'),
            'websiteCrawl' => [
                'status' => data_get($latestCrawl, 'status', 'not_started'),
                'lastCrawledAt' => $crawlFreshness['last_crawled_at'],
                'pageCount' => $crawlFreshness['page_count'],
                'error' => $latestCrawl?->error,
            ],
            'publicSharing' => [
                'enabled' => $project->hasPublicSharingEnabled(),
                'url' => $project->public_share_token === null
                    ? null
                    : route('shared.dashboard.show', ['token' => $project->public_share_token]),
                'sections' => $project->publicDashboardSections(),
            ],
            'searchConsole' => [
                'connection' => $connection === null ? null : $searchConsoleAnalytics->connectionSummary($connection),
                'candidates' => $this->pendingSearchConsoleProperties($request, $project),
                'canManage' => $request->user()?->can('manageIntegrations', $project) === true,
            ],
        ]);
    }

    public function update(
        UpdateWebsiteSettingsRequest $request,
        Project $project,
        UpdateWebsiteSettingsAction $updateWebsiteSettings,
    ): RedirectResponse {
        Gate::authorize('manage', $project);
        $updateWebsiteSettings->handle($project, $request->website());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Website settings saved.']);

        return to_route('websites.settings.edit', $project);
    }

    /** @return array<int, array<string, string>> */
    private function pendingSearchConsoleProperties(Request $request, Project $project): array
    {
        $pending = $request->session()->get('search_console.pending');

        if (! is_array($pending)
            || (int) ($pending['project_id'] ?? 0) !== $project->getKey()
            || (int) ($pending['expires_at'] ?? 0) < now()->timestamp
            || ! is_string($pending['payload'] ?? null)) {
            return [];
        }

        try {
            $payload = json_decode(Crypt::decryptString($pending['payload']), true, flags: JSON_THROW_ON_ERROR);
            $properties = is_array($payload) ? ($payload['properties'] ?? []) : [];

            if (! is_array($properties)) {
                return [];
            }

            $candidates = [];

            foreach ($properties as $property) {
                if (! is_array($property)) {
                    continue;
                }

                $candidates[] = [
                    'siteUrl' => (string) ($property['siteUrl'] ?? ''),
                    'propertyType' => (string) ($property['propertyType'] ?? ''),
                    'permissionLevel' => (string) ($property['permissionLevel'] ?? ''),
                ];
            }

            return $candidates;
        } catch (Throwable) {
            return [];
        }
    }
}
