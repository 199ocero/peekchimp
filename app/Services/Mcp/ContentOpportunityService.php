<?php

namespace App\Services\Mcp;

use App\Models\Project;
use App\Models\SearchConsoleMetric;
use App\Services\SearchConsole\SearchConsoleAnalyticsService;
use App\Services\Websites\WebsiteSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ContentOpportunityService
{
    public function __construct(
        private readonly SearchConsoleAnalyticsService $searchConsole,
        private readonly WebsiteSnapshotService $snapshots,
    ) {}

    /** @return array<string, mixed> */
    public function find(Project $project, CarbonImmutable $from, CarbonImmutable $to, int $limit = 20): array
    {
        $limit = min(50, max(1, $limit));
        $report = $this->searchConsole->report($project, $from->toDateString(), $to->toDateString());
        $landingPages = $report['landingPages'] ?? [];
        if (! is_array($landingPages)) {
            $landingPages = [];
        }

        $queryPages = $this->queryPages($project, $from, $to);
        $highImpression = collect($queryPages)
            ->filter(fn (array $item): bool => $item['impressions'] >= 100
                && $item['ctr'] < 3
                && $item['position'] !== null
                && $item['position'] <= 20)
            ->map(fn (array $item): array => $this->opportunity(
                'high_impression_query',
                $item['path'],
                $item['query'],
                min(100, 45 + (int) min(35, $item['impressions'] / 20) + (int) max(0, 20 - $item['position'])),
                'High search visibility is producing a low click-through rate.',
                ['gsc:query:'.$item['query'].':page:'.$item['path']],
                $item,
            ));
        $cannibalization = collect($queryPages)
            ->groupBy('query')
            ->filter(fn (Collection $items): bool => $items->where('impressions', '>=', 10)->pluck('path')->unique()->count() >= 2)
            ->map(function (Collection $items, string $query): array {
                $pages = $items->where('impressions', '>=', 10)->sortByDesc('impressions')->values();

                return $this->opportunity(
                    'cannibalization',
                    null,
                    $query,
                    min(100, 55 + $pages->count() * 5),
                    'The same query receives meaningful impressions across multiple landing pages.',
                    $pages->map(fn (array $item): string => 'gsc:query:'.$query.':page:'.$item['path'])->all(),
                    ['pages' => $pages->all()],
                );
            })->values();
        $weakPages = collect($landingPages)
            ->filter(fn (array $page): bool => ($page['visits'] >= 10 && $page['bounceRate'] >= 65)
                || ($page['visits'] >= 10 && $page['conversions'] === 0))
            ->map(fn (array $page): array => $this->opportunity(
                'weak_landing_page',
                $page['path'],
                data_get($page, 'topQueries.0.query'),
                min(100, 50 + (int) min(25, $page['visits']) + ($page['conversions'] === 0 ? 15 : 0)),
                $page['conversions'] === 0
                    ? 'The landing page receives visits but produced no configured conversions.'
                    : 'The landing page has a high bounce rate after organic search visits.',
                ['analytics:organic-page:'.$page['path'], 'gsc:page:'.$page['path']],
                $page,
            ));
        $decay = $this->decay($project, $from, $to);
        $missingTopics = $this->missingTopics($project, $highImpression);
        $ranked = collect()
            ->merge($highImpression)
            ->merge($cannibalization)
            ->merge($weakPages)
            ->merge($decay)
            ->merge($missingTopics)
            ->sortByDesc('priorityScore')
            ->take($limit)
            ->values();

        return [
            'status' => ($report['status'] ?? null) === 'not_connected' ? 'not_connected' : ($ranked->isEmpty() ? 'no_opportunities' : 'ok'),
            'freshness' => $this->snapshots->freshness($project),
            'ranked' => $ranked->all(),
            'highImpressionQueries' => $highImpression->take($limit)->values()->all(),
            'weakLandingPages' => $weakPages->take($limit)->values()->all(),
            'cannibalization' => $cannibalization->take($limit)->values()->all(),
            'contentDecay' => $decay->take($limit)->values()->all(),
            'missingTopicHypotheses' => $missingTopics->take($limit)->values()->all(),
        ];
    }

    /** @return array<int, array{path: string, query: string, clicks: int, impressions: int, ctr: float, position: float|null}> */
    private function queryPages(Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return SearchConsoleMetric::query()
            ->where('project_id', $project->getKey())
            ->where('dimension_type', 'query_page')
            ->whereDate('report_date', '>=', $from->toDateString())
            ->whereDate('report_date', '<=', $to->toDateString())
            ->get()
            ->groupBy(fn ($metric): string => ($metric->normalized_path ?: '/')."\0".$metric->dimension_value)
            ->map(function (Collection $metrics): array {
                $metric = $metrics->first();
                $clicks = (int) $metrics->sum('clicks');
                $impressions = (int) $metrics->sum('impressions');
                $weightedPosition = (float) $metrics->sum(fn ($row): float => (float) ($row->position ?? 0) * $row->impressions);

                return [
                    'path' => (string) ($metric->normalized_path ?: '/'),
                    'query' => (string) $metric->dimension_value,
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
                    'position' => $impressions > 0 ? round($weightedPosition / $impressions, 2) : null,
                ];
            })->sortByDesc('impressions')->take(500)->values()->all();
    }

    /** @return Collection<int, non-empty-array<string, mixed>> */
    private function decay(Project $project, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $days = $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);
        $current = collect($this->queryPages($project, $from, $to))->groupBy('path')
            ->map(fn (Collection $rows): int => (int) $rows->sum('clicks'));
        $previous = collect($this->queryPages($project, $previousFrom, $previousTo))->groupBy('path')
            ->map(fn (Collection $rows): int => (int) $rows->sum('clicks'));

        return $previous->map(function (int $previousClicks, string $path) use ($current): ?array {
            $currentClicks = (int) $current->get($path, 0);
            if ($previousClicks + $currentClicks < 40 || $previousClicks === 0) {
                return null;
            }
            $change = round((($currentClicks - $previousClicks) / $previousClicks) * 100, 1);
            if ($change > -25) {
                return null;
            }

            return $this->opportunity(
                'content_decay',
                $path,
                null,
                min(100, 55 + (int) min(40, abs($change) / 2)),
                'Organic clicks declined materially versus the preceding equivalent period.',
                ['gsc:decay:page:'.$path],
                ['currentClicks' => $currentClicks, 'previousClicks' => $previousClicks, 'change' => $change],
            );
        })->filter()->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $highImpression
     * @return Collection<int, array<string, mixed>>
     */
    private function missingTopics(Project $project, Collection $highImpression): Collection
    {
        $pages = $this->snapshots->latest($project);

        return $highImpression->filter(function (array $opportunity) use ($pages): bool {
            $query = Str::lower((string) ($opportunity['query'] ?? ''));
            $terms = collect(preg_split('/\W+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [])->filter(fn (string $term): bool => Str::length($term) >= 4);
            if ($terms->isEmpty()) {
                return false;
            }

            $bestCoverage = $pages->max(function ($page) use ($terms): float {
                $content = Str::lower(($page->title ?? '').' '.collect($page->headings ?? [])->pluck('text')->implode(' ').' '.($page->main_content ?? ''));

                return $terms->filter(fn (string $term): bool => Str::contains($content, $term))->count() / $terms->count();
            });

            return (float) $bestCoverage < 0.5;
        })->map(fn (array $opportunity): array => $this->opportunity(
            'missing_topic_hypothesis',
            null,
            $opportunity['query'],
            max(1, (int) $opportunity['priorityScore'] - 5),
            'No crawled page strongly covers the significant terms in this high-impression query; treat this as a hypothesis for editorial review.',
            $opportunity['evidence'],
            $opportunity['metrics'],
        ))->values();
    }

    /**
     * @param  array<int, string>  $evidence
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function opportunity(string $type, ?string $path, ?string $query, int $score, string $reason, array $evidence, array $metrics): array
    {
        return [
            'id' => sha1($type.'|'.$path.'|'.$query),
            'type' => $type,
            'path' => $path,
            'query' => $query,
            'priorityScore' => max(1, min(100, $score)),
            'reason' => $reason,
            'evidence' => $evidence,
            'metrics' => $metrics,
        ];
    }
}
