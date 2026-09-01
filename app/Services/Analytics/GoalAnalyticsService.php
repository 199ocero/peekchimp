<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsSession;
use App\Models\Goal;
use App\Models\ProjectDomain;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GoalAnalyticsService
{
    public function __construct(private readonly SourceGrouping $sourceGrouping) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{conversions: int, visits: int, conversionRate: float, trend: array{previous: int, change: float|null}, bySource: array<int, array{label: string, conversions: int, visits: int, rate: float}>}
     */
    public function summary(Goal $goal, CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        $projectId = (int) $goal->project_id;
        $internalDomains = ProjectDomain::query()
            ->where('project_id', $projectId)
            ->pluck('domain')
            ->all();
        $sessionQuery = AnalyticsSession::query()
            ->where('project_id', $projectId)
            ->whereBetween('started_at', [$from->utc(), $to->utc()]);
        $this->applySessionFilters($sessionQuery, $filters);
        $visits = $sessionQuery->count();
        $conversionQuery = DB::table('goal_conversions')
            ->where('goal_conversions.goal_id', $goal->getKey())
            ->where('goal_conversions.project_id', $projectId)
            ->whereBetween('goal_conversions.occurred_at', [$from->utc(), $to->utc()]);
        if ($filters !== []) {
            $conversionQuery
                ->join('analytics_sessions as sessions', function ($join): void {
                    $join->on('sessions.session_id', '=', 'goal_conversions.session_id')
                        ->on('sessions.project_id', '=', 'goal_conversions.project_id');
                });
            $this->applyQuerySessionFilters($conversionQuery, $filters);
        }
        $conversions = $conversionQuery->count('goal_conversions.id');
        $conversionRowsQuery = DB::table('goal_conversions as conversions')
            ->join('analytics_sessions as sessions', function ($join): void {
                $join->on('sessions.session_id', '=', 'conversions.session_id')
                    ->on('sessions.project_id', '=', 'conversions.project_id');
            })
            ->where('conversions.goal_id', $goal->getKey())
            ->whereBetween('conversions.occurred_at', [$from->utc(), $to->utc()]);
        $this->applyQuerySessionFilters($conversionRowsQuery, $filters);
        $conversionRows = $conversionRowsQuery
            ->get(['sessions.referrer_host', 'sessions.utm_source']);
        $sourceVisits = [];
        foreach ((clone $sessionQuery)->get(['referrer_host', 'utm_source']) as $session) {
            $source = $this->sourceGrouping->classify($session->referrer_host, $session->utm_source, null, $internalDomains)['source'];
            $sourceVisits[$source] = ($sourceVisits[$source] ?? 0) + 1;
        }
        $sourceCounts = [];

        foreach ($conversionRows as $row) {
            $source = $this->sourceGrouping->classify($row->referrer_host, $row->utm_source, null, $internalDomains)['source'];
            $sourceCounts[$source] = ($sourceCounts[$source] ?? 0) + 1;
        }

        $bySource = (new Collection($sourceCounts))
            ->map(fn (int $count, string $source): array => [
                'label' => $source,
                'conversions' => $count,
                'visits' => (int) ($sourceVisits[$source] ?? 0),
                'rate' => round(($count / max(1, (int) ($sourceVisits[$source] ?? 0))) * 100, 2),
            ])
            ->sortByDesc('conversions')
            ->values()
            ->all();
        $duration = max(1, $to->getTimestamp() - $from->getTimestamp());
        $previousTo = $from->subSecond();
        $previousFrom = $previousTo->subSeconds($duration);
        $previousConversions = $this->conversionCount($goal, $previousFrom, $previousTo, $filters);
        $conversionChange = $previousConversions === 0
            ? ($conversions === 0 ? 0.0 : null)
            : round((($conversions - $previousConversions) / $previousConversions) * 100, 1);

        return [
            'conversions' => (int) $conversions,
            'visits' => (int) $visits,
            'conversionRate' => $visits > 0 ? round(($conversions / $visits) * 100, 2) : 0.0,
            'trend' => ['previous' => $previousConversions, 'change' => $conversionChange],
            'bySource' => $bySource,
        ];
    }

    /**
     * @param  Builder<AnalyticsSession>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySessionFilters(Builder $query, array $filters): void
    {
        foreach (['country', 'device', 'browser', 'utm_campaign'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['referrer'])) {
            $query->where('referrer_host', $filters['referrer']);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyQuerySessionFilters(QueryBuilder $query, array $filters): void
    {
        foreach (['country', 'device', 'browser', 'utm_campaign'] as $field) {
            if (! empty($filters[$field])) {
                $query->where('sessions.'.$field, $filters[$field]);
            }
        }

        if (! empty($filters['referrer'])) {
            $query->where('sessions.referrer_host', $filters['referrer']);
        }
    }

    /** @param array<string, mixed> $filters */
    private function conversionCount(Goal $goal, CarbonImmutable $from, CarbonImmutable $to, array $filters): int
    {
        $query = DB::table('goal_conversions')
            ->where('goal_id', $goal->getKey())
            ->where('project_id', $goal->project_id)
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()]);

        if ($filters !== []) {
            $query->join('analytics_sessions as sessions', function ($join): void {
                $join->on('sessions.session_id', '=', 'goal_conversions.session_id')
                    ->on('sessions.project_id', '=', 'goal_conversions.project_id');
            });
            $this->applyQuerySessionFilters($query, $filters);
        }

        return (int) $query->count('goal_conversions.id');
    }
}
