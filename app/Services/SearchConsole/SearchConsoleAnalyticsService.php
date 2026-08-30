<?php

namespace App\Services\SearchConsole;

use App\Models\Project;
use App\Models\SearchConsoleConnection;
use App\Models\SearchConsoleMetric;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SearchConsoleAnalyticsService
{
    public function __construct(private readonly OrganicLandingPageAnalyticsService $organicLandingPages) {}

    /**
     * @return array<string, mixed>
     */
    public function report(Project $project, string $requestedFrom, string $requestedTo): array
    {
        $connection = $project->searchConsoleConnection()->first();

        if ($connection === null) {
            return ['status' => 'not_connected'];
        }

        $base = [
            'status' => $connection->status,
            'connection' => $this->connectionSummary($connection),
        ];

        if ($connection->data_through === null) {
            return [...$base, 'metrics' => null, 'timeseries' => [], 'pages' => [], 'queries' => []];
        }

        $from = CarbonImmutable::parse($requestedFrom, 'UTC')->startOfDay();
        $dataThrough = CarbonImmutable::parse($connection->data_through->toDateString(), 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($requestedTo, 'UTC')->startOfDay()->min($dataThrough);

        if ($from->greaterThan($to)) {
            return [...$base, 'status' => 'no_data', 'metrics' => null, 'timeseries' => [], 'pages' => [], 'queries' => []];
        }

        $days = $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);
        $propertyRows = SearchConsoleMetric::query()
            ->where('project_id', $project->getKey())
            ->where('dimension_type', 'property')
            ->whereDate('report_date', '>=', $previousFrom->toDateString())
            ->whereDate('report_date', '<=', $to->toDateString())
            ->orderBy('report_date')
            ->get();
        $currentRows = $propertyRows->filter(fn (SearchConsoleMetric $metric): bool => $metric->report_date->toDateString() >= $from->toDateString()
            && $metric->report_date->toDateString() <= $to->toDateString());
        $previousRows = $propertyRows->filter(fn (SearchConsoleMetric $metric): bool => $metric->report_date->toDateString() >= $previousFrom->toDateString()
            && $metric->report_date->toDateString() <= $previousTo->toDateString());

        if ($currentRows->isEmpty()) {
            return [...$base, 'status' => 'no_data', 'metrics' => null, 'timeseries' => [], 'pages' => [], 'queries' => []];
        }

        $current = $this->summary($currentRows);
        $previous = $this->summary($previousRows);

        $metrics = [
            'clicks' => $this->comparison($current['clicks'], $previous['clicks']),
            'impressions' => $this->comparison($current['impressions'], $previous['impressions']),
            'ctr' => $this->comparison($current['ctr'], $previous['ctr']),
            'position' => $this->comparison($current['position'], $previous['position'], true),
        ];
        $allPages = $this->breakdown($project, 'page', $from, $to, 100);
        $pages = array_slice($allPages, 0, 10);
        $queries = $this->breakdown($project, 'query', $from, $to);
        $queryPages = $this->queryPageBreakdown($project, $from, $to);
        $organic = $this->organicLandingPages->report($project, $from, $to, $current, $allPages, $queryPages);

        return [
            ...$base,
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'previousFrom' => $previousFrom->toDateString(),
                'previousTo' => $previousTo->toDateString(),
            ],
            'metrics' => $metrics,
            'timeseries' => $currentRows->map(fn (SearchConsoleMetric $metric): array => [
                'date' => $metric->report_date->toDateString(),
                'clicks' => $metric->clicks,
                'impressions' => $metric->impressions,
            ])->values()->all(),
            'pages' => $pages,
            'queries' => $queries,
            'organicFunnel' => $organic['funnel'],
            'landingPages' => $organic['landingPages'],
            'insights' => array_slice([
                ...$organic['insights'],
                ...$this->insights($metrics, $pages),
            ], 0, 3),
        ];
    }

    /** @return array<string, mixed> */
    public function connectionSummary(SearchConsoleConnection $connection): array
    {
        return [
            'propertySiteUrl' => $connection->property_site_url,
            'propertyType' => $connection->property_type,
            'permissionLevel' => $connection->permission_level,
            'status' => $connection->status,
            'dataThrough' => $connection->data_through?->toDateString(),
            'lastSyncedAt' => $connection->last_synced_at?->toIso8601String(),
            'lastError' => $connection->last_error,
        ];
    }

    /**
     * @param  Collection<int, SearchConsoleMetric>  $rows
     * @return array{clicks: int, impressions: int, ctr: float, position: float|null}
     */
    private function summary(Collection $rows): array
    {
        $clicks = (int) $rows->sum('clicks');
        $impressions = (int) $rows->sum('impressions');
        $weightedPosition = (float) $rows->sum(
            fn (SearchConsoleMetric $metric): float => ($metric->position ?? 0) * $metric->impressions,
        );

        return [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
            'position' => $impressions > 0 ? round($weightedPosition / $impressions, 2) : null,
        ];
    }

    /** @return array{current: float|int|null, previous: float|int|null, change: float|null} */
    private function comparison(float|int|null $current, float|int|null $previous, bool $lowerIsBetter = false): array
    {
        $change = $previous === null || (float) $previous === 0.0
            ? null
            : round((((float) $current - (float) $previous) / abs((float) $previous)) * 100, 1);

        return [
            'current' => $current,
            'previous' => $previous,
            'change' => $change,
            'improved' => $change === null ? null : ($lowerIsBetter ? $change < 0 : $change > 0),
        ];
    }

    /** @return array<int, array{label: string, clicks: int, impressions: int, ctr: float, position: float|null}> */
    private function breakdown(Project $project, string $dimension, CarbonImmutable $from, CarbonImmutable $to, int $limit = 10): array
    {
        $rows = SearchConsoleMetric::query()
            ->where('project_id', $project->getKey())
            ->where('dimension_type', $dimension)
            ->whereDate('report_date', '>=', $from->toDateString())
            ->whereDate('report_date', '<=', $to->toDateString())
            ->get();

        return $rows
            ->groupBy(fn (SearchConsoleMetric $metric): string => $dimension === 'page'
                ? ($metric->normalized_path ?: $metric->dimension_value)
                : $metric->dimension_value)
            ->map(function (Collection $group, string $label): array {
                $summary = $this->summary($group);

                return ['label' => $label, ...$summary];
            })
            ->sort(function (array $first, array $second): int {
                return [$second['clicks'], $second['impressions']] <=> [$first['clicks'], $first['impressions']];
            })
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return array<int, array{path: string, query: string, clicks: int, impressions: int, ctr: float, position: float|null}> */
    private function queryPageBreakdown(Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return SearchConsoleMetric::query()
            ->where('project_id', $project->getKey())
            ->where('dimension_type', 'query_page')
            ->whereDate('report_date', '>=', $from->toDateString())
            ->whereDate('report_date', '<=', $to->toDateString())
            ->get()
            ->groupBy(fn (SearchConsoleMetric $metric): string => ($metric->normalized_path ?: '/')."\0".$metric->dimension_value)
            ->map(function (Collection $group): array {
                $metric = $group->first();
                $summary = $this->summary($group);

                return [
                    'path' => $metric->normalized_path ?: '/',
                    'query' => $metric->dimension_value,
                    ...$summary,
                ];
            })
            ->sortByDesc('clicks')
            ->take(500)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $metrics
     * @param  array<int, array{label: string, clicks: int, impressions: int, ctr: float, position: float|null}>  $pages
     * @return array<int, array{title: string, detail: string, recommendation: string}>
     */
    private function insights(array $metrics, array $pages): array
    {
        $insights = [];
        $impressionChange = $metrics['impressions']['change'];
        $clickChange = $metrics['clicks']['change'];

        if (is_numeric($impressionChange) && is_numeric($clickChange) && $impressionChange >= 15 && $clickChange < $impressionChange / 2) {
            $insights[] = [
                'title' => 'Search visibility is growing faster than clicks',
                'detail' => 'Impressions increased '.abs((float) $impressionChange).'% while clicks changed '.(float) $clickChange.'%.',
                'recommendation' => 'Improve titles and descriptions on high-impression pages to turn more appearances into visits.',
            ];
        }

        $opportunity = collect($pages)->first(
            fn (array $page): bool => $page['impressions'] >= 100 && $page['ctr'] < 3 && ($page['position'] ?? 100) <= 20,
        );

        if (is_array($opportunity)) {
            $insights[] = [
                'title' => $opportunity['label'].' has an organic search opportunity',
                'detail' => number_format($opportunity['impressions']).' impressions at '.number_format($opportunity['ctr'], 1).'% CTR and average position '.number_format((float) $opportunity['position'], 1).'.',
                'recommendation' => 'Align the page title and opening copy with the search intent, then compare CTR after the next full reporting period.',
            ];
        }

        if ($insights === [] && $metrics['clicks']['previous'] !== null) {
            $direction = ((float) ($metrics['clicks']['change'] ?? 0)) >= 0 ? 'up' : 'down';
            $insights[] = [
                'title' => 'Organic clicks are '.$direction.' versus the previous period',
                'detail' => number_format((float) ($metrics['clicks']['current'] ?? 0)).' clicks were recorded in this period.',
                'recommendation' => 'Compare the top pages below to see which URLs contributed most to the change.',
            ];
        }

        return array_slice($insights, 0, 3);
    }
}
