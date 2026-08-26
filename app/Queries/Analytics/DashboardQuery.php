<?php

namespace App\Queries\Analytics;

use App\Jobs\GenerateInsightRecommendations;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Project;
use App\Services\Analytics\AiReferralClassifier;
use App\Services\Analytics\AnalyticsRollupReader;
use App\Services\Analytics\DashboardInsightBuilder;
use App\Services\Analytics\FunnelAnalyticsService;
use App\Services\Analytics\GoalAnalyticsService;
use App\Services\Analytics\ImportantActionAnalyticsService;
use App\Services\Analytics\InsightGenerationService;
use App\Services\Analytics\SourceGrouping;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardQuery
{
    public function __construct(
        private readonly AiReferralClassifier $aiReferralClassifier,
        private readonly SourceGrouping $sourceGrouping,
        private readonly DashboardInsightBuilder $dashboardInsightBuilder,
        private readonly InsightGenerationService $insightGenerationService,
        private readonly GoalAnalyticsService $goalAnalyticsService,
        private readonly ImportantActionAnalyticsService $importantActionAnalyticsService,
        private readonly FunnelAnalyticsService $funnelAnalyticsService,
        private readonly AnalyticsRollupReader $rollupReader,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function run(Project $project, array $filters = [], bool $withInsights = true): array
    {
        [$from, $to, $rangeKey, $rangeLabel] = $this->range($project, $filters);
        $normalizedFilters = array_filter([
            'country' => $filters['country'] ?? null,
            'device' => $filters['device'] ?? null,
            'browser' => $filters['browser'] ?? null,
            'referrer' => $filters['referrer'] ?? null,
            'page' => $filters['page'] ?? null,
            'utm_campaign' => $filters['utm_campaign'] ?? null,
        ]);
        $cacheBust = (string) ($filters['refresh'] ?? '');
        $cacheKey = 'dashboard:v7:'.$project->getKey().':'.$rangeKey.':'.$from->timestamp.':'.$to->timestamp.':'.sha1((string) json_encode($normalizedFilters)).':'.($withInsights ? 'insights' : 'public').':'.$cacheBust;

        return Cache::remember($cacheKey, now()->addSeconds(30), function () use ($project, $from, $to, $rangeKey, $rangeLabel, $normalizedFilters, $withInsights): array {
            $activeAt = CarbonImmutable::now('UTC');
            $pageviews = $this->events($project, $from, $to, $normalizedFilters)->where('event_name', 'page_view')->count();
            $visitors = $this->events($project, $from, $to, $normalizedFilters)
                ->where('event_name', 'page_view')
                ->distinct('visitor_id')
                ->count('visitor_id');
            $sessionSummary = $this->sessionSummary($this->sessions($project, $from, $to, $normalizedFilters));
            $sessions = $sessionSummary['sessions'];
            $bounces = $sessionSummary['bounces'];
            $averageDuration = $sessionSummary['averageDuration'];
            $singlePageRate = $sessions > 0 ? round(($bounces / $sessions) * 100, 1) : 0;
            $conversionOverview = $this->conversionOverview($project, $from, $to, $normalizedFilters);
            // Keep the legacy referrer series for compatibility; the actionable
            // source grouping below uses session visits rather than page loads.
            $referrers = $this->breakdown($project, $from, $to, $normalizedFilters, 'referrer_host', 'Direct');
            $timeseries = $this->timeseries($project, $from, $to, $normalizedFilters, $rangeKey);
            $metrics = [
                'pageviews' => $pageviews,
                'visitors' => $visitors,
                'activeVisitors' => $this->activeVisitors($project, $normalizedFilters, $activeAt),
                'sessions' => $sessions,
                'bounceRate' => $singlePageRate,
                'averageDuration' => round($averageDuration),
                'viewsPerVisitor' => $visitors > 0 ? round($pageviews / $visitors, 2) : 0,
                'conversions' => $conversionOverview['conversions'],
                'conversionRate' => $conversionOverview['conversionRate'],
            ];

            [$previousFrom, $previousTo] = $this->previousRange($project, $from, $to, $activeAt);
            $actionableInsights = [];
            if ($withInsights) {
                $actionableInsights = $this->insightGenerationService->generate(
                    $project,
                    $from,
                    $to,
                    $normalizedFilters,
                    $previousFrom,
                    $previousTo,
                );
                if ($actionableInsights !== []) {
                    GenerateInsightRecommendations::dispatch(
                        $project,
                        $actionableInsights,
                        $from->toIso8601String(),
                        $to->toIso8601String(),
                    );
                }
            }

            return [
                'range' => [
                    'key' => $rangeKey,
                    'label' => $rangeLabel,
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'interval' => $this->usesHourlyBuckets($rangeKey) ? 'hour' : 'day',
                ],
                'metrics' => $metrics,
                'metricTrends' => $this->metricTrends($project, $from, $to, $normalizedFilters, $rangeKey, $metrics, $timeseries, $activeAt),
                'timeseries' => $timeseries,
                'topPages' => $this->breakdown($project, $from, $to, $normalizedFilters, 'path', 'Other'),
                'entryPages' => $this->sessionBreakdown($project, $from, $to, $normalizedFilters, 'entry_path', 'Other'),
                'exitPages' => $this->sessionBreakdown($project, $from, $to, $normalizedFilters, 'exit_path', 'Other'),
                'referrers' => $referrers,
                'countryVisits' => $this->countryVisits($project, $from, $to, $normalizedFilters),
                'sources' => $this->sourceBreakdown($project, $from, $to, $normalizedFilters),
                'devices' => $this->sessionBreakdown($project, $from, $to, $normalizedFilters, 'device', 'Unknown'),
                'browsers' => $this->sessionBreakdown($project, $from, $to, $normalizedFilters, 'browser', 'Unknown'),
                'operatingSystems' => $this->sessionBreakdown($project, $from, $to, $normalizedFilters, 'operating_system', 'Unknown'),
                'campaigns' => $this->sessionBreakdown($project, $from, $to, $normalizedFilters, 'utm_campaign', 'None'),
                'mediums' => $this->sessionBreakdown($project, $from, $to, $normalizedFilters, 'utm_medium', 'Direct'),
                'utmSources' => $this->sessionBreakdown($project, $from, $to, $normalizedFilters, 'utm_source', 'None'),
                'aiReferrals' => $this->aiReferrals($project, $from, $to, $normalizedFilters),
                'aiTraffic' => $this->aiTraffic($project, $from, $to, $normalizedFilters),
                'insights' => $this->dashboardInsightBuilder->build($sessions, $singlePageRate, $referrers),
                'whatChanged' => $actionableInsights,
                'actionableInsights' => $actionableInsights,
                'goals' => $conversionOverview['goals'],
                'importantActions' => $this->importantActionAnalyticsService->summarize($project, $from, $to),
                'funnels' => $project->funnels()->where('is_active', true)->with('steps')->get()->map(
                    fn ($funnel): array => [
                        'id' => $funnel->getKey(),
                        'name' => $funnel->name,
                        ...$this->funnelAnalyticsService->summary($funnel, $from, $to),
                    ],
                )->values()->all(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{totalVisits: int, sources: array<int, array{label: string, value: int}>}
     */
    private function aiReferrals(Project $project, CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        $referrerHosts = $this->aiReferralClassifier->referrerHosts();
        $utmSources = $this->aiReferralClassifier->utmSources();

        if ($referrerHosts === [] && $utmSources === []) {
            return ['totalVisits' => 0, 'sources' => []];
        }

        $sessions = $this->sessions($project, $from, $to, $filters)
            ->where(function (Builder $query) use ($referrerHosts, $utmSources): void {
                foreach ($referrerHosts as $host) {
                    $query
                        ->orWhereRaw('LOWER(referrer_host) = ?', [$host])
                        ->orWhereRaw('LOWER(referrer_host) LIKE ?', ['%.'.$host]);
                }

                foreach ($utmSources as $source) {
                    $query->orWhereRaw('LOWER(utm_source) = ?', [$source]);
                }
            })
            ->get(['referrer_host', 'utm_source']);

        $counts = [];

        foreach ($sessions as $session) {
            $provider = $this->aiReferralClassifier->classify(
                $session->getAttribute('referrer_host'),
                $session->getAttribute('utm_source'),
            );

            if ($provider === null) {
                continue;
            }

            $counts[$provider] = ($counts[$provider] ?? 0) + 1;
        }

        $sources = [];

        foreach ($counts as $provider => $count) {
            $sources[] = [
                'label' => $this->aiReferralClassifier->label($provider),
                'value' => $count,
            ];
        }

        usort($sources, function (array $first, array $second): int {
            $valueComparison = $second['value'] <=> $first['value'];

            return $valueComparison !== 0
                ? $valueComparison
                : strcasecmp($first['label'], $second['label']);
        });

        return [
            'totalVisits' => (int) array_sum($counts),
            'sources' => $sources,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{visitors: int, visits: int, pageviews: int, conversions: int, previousVisits: int, change: float|null, sources: array<int, array{label: string, value: int}>, pages: array<int, array{label: string, value: int}>}
     */
    private function aiTraffic(Project $project, CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        if ($this->aiReferralClassifier->referrerHosts() === [] && $this->aiReferralClassifier->utmSources() === []) {
            return ['visitors' => 0, 'visits' => 0, 'pageviews' => 0, 'conversions' => 0, 'previousVisits' => 0, 'change' => 0.0, 'sources' => [], 'pages' => []];
        }

        [$previousFrom, $previousTo] = $this->previousRange($project, $from, $to, CarbonImmutable::now('UTC'));
        $previousVisits = $this->aiReferrals($project, $previousFrom, $previousTo, $filters)['totalVisits'];

        $sessions = $this->sessions($project, $from, $to, $filters)
            ->where(function (Builder $query): void {
                foreach ($this->aiReferralClassifier->referrerHosts() as $host) {
                    $query
                        ->orWhereRaw('LOWER(referrer_host) = ?', [$host])
                        ->orWhereRaw('LOWER(referrer_host) LIKE ?', ['%.'.$host]);
                }
                foreach ($this->aiReferralClassifier->utmSources() as $source) {
                    $query->orWhereRaw('LOWER(utm_source) = ?', [$source]);
                }
            });
        $sessionRows = $sessions->get(['session_id', 'visitor_id', 'referrer_host', 'utm_source']);
        $sessionIds = [];
        $sourceCounts = [];
        $visitorIds = [];

        foreach ($sessionRows as $session) {
            $provider = $this->aiReferralClassifier->classify($session->referrer_host, $session->utm_source);
            if ($provider === null) {
                continue;
            }
            $sessionIds[] = (string) $session->session_id;
            $visitorIds[(string) $session->visitor_id] = true;
            $label = $this->aiReferralClassifier->label($provider);
            $sourceCounts[$label] = ($sourceCounts[$label] ?? 0) + 1;
        }

        if ($sessionIds === []) {
            return [
                'visitors' => 0,
                'visits' => 0,
                'pageviews' => 0,
                'conversions' => 0,
                'previousVisits' => $previousVisits,
                'change' => $previousVisits > 0 ? -100.0 : 0.0,
                'sources' => [],
                'pages' => [],
            ];
        }

        $events = $this->events($project, $from, $to, $filters)
            ->whereIn('session_id', array_values(array_unique($sessionIds)));
        $pageviews = (clone $events)->where('event_name', 'page_view')->count();
        $pages = (clone $events)->where('event_name', 'page_view')
            ->select('path')->selectRaw('COUNT(*) AS total')->groupBy('path')->orderByDesc('total')->limit(8)->get()
            ->map(fn ($row): array => ['label' => $row->getAttribute('path') ?: 'Other', 'value' => (int) $row->getAttribute('total')])->all();
        $sources = collect($sourceCounts)->map(fn (int $value, string $label): array => ['label' => $label, 'value' => $value])->sortByDesc('value')->values()->all();
        $conversions = (int) DB::table('goal_conversions')
            ->where('project_id', $project->getKey())
            ->whereIn('session_id', array_values(array_unique($sessionIds)))
            ->whereBetween('occurred_at', [$this->databaseTimestamp($from), $this->databaseTimestamp($to)])
            ->count();
        $currentVisits = count(array_unique($sessionIds));
        $change = $previousVisits === 0
            ? null
            : round((($currentVisits - $previousVisits) / $previousVisits) * 100, 1);

        return [
            'visitors' => count($visitorIds),
            'visits' => $currentVisits,
            'pageviews' => (int) $pageviews,
            'conversions' => $conversions,
            'previousVisits' => $previousVisits,
            'change' => $change,
            'sources' => $sources,
            'pages' => $pages,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AnalyticsEvent>
     */
    private function events(Project $project, CarbonImmutable $from, CarbonImmutable $to, array $filters): Builder
    {
        $query = AnalyticsEvent::query()
            ->where('project_id', $project->getKey())
            ->whereBetween('occurred_at', [
                $this->databaseTimestamp($from),
                $this->databaseTimestamp($to),
            ]);

        return $this->applyFilters($query, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AnalyticsSession>
     */
    private function sessions(Project $project, CarbonImmutable $from, CarbonImmutable $to, array $filters): Builder
    {
        $query = AnalyticsSession::query()
            ->where('project_id', $project->getKey())
            ->whereBetween('started_at', [
                $this->databaseTimestamp($from),
                $this->databaseTimestamp($to),
            ]);

        return $this->applySessionFilters($query, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function activeVisitors(Project $project, array $filters, CarbonImmutable $activeAt): int
    {
        $query = AnalyticsSession::query()
            ->where('project_id', $project->getKey())
            ->whereBetween('last_seen_at', [
                $this->databaseTimestamp($activeAt->subMinutes(5)),
                $this->databaseTimestamp($activeAt),
            ]);

        return $this->applySessionFilters($query, $filters)
            ->distinct('visitor_id')
            ->count('visitor_id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, int|float>  $metrics
     * @param  array<int, array{label: string, pageviews: int, visitors: int}>  $timeseries
     * @return array<string, array{previous: int|float, change: float|null, series: array<int, int|float>}>
     */
    private function metricTrends(Project $project, CarbonImmutable $from, CarbonImmutable $to, array $filters, string $rangeKey, array $metrics, array $timeseries, CarbonImmutable $activeAt): array
    {
        [$previousFrom, $previousTo] = $this->previousRange($project, $from, $to, $activeAt);
        $previousPageviews = $this->events($project, $previousFrom, $previousTo, $filters)
            ->where('event_name', 'page_view')
            ->count();
        $previousVisitors = $this->events($project, $previousFrom, $previousTo, $filters)
            ->where('event_name', 'page_view')
            ->distinct('visitor_id')
            ->count('visitor_id');
        $previousSessionSummary = $this->sessionSummary($this->sessions($project, $previousFrom, $previousTo, $filters));
        $previousSessions = $previousSessionSummary['sessions'];
        $previousBounces = $previousSessionSummary['bounces'];
        $previousAverageDuration = $previousSessionSummary['averageDuration'];
        $previousBounceRate = $previousSessions > 0 ? round(($previousBounces / $previousSessions) * 100, 1) : 0;
        $previousViewsPerVisitor = $previousVisitors > 0 ? round($previousPageviews / $previousVisitors, 2) : 0;
        $sessionSeries = $this->sessionMetricSeries($project, $from, $to, $filters, $rangeKey);
        $previousActiveVisitors = $this->activeVisitorsInWindow(
            $project,
            $filters,
            $activeAt->subMinutes(10),
            $activeAt->subMinutes(5),
        );

        return [
            'activeVisitors' => $this->metricTrend(
                $metrics['activeVisitors'],
                $previousActiveVisitors,
                $this->activeVisitorSeries($project, $filters, $activeAt),
            ),
            'visitors' => $this->metricTrend(
                $metrics['visitors'],
                $previousVisitors,
                array_column($timeseries, 'visitors'),
            ),
            'pageviews' => $this->metricTrend(
                $metrics['pageviews'],
                $previousPageviews,
                array_column($timeseries, 'pageviews'),
            ),
            'sessions' => $this->metricTrend(
                $metrics['sessions'],
                $previousSessions,
                array_column($sessionSeries['sessions'], 'sessions'),
            ),
            'bounceRate' => $this->metricTrend(
                $metrics['bounceRate'],
                $previousBounceRate,
                $sessionSeries['bounceRate'],
            ),
            'viewsPerVisitor' => $this->metricTrend(
                $metrics['viewsPerVisitor'],
                $previousViewsPerVisitor,
                array_map(
                    fn (array $bucket): float|int => $bucket['visitors'] > 0
                        ? round($bucket['pageviews'] / $bucket['visitors'], 2)
                        : 0,
                    $timeseries,
                ),
            ),
            'averageDuration' => $this->metricTrend(
                $metrics['averageDuration'],
                round($previousAverageDuration),
                $sessionSeries['averageDuration'],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function activeVisitorsInWindow(Project $project, array $filters, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $query = AnalyticsSession::query()
            ->where('project_id', $project->getKey())
            ->where('last_seen_at', '>=', $this->databaseTimestamp($from))
            ->where('last_seen_at', '<', $this->databaseTimestamp($to));

        return $this->applySessionFilters($query, $filters)
            ->distinct('visitor_id')
            ->count('visitor_id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{sessions: array<int, int>, bounceRate: array<int, float>, averageDuration: array<int, int>}
     */
    private function sessionMetricSeries(Project $project, CarbonImmutable $from, CarbonImmutable $to, array $filters, string $rangeKey): array
    {
        $usesHourlyBuckets = $this->usesHourlyBuckets($rangeKey);
        $buckets = [];
        $cursor = $usesHourlyBuckets ? $from->startOfHour() : $from->startOfDay();
        $bucketEnd = $usesHourlyBuckets ? $to->startOfHour() : $to->startOfDay();

        if ($rangeKey === 'today') {
            $currentHour = CarbonImmutable::now($project->timezone)->startOfHour();
            $bucketEnd = $currentHour->lt($bucketEnd) ? $currentHour : $bucketEnd;
        }

        while ($cursor->lte($bucketEnd)) {
            $key = $cursor->format($usesHourlyBuckets ? 'Y-m-d H' : 'Y-m-d');
            $buckets[$key] = [
                'sessions' => 0,
                'bounces' => 0,
                'duration' => 0,
            ];
            $cursor = $usesHourlyBuckets ? $cursor->addHour() : $cursor->addDay();
        }

        $sessions = $this->sessions($project, $from, $to, $filters)
            ->get(['started_at', 'is_bounce', 'duration_seconds']);

        foreach ($sessions as $session) {
            $startedAt = $session->getAttribute('started_at');
            $startedAt = $startedAt instanceof CarbonImmutable
                ? $startedAt
                : CarbonImmutable::parse((string) $startedAt, config('app.timezone'));
            $key = $startedAt
                ->setTimezone($project->timezone)
                ->format($usesHourlyBuckets ? 'Y-m-d H' : 'Y-m-d');

            if (! isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['sessions']++;
            $buckets[$key]['bounces'] += $session->getAttribute('is_bounce') ? 1 : 0;
            $buckets[$key]['duration'] += (int) $session->getAttribute('duration_seconds');
        }

        return [
            'sessions' => array_map(
                fn (array $bucket): int => $bucket['sessions'],
                array_values($buckets),
            ),
            'bounceRate' => array_map(
                fn (array $bucket): float => $bucket['sessions'] > 0
                    ? round(($bucket['bounces'] / $bucket['sessions']) * 100, 1)
                    : 0,
                array_values($buckets),
            ),
            'averageDuration' => array_map(
                fn (array $bucket): int => $bucket['sessions'] > 0
                    ? (int) round($bucket['duration'] / $bucket['sessions'])
                    : 0,
                array_values($buckets),
            ),
        ];
    }

    /**
     * @param  Builder<AnalyticsSession>  $query
     * @return array{sessions: int, bounces: int, averageDuration: float}
     */
    private function sessionSummary(Builder $query): array
    {
        $summary = (clone $query)
            ->toBase()
            ->selectRaw('COUNT(*) AS sessions')
            ->selectRaw('SUM(CASE WHEN is_bounce THEN 1 ELSE 0 END) AS bounces')
            ->selectRaw('AVG(duration_seconds) AS average_duration')
            ->first();

        return [
            'sessions' => (int) ($summary->sessions ?? 0),
            'bounces' => (int) ($summary->bounces ?? 0),
            'averageDuration' => (float) ($summary->average_duration ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, int>
     */
    private function activeVisitorSeries(Project $project, array $filters, CarbonImmutable $activeAt): array
    {
        $seriesStart = $activeAt->subMinutes(30);
        $visitorBuckets = array_fill(0, 6, []);
        $query = AnalyticsSession::query()
            ->where('project_id', $project->getKey())
            ->whereBetween('last_seen_at', [
                $this->databaseTimestamp($seriesStart),
                $this->databaseTimestamp($activeAt),
            ]);
        $sessions = $this->applySessionFilters($query, $filters)
            ->get(['visitor_id', 'last_seen_at']);

        foreach ($sessions as $session) {
            $lastSeenAt = $session->getAttribute('last_seen_at');
            $lastSeenAt = $lastSeenAt instanceof CarbonImmutable
                ? $lastSeenAt
                : CarbonImmutable::parse((string) $lastSeenAt, config('app.timezone'));
            $offset = $lastSeenAt->getTimestamp() - $seriesStart->getTimestamp();

            if ($offset < 0 || $offset > 1800) {
                continue;
            }

            $bucket = min(5, intdiv($offset, 300));
            $visitorBuckets[$bucket][(string) $session->getAttribute('visitor_id')] = true;
        }

        return array_map(count(...), $visitorBuckets);
    }

    /**
     * @param  array<int, int|float>  $series
     * @return array{previous: int|float, change: float|null, series: array<int, int|float>}
     */
    private function metricTrend(int|float $current, int|float $previous, array $series): array
    {
        $change = match (true) {
            (float) $previous === 0.0 && (float) $current === 0.0 => 0.0,
            (float) $previous === 0.0 => null,
            default => round((($current - $previous) / abs($previous)) * 100, 1),
        };

        return [
            'previous' => $previous,
            'change' => $change,
            'series' => $series,
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function previousRange(Project $project, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $activeAt): array
    {
        $projectNow = $activeAt->setTimezone($project->timezone);
        $effectiveTo = $to->gt($projectNow) ? $projectNow : $to;
        $duration = max(1, $effectiveTo->getTimestamp() - $from->getTimestamp());
        $previousTo = $from->subSecond();

        return [$previousTo->subSeconds($duration), $previousTo];
    }

    /**
     * @param  Builder<AnalyticsSession>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<AnalyticsSession>
     */
    private function applySessionFilters(Builder $query, array $filters): Builder
    {
        foreach (['country', 'device', 'browser'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['referrer'])) {
            $query->where('referrer_host', $filters['referrer']);
        }

        if (! empty($filters['utm_campaign'])) {
            $query->where('utm_campaign', $filters['utm_campaign']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string, pageviews: int, visitors: int}>
     */
    private function timeseries(Project $project, CarbonImmutable $from, CarbonImmutable $to, array $filters, string $rangeKey): array
    {
        $usesHourlyBuckets = $this->usesHourlyBuckets($rangeKey);
        $rollupSeries = $this->rollupReader->timeSeries(
            $project,
            $from,
            $to,
            $usesHourlyBuckets ? 'hour' : 'day',
            $filters,
        );
        if ($rollupSeries !== null) {
            return $rollupSeries;
        }

        $buckets = [];
        $cursor = $usesHourlyBuckets ? $from->startOfHour() : $from->startOfDay();
        $bucketEnd = $usesHourlyBuckets ? $to->startOfHour() : $to->startOfDay();

        if ($rangeKey === 'today') {
            $currentHour = CarbonImmutable::now($project->timezone)->startOfHour();
            $bucketEnd = $currentHour->lt($bucketEnd) ? $currentHour : $bucketEnd;
        }

        while ($cursor->lte($bucketEnd)) {
            $key = $usesHourlyBuckets ? $cursor->format('Y-m-d H') : $cursor->toDateString();
            $buckets[$key] = [
                'label' => $cursor->format($usesHourlyBuckets ? 'g A' : 'M j'),
                'pageviews' => 0,
                'visitors' => [],
            ];
            $cursor = $usesHourlyBuckets ? $cursor->addHour() : $cursor->addDay();
        }

        $events = $this->events($project, $from, $to, $filters)
            ->where('event_name', 'page_view')
            ->get(['visitor_id', 'occurred_at']);

        foreach ($events as $event) {
            $occurredAt = $event->getAttribute('occurred_at');
            $occurredAt = $occurredAt instanceof CarbonImmutable
                ? $occurredAt
                : CarbonImmutable::parse((string) $occurredAt, config('app.timezone'));

            $key = $occurredAt
                ->setTimezone($project->timezone)
                ->format($usesHourlyBuckets ? 'Y-m-d H' : 'Y-m-d');

            if (! isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['pageviews']++;
            $buckets[$key]['visitors'][$event->getAttribute('visitor_id')] = true;
        }

        return array_map(
            fn (array $bucket): array => [
                'label' => $bucket['label'],
                'pageviews' => $bucket['pageviews'],
                'visitors' => count($bucket['visitors']),
            ],
            array_values($buckets),
        );
    }

    private function usesHourlyBuckets(string $rangeKey): bool
    {
        return in_array($rangeKey, ['today', 'yesterday'], true);
    }

    private function databaseTimestamp(CarbonImmutable $dateTime): string
    {
        return $dateTime->utc()->toDateTimeString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string, value: int}>
     */
    private function breakdown(Project $project, CarbonImmutable $from, CarbonImmutable $to, array $filters, string $column, string $fallback): array
    {
        return $this->events($project, $from, $to, $filters)
            ->where('event_name', 'page_view')
            ->select($column)
            ->selectRaw('COUNT(*) as total')
            ->groupBy($column)
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'label' => $row->getAttribute($column) ?: $fallback,
                'value' => (int) $row->getAttribute('total'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string, value: int}>
     */
    private function sessionBreakdown(Project $project, CarbonImmutable $from, CarbonImmutable $to, array $filters, string $column, string $fallback): array
    {
        return $this->sessions($project, $from, $to, $filters)
            ->select($column)
            ->selectRaw('COUNT(*) as total')
            ->groupBy($column)
            ->orderByDesc('total')
            ->orderBy($column)
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'label' => $row->getAttribute($column) ?: $fallback,
                'value' => (int) $row->getAttribute('total'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string, value: int}>
     */
    private function sourceBreakdown(Project $project, CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        $counts = [];
        $sessions = $this->sessions($project, $from, $to, $filters)
            ->get(['referrer_host', 'utm_source', 'utm_medium']);

        foreach ($sessions as $session) {
            $source = $this->sourceGrouping->classify($session->referrer_host, $session->utm_source, $session->utm_medium)['source'];
            $counts[$source] = ($counts[$source] ?? 0) + 1;
        }

        arsort($counts);

        return collect($counts)
            ->take(8)
            ->map(fn (int $value, string $label): array => ['label' => $label, 'value' => $value])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{total: int, unknown: int, countries: array<int, array{code: string, visits: int}>}
     */
    private function countryVisits(Project $project, CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        $countryCounts = $this->sessions($project, $from, $to, $filters)
            ->select('country')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('country')
            ->orderByDesc('total')
            ->orderBy('country')
            ->get();
        $countries = [];
        $unknown = 0;

        foreach ($countryCounts as $countryCount) {
            $country = $countryCount->getAttribute('country');
            $visits = (int) $countryCount->getAttribute('total');

            if (! is_string($country) || preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                $unknown += $visits;

                continue;
            }

            $countries[] = [
                'code' => $country,
                'visits' => $visits,
            ];
        }

        return [
            'total' => (int) $countryCounts->sum('total'),
            'unknown' => $unknown,
            'countries' => $countries,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string, 3: string}
     */
    private function range(Project $project, array $filters): array
    {
        $key = (string) ($filters['range'] ?? '7d');
        $today = CarbonImmutable::now($project->timezone)->startOfDay();

        if ($key === 'today') {
            return [$today, $today->endOfDay(), $key, 'Today'];
        }

        if ($key === 'yesterday') {
            $day = $today->subDay();

            return [$day, $day->endOfDay(), $key, 'Yesterday'];
        }

        if ($key === '30d') {
            return [$today->subDays(29), $today->endOfDay(), $key, 'Last 30 days'];
        }

        if ($key === 'month') {
            return [$today->startOfMonth(), $today->endOfDay(), $key, 'This month'];
        }

        if ($key === 'custom' && isset($filters['from'], $filters['to'])) {
            try {
                $from = CarbonImmutable::parse((string) $filters['from'], $project->timezone)->startOfDay();
                $to = CarbonImmutable::parse((string) $filters['to'], $project->timezone)->endOfDay();

                return $from->lte($to)
                    ? [$from, $to, $key, $from->format('M j').' – '.$to->format('M j, Y')]
                    : [$to, $from, $key, $to->format('M j').' – '.$from->format('M j, Y')];
            } catch (\Throwable) {
                // Fall back to a safe predefined range for malformed custom input.
            }
        }

        return [$today->subDays(6), $today->endOfDay(), '7d', 'Last 7 days'];
    }

    /**
     * @param  Builder<AnalyticsEvent>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<AnalyticsEvent>
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        foreach (['country', 'device', 'browser'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['referrer'])) {
            $query->where('referrer_host', $filters['referrer']);
        }

        if (! empty($filters['page'])) {
            $query->where('path', $filters['page']);
        }

        if (! empty($filters['utm_campaign'])) {
            $query->where('utm_campaign', $filters['utm_campaign']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{conversions: int, conversionRate: float, goals: array<int, array<string, mixed>>}
     */
    private function conversionOverview(Project $project, CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        $visits = $this->sessions($project, $from, $to, $filters)->count();
        $goals = [];
        $conversions = 0;

        foreach ($project->goals()->where('is_active', true)->get() as $goal) {
            $summary = $this->goalAnalyticsService->summary($goal, $from, $to, $filters);
            $conversions += $summary['conversions'];
            $goals[] = [
                'id' => $goal->getKey(),
                'name' => $goal->name,
                'type' => $goal->type ?: 'event',
                ...$summary,
            ];
        }

        return [
            'conversions' => $conversions,
            'conversionRate' => $visits > 0 ? round(($conversions / $visits) * 100, 2) : 0.0,
            'goals' => $goals,
        ];
    }
}
