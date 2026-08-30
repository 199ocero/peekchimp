<?php

namespace App\Services\Mcp;

use App\Models\Project;
use App\Models\SearchConsoleMetric;
use App\Models\WebsitePageSnapshot;
use App\Services\Websites\WebsiteSnapshotService;
use App\Services\Websites\WebsiteUrlNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PageDiagnosticService
{
    public function __construct(
        private readonly WebsiteSnapshotService $snapshots,
        private readonly WebsiteUrlNormalizer $normalizer,
    ) {}

    /** @return array<string, mixed> */
    public function diagnose(Project $project, string $path, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $path = $this->normalizer->normalizePath($path);
        $snapshot = $this->snapshots->latestForPath($project, $path);
        if ($snapshot === null) {
            return [
                'status' => 'snapshot_required',
                'path' => $path,
                'freshness' => $this->snapshots->freshness($project),
                'page' => null,
                'analytics' => $this->analytics($project, $path, $from, $to),
                'search' => $this->search($project, $path, $from, $to),
                'goals' => $this->goals($project, $path),
                'funnelSteps' => $this->funnelSteps($project, $path),
            ];
        }

        return [
            'status' => $snapshot->error === null ? 'ok' : 'partial',
            'path' => $path,
            'freshness' => $this->snapshots->freshness($project),
            'page' => $this->snapshot($snapshot),
            'analytics' => $this->analytics($project, $path, $from, $to),
            'search' => $this->search($project, $path, $from, $to),
            'goals' => $this->goals($project, $path),
            'funnelSteps' => $this->funnelSteps($project, $path),
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(WebsitePageSnapshot $snapshot): array
    {
        return [
            'url' => $snapshot->url,
            'status' => $snapshot->http_status,
            'contentType' => $snapshot->content_type,
            'title' => $snapshot->title,
            'description' => $snapshot->meta_description,
            'canonicalUrl' => $snapshot->canonical_url,
            'robots' => $snapshot->robots_directives ?? [],
            'headings' => $snapshot->headings ?? [],
            'links' => $snapshot->links ?? [],
            'ctaCandidates' => $snapshot->cta_candidates ?? [],
            'structuredData' => $snapshot->structured_data ?? [],
            'mainContent' => Str::limit(
                (string) $snapshot->main_content,
                (int) config('analytics.website_crawl.max_tool_content_characters', 20000),
                '',
            ),
            'wordCount' => $snapshot->word_count,
            'contentHash' => $snapshot->content_hash,
            'responseTimeMs' => $snapshot->response_time_ms,
            'responseBytes' => $snapshot->response_bytes,
            'redirectChain' => $snapshot->redirect_chain ?? [],
            'error' => $snapshot->error,
            'crawledAt' => $snapshot->crawled_at->toIso8601String(),
            'evidenceRef' => 'snapshot:'.$snapshot->normalized_path.'@'.$snapshot->crawled_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function analytics(Project $project, string $path, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $current = $this->analyticsPeriod($project, $path, $from, $to);
        $duration = max(1, $to->getTimestamp() - $from->getTimestamp());
        $previousTo = $from->subSecond();
        $previous = $this->analyticsPeriod($project, $path, $previousTo->subSeconds($duration), $previousTo);

        return [
            ...$current,
            'previous' => $previous,
            'changes' => [
                'pageviews' => $this->change($current['pageviews'], $previous['pageviews']),
                'entryVisits' => $this->change($current['entryVisits'], $previous['entryVisits']),
                'conversions' => $this->change($current['conversions'], $previous['conversions']),
            ],
            'evidenceRef' => 'analytics:page:'.$path.':'.$from->toDateString().':'.$to->toDateString(),
        ];
    }

    /** @return array{pageviews: int, visitors: int, entryVisits: int, bounces: int, bounceRate: float|null, averageDuration: int, conversions: int, conversionRate: float|null} */
    private function analyticsPeriod(Project $project, string $path, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $fromUtc = $from->utc();
        $toUtc = $to->utc();
        $events = $project->events()
            ->where('event_name', 'page_view')
            ->where('path', $path)
            ->whereBetween('occurred_at', [$fromUtc, $toUtc]);
        $sessions = DB::table('analytics_sessions')
            ->where('project_id', $project->getKey())
            ->where('entry_path', $path)
            ->whereBetween('started_at', [$fromUtc, $toUtc]);
        $sessionSummary = (clone $sessions)->selectRaw('COUNT(*) as visits')
            ->selectRaw('SUM(CASE WHEN is_bounce THEN 1 ELSE 0 END) as bounces')
            ->selectRaw('AVG(duration_seconds) as average_duration')
            ->first();
        $entryVisits = (int) ($sessionSummary->visits ?? 0);
        $bounces = (int) ($sessionSummary->bounces ?? 0);
        $conversions = DB::table('goal_conversions as conversions')
            ->join('analytics_sessions as sessions', function ($join): void {
                $join->on('sessions.project_id', 'conversions.project_id')
                    ->on('sessions.session_id', 'conversions.session_id');
            })
            ->where('conversions.project_id', $project->getKey())
            ->where('sessions.entry_path', $path)
            ->whereBetween('conversions.occurred_at', [$fromUtc, $toUtc])
            ->count('conversions.id');

        return [
            'pageviews' => (clone $events)->count(),
            'visitors' => (clone $events)->distinct()->count('visitor_id'),
            'entryVisits' => $entryVisits,
            'bounces' => $bounces,
            'bounceRate' => $entryVisits > 0 ? round(($bounces / $entryVisits) * 100, 1) : null,
            'averageDuration' => (int) round((float) ($sessionSummary->average_duration ?? 0)),
            'conversions' => (int) $conversions,
            'conversionRate' => $entryVisits > 0 ? round(((int) $conversions / $entryVisits) * 100, 2) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function search(Project $project, string $path, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($project->searchConsoleConnection()->doesntExist()) {
            return ['status' => 'not_connected', 'queries' => [], 'evidenceRef' => null];
        }

        $rows = SearchConsoleMetric::query()
            ->where('project_id', $project->getKey())
            ->where('dimension_type', 'query_page')
            ->where('normalized_path', $path)
            ->whereDate('report_date', '>=', $from->toDateString())
            ->whereDate('report_date', '<=', $to->toDateString())
            ->get();
        $queries = $rows->groupBy('dimension_value')
            ->map(function (Collection $metrics, string $query): array {
                $clicks = (int) $metrics->sum('clicks');
                $impressions = (int) $metrics->sum('impressions');
                $weightedPosition = (float) $metrics->sum(fn ($metric): float => (float) ($metric->position ?? 0) * $metric->impressions);

                return [
                    'query' => $query,
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
                    'position' => $impressions > 0 ? round($weightedPosition / $impressions, 2) : null,
                ];
            })
            ->sortByDesc('impressions')
            ->take(25)
            ->values()
            ->all();

        return [
            'status' => $queries === [] ? 'no_data' : 'ok',
            'queries' => $queries,
            'evidenceRef' => 'gsc:page:'.$path.':'.$from->toDateString().':'.$to->toDateString(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function goals(Project $project, string $path): array
    {
        return $project->goals()->where('is_active', true)->get()
            ->filter(fn ($goal): bool => $goal->type !== 'url'
                || $goal->path === null
                || ($goal->path_operator === 'prefix' ? Str::startsWith($path, $goal->path) : $path === $goal->path))
            ->map(fn ($goal): array => [
                'id' => (int) $goal->getKey(),
                'name' => (string) $goal->name,
                'type' => (string) $goal->type,
                'path' => $goal->path,
                'eventName' => $goal->event_name,
            ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function funnelSteps(Project $project, string $path): array
    {
        return DB::table('funnel_steps as steps')
            ->join('funnels', 'funnels.id', 'steps.funnel_id')
            ->where('funnels.project_id', $project->getKey())
            ->where('funnels.is_active', true)
            ->where('steps.type', 'url')
            ->where(function ($query) use ($path): void {
                $query->where(function ($query) use ($path): void {
                    $query->where('steps.path_operator', 'exact')->where('steps.path', $path);
                })->orWhere(function ($query) use ($path): void {
                    $query->where('steps.path_operator', 'prefix')->whereRaw('? LIKE steps.path || ?', [$path, '%']);
                });
            })
            ->orderBy('funnels.name')
            ->orderBy('steps.position')
            ->get(['funnels.id as funnel_id', 'funnels.name as funnel_name', 'steps.position', 'steps.name'])
            ->map(fn ($step): array => [
                'funnelId' => (int) $step->funnel_id,
                'funnelName' => (string) $step->funnel_name,
                'position' => (int) $step->position,
                'name' => (string) $step->name,
            ])->all();
    }

    private function change(int $current, int $previous): ?float
    {
        return $previous === 0 ? null : round((($current - $previous) / $previous) * 100, 1);
    }
}
