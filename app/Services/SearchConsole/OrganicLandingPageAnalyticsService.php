<?php

namespace App\Services\SearchConsole;

use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrganicLandingPageAnalyticsService
{
    /**
     * @param  array{clicks: int, impressions: int, ctr: float, position: float|null}  $searchTotals
     * @param  array<int, array{label: string, clicks: int, impressions: int, ctr: float, position: float|null}>  $searchPages
     * @param  array<int, array{path: string, query: string, clicks: int, impressions: int, ctr: float, position: float|null}>  $queryPages
     * @return array{funnel: array<string, int|float|null>, landingPages: array<int, array<string, mixed>>, insights: array<int, array{title: string, detail: string, recommendation: string}>}
     */
    public function report(
        Project $project,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $searchTotals,
        array $searchPages,
        array $queryPages,
    ): array {
        $fromUtc = CarbonImmutable::parse($from->toDateString(), $project->timezone)->startOfDay()->utc();
        $toUtc = CarbonImmutable::parse($to->toDateString(), $project->timezone)->endOfDay()->utc();
        $sessionRows = $this->sessionRows($project, $fromUtc, $toUtc)->keyBy('path');
        $conversionRows = $this->conversionRows($project, $fromUtc, $toUtc)->keyBy('path');
        $queriesByPage = collect($queryPages)
            ->groupBy('path')
            ->map(fn (Collection $rows): array => $rows->sortByDesc('clicks')->take(5)->values()->all());
        $searchPagesByPath = collect($searchPages)->keyBy('label');
        $paths = $searchPagesByPath->keys()->merge($sessionRows->keys())->unique();
        $landingPages = $paths->map(function (string $path) use ($searchPagesByPath, $sessionRows, $conversionRows, $queriesByPage): array {
            $search = $searchPagesByPath->get($path, [
                'clicks' => 0,
                'impressions' => 0,
                'ctr' => 0.0,
                'position' => null,
            ]);
            $session = $sessionRows->get($path);
            $visits = (int) ($session->visits ?? 0);
            $bounces = (int) ($session->bounces ?? 0);
            $conversions = (int) ($conversionRows->get($path)->conversions ?? 0);

            return [
                'path' => $path,
                'impressions' => (int) $search['impressions'],
                'clicks' => (int) $search['clicks'],
                'ctr' => (float) $search['ctr'],
                'position' => $search['position'],
                'visits' => $visits,
                'visitors' => (int) ($session->visitors ?? 0),
                'engagedVisits' => max(0, $visits - $bounces),
                'bounceRate' => $visits > 0 ? round(($bounces / $visits) * 100, 1) : 0.0,
                'averageDuration' => (int) round((float) ($session->average_duration ?? 0)),
                'conversions' => $conversions,
                'conversionRate' => $visits > 0 ? round(($conversions / $visits) * 100, 2) : 0.0,
                'trackedVisitRate' => (int) $search['clicks'] > 0
                    ? round(($visits / (int) $search['clicks']) * 100, 1)
                    : null,
                'topQueries' => $queriesByPage->get($path, []),
            ];
        })->sort(function (array $first, array $second): int {
            return [$second['impressions'], $second['clicks'], $second['visits']]
                <=> [$first['impressions'], $first['clicks'], $first['visits']];
        })->take(20)->values()->all();

        $visits = (int) $sessionRows->sum('visits');
        $bounces = (int) $sessionRows->sum('bounces');
        $conversions = (int) $conversionRows->sum('conversions');
        $engagedVisits = max(0, $visits - $bounces);
        $funnel = [
            'impressions' => $searchTotals['impressions'],
            'clicks' => $searchTotals['clicks'],
            'visits' => $visits,
            'engagedVisits' => $engagedVisits,
            'conversions' => $conversions,
            'searchCtr' => $searchTotals['ctr'],
            'trackedVisitRate' => $searchTotals['clicks'] > 0
                ? round(($visits / $searchTotals['clicks']) * 100, 1)
                : null,
            'engagementRate' => $visits > 0 ? round(($engagedVisits / $visits) * 100, 1) : null,
            'conversionRate' => $visits > 0 ? round(($conversions / $visits) * 100, 2) : null,
        ];

        return [
            'funnel' => $funnel,
            'landingPages' => $landingPages,
            'insights' => $this->insights($funnel, $landingPages),
        ];
    }

    /** @return Collection<int, \stdClass> */
    private function sessionRows(Project $project, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->googleOrganicSessions($project, $from, $to)
            ->selectRaw('sessions.entry_path AS path')
            ->selectRaw('COUNT(*) AS visits')
            ->selectRaw('COUNT(DISTINCT sessions.visitor_id) AS visitors')
            ->selectRaw('SUM(CASE WHEN sessions.is_bounce THEN 1 ELSE 0 END) AS bounces')
            ->selectRaw('AVG(sessions.duration_seconds) AS average_duration')
            ->groupBy('sessions.entry_path')
            ->get();
    }

    /** @return Collection<int, \stdClass> */
    private function conversionRows(Project $project, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $query = DB::table('goal_conversions as conversions')
            ->join('analytics_sessions as sessions', function ($join): void {
                $join->on('sessions.project_id', '=', 'conversions.project_id')
                    ->on('sessions.session_id', '=', 'conversions.session_id');
            })
            ->where('conversions.project_id', $project->getKey())
            ->whereBetween('conversions.occurred_at', [$from, $to])
            ->whereBetween('sessions.started_at', [$from, $to])
            ->whereNotNull('sessions.entry_path');
        $this->applyGoogleOrganicFilter($query);

        return $query
            ->selectRaw('sessions.entry_path AS path')
            ->selectRaw('COUNT(conversions.id) AS conversions')
            ->groupBy('sessions.entry_path')
            ->get();
    }

    private function googleOrganicSessions(Project $project, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $query = DB::table('analytics_sessions as sessions')
            ->where('sessions.project_id', $project->getKey())
            ->whereBetween('sessions.started_at', [$from, $to])
            ->whereNotNull('sessions.entry_path');
        $this->applyGoogleOrganicFilter($query);

        return $query;
    }

    private function applyGoogleOrganicFilter(Builder $query): void
    {
        $query
            ->whereRaw("LOWER(COALESCE(sessions.utm_medium, '')) NOT IN ('cpc', 'ppc', 'paidsearch', 'paid-search')")
            ->where(function (Builder $query): void {
                $query
                    ->whereRaw("LOWER(COALESCE(sessions.utm_source, '')) = 'google'")
                    ->orWhereRaw("LOWER(COALESCE(sessions.utm_source, '')) LIKE 'google.%'")
                    ->orWhereRaw("LOWER(COALESCE(sessions.referrer_host, '')) = 'google.com'")
                    ->orWhereRaw("LOWER(COALESCE(sessions.referrer_host, '')) LIKE '%.google.com'")
                    ->orWhereRaw("LOWER(COALESCE(sessions.referrer_host, '')) LIKE 'google.%'")
                    ->orWhereRaw("LOWER(COALESCE(sessions.referrer_host, '')) = 'googleusercontent.com'")
                    ->orWhereRaw("LOWER(COALESCE(sessions.referrer_host, '')) LIKE '%.googleusercontent.com'");
            });
    }

    /**
     * @param  array<string, int|float|null>  $funnel
     * @param  array<int, array<string, mixed>>  $landingPages
     * @return array<int, array{title: string, detail: string, recommendation: string}>
     */
    private function insights(array $funnel, array $landingPages): array
    {
        $insights = [];
        $rankingOpportunity = collect($landingPages)->first(fn (array $page): bool => $page['conversions'] >= 2
            && $page['position'] !== null
            && $page['position'] >= 4
            && $page['position'] <= 15
            && $page['conversionRate'] >= max(2, (float) ($funnel['conversionRate'] ?? 0)));

        if (is_array($rankingOpportunity)) {
            $insights[] = [
                'title' => $rankingOpportunity['path'].' is a high-value ranking opportunity',
                'detail' => $rankingOpportunity['conversions'].' conversions at a '.$rankingOpportunity['conversionRate'].'% conversion rate from average position '.number_format((float) $rankingOpportunity['position'], 1).'.',
                'recommendation' => 'Prioritize internal links and content improvements for this page because better rankings are tied to demonstrated business value.',
            ];
        }

        $intentMismatch = collect($landingPages)->first(fn (array $page): bool => $page['clicks'] >= 10
            && $page['visits'] >= 10
            && $page['bounceRate'] >= 65);

        if (is_array($intentMismatch)) {
            $insights[] = [
                'title' => $intentMismatch['path'].' may not match search intent',
                'detail' => $intentMismatch['clicks'].' Google clicks led to a '.$intentMismatch['bounceRate'].'% bounce rate.',
                'recommendation' => 'Compare its top queries with the page heading and opening content, then make the promised answer immediately visible.',
            ];
        }

        $conversionGap = collect($landingPages)->first(fn (array $page): bool => $page['visits'] >= 10 && $page['conversions'] === 0);

        if (is_array($conversionGap)) {
            $insights[] = [
                'title' => $conversionGap['path'].' attracts search visits but no conversions',
                'detail' => $conversionGap['visits'].' tracked organic visits produced no configured goal conversions.',
                'recommendation' => 'Add or clarify the next action on this landing page and verify that its intended outcome is configured as a Peekchimp goal.',
            ];
        }

        return array_slice($insights, 0, 3);
    }
}
