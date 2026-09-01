<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Funnel;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductInvestigationService
{
    private const MIN_SESSIONS = 20;

    private const MIN_AFFECTED_SESSIONS = 10;

    public function __construct(
        private readonly BrowserSignalAnalyticsService $signals,
        private readonly FunnelAnalyticsService $funnels,
    ) {}

    /** @return array<string, mixed> */
    public function investigateChange(Project $project, CarbonImmutable $from, CarbonImmutable $to, ?string $path = null): array
    {
        [$currentFrom, $currentTo, $previousFrom, $previousTo] = $this->periods($from, $to);
        $current = $this->period($project, $currentFrom, $currentTo, $path);
        $previous = $this->period($project, $previousFrom, $previousTo, $path);

        if ($previous['sessions'] === 0) {
            return [
                'status' => 'comparison_pending',
                'current' => $current,
                'previous' => $previous,
                'changes' => [],
                'segments' => [],
            ];
        }

        return [
            'status' => 'ok',
            'current' => $current,
            'previous' => $previous,
            'changes' => $this->changes($current, $previous),
            'segments' => $this->segmentChanges($project, $currentFrom, $currentTo, $previousFrom, $previousTo, $path),
        ];
    }

    /** @return array<string, mixed> */
    public function findFriction(Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $findings = [];
        $paths = $project->events()
            ->where('event_name', 'page_view')
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->whereNotNull('path')
            ->select('path')
            ->selectRaw('COUNT(DISTINCT session_id) as sessions')
            ->groupBy('path')
            ->orderByDesc('sessions')
            ->limit(10)
            ->get();

        foreach ($paths as $page) {
            $signals = $this->signals->page($project, (string) $page->path, $from, $to);
            if ($signals['status'] !== 'ok') {
                continue;
            }
            $failures = $signals['failures'];
            if (($failures['affectedSessions'] ?? 0) >= self::MIN_AFFECTED_SESSIONS && ($failures['rate'] ?? 0) >= 5) {
                $findings[] = [
                    'category' => 'runtime_failure',
                    'priority' => 'high',
                    'path' => $page->path,
                    'affectedSessions' => $failures['affectedSessions'],
                    'summary' => "Browser or request failures affected {$failures['affectedSessions']} sessions on {$page->path} ({$failures['rate']}%).",
                    'evidenceRef' => $signals['evidenceRef'],
                ];
            }

            $lcp = $signals['performance']['lcp'];
            if (($lcp['samples'] ?? 0) >= self::MIN_SESSIONS && ($lcp['p75Ms'] ?? 0) >= 2500) {
                $findings[] = [
                    'category' => 'performance',
                    'priority' => 'medium',
                    'path' => $page->path,
                    'affectedSessions' => $lcp['samples'],
                    'summary' => "LCP p75 was {$lcp['p75Ms']}ms on {$page->path}.",
                    'evidenceRef' => $signals['evidenceRef'],
                ];
            }

            $behavior = $signals['behavior'];
            $attempts = (int) $behavior['submitAttempts'];
            $abandoned = (int) $behavior['submitAttemptsWithoutSubmission'];
            if ($attempts >= self::MIN_SESSIONS && ($abandoned / $attempts) * 100 >= 30) {
                $findings[] = [
                    'category' => 'interaction',
                    'priority' => 'high',
                    'path' => $page->path,
                    'affectedSessions' => $abandoned,
                    'summary' => "{$abandoned} of {$attempts} sessions clicked a submit control without a later form submission on {$page->path}.",
                    'evidenceRef' => $signals['evidenceRef'],
                ];
            }
        }

        $funnels = Funnel::query()->where('project_id', $project->getKey())->where('is_active', true)->with('steps')->limit(10)->get();
        foreach ($funnels as $funnel) {
            $investigation = $this->funnels->investigate($funnel, $from, $to);
            $dropOff = $investigation['largestDropOff'];
            if ($dropOff !== null && $dropOff['users'] >= self::MIN_SESSIONS && $dropOff['dropOffPercentage'] >= 30) {
                $findings[] = [
                    'category' => 'funnel',
                    'priority' => 'high',
                    'path' => null,
                    'affectedSessions' => $dropOff['dropOff'],
                    'summary' => "{$funnel->name} loses {$dropOff['dropOffPercentage']}% between {$dropOff['name']} and the next step.",
                    'evidenceRef' => 'analytics:funnel:'.$funnel->getKey().':'.$from->toDateString().':'.$to->toDateString(),
                ];
            }
        }

        return [
            'status' => $findings === [] ? 'no_findings' : 'ok',
            'findings' => collect($findings)->sortBy([
                ['priority', 'asc'],
                ['affectedSessions', 'desc'],
            ])->values()->take(10)->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function period(Project $project, CarbonImmutable $from, CarbonImmutable $to, ?string $path): array
    {
        $events = AnalyticsEvent::query()
            ->where('project_id', $project->getKey())
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()]);
        $sessions = $this->sessions($project, $from, $to, $path);
        $sessionIds = $sessions->pluck('session_id')->values()->all();
        $conversions = $sessionIds === [] ? 0 : (int) DB::table('goal_conversions')
            ->where('project_id', $project->getKey())
            ->whereIn('session_id', $sessionIds)
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->distinct('session_id')
            ->count('session_id');
        $signal = $path === null
            ? $this->signals->site($project, $from, $to)
            : $this->signals->page($project, $path, $from, $to);
        $pageviews = (clone $events)->where('event_name', 'page_view')->when($path !== null, fn ($query) => $query->where('path', $path))->count();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'path' => $path,
            'sessions' => $sessions->count(),
            'pageviews' => (int) $pageviews,
            'conversions' => $conversions,
            'conversionRate' => $sessions->count() > 0 ? round(($conversions / $sessions->count()) * 100, 1) : null,
            'lcpP75Ms' => $signal['performance']['lcp']['p75Ms'],
            'lcpSamples' => $signal['performance']['lcp']['samples'],
            'failureRate' => $signal['failures']['rate'],
            'failureAffectedSessions' => $signal['failures']['affectedSessions'],
            'submitAttempts' => $signal['behavior']['submitAttempts'],
            'submitAttemptsWithoutSubmission' => $signal['behavior']['submitAttemptsWithoutSubmission'],
        ];
    }

    /** @return Collection<int, AnalyticsSession> */
    private function sessions(Project $project, CarbonImmutable $from, CarbonImmutable $to, ?string $path): Collection
    {
        $query = AnalyticsSession::query()->where('project_id', $project->getKey())->whereBetween('started_at', [$from->utc(), $to->utc()]);
        if ($path !== null) {
            $sessionIds = AnalyticsEvent::query()
                ->where('project_id', $project->getKey())
                ->where('event_name', 'page_view')
                ->where('path', $path)
                ->whereBetween('occurred_at', [$from->utc(), $to->utc()])
                ->select('session_id')
                ->distinct();
            $query->whereIn('session_id', $sessionIds);
        }

        return $query->get(['session_id', 'device', 'browser', 'utm_source', 'utm_campaign']);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable} */
    private function periods(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $duration = max(1, $to->getTimestamp() - $from->getTimestamp());
        $previousTo = $from->subSecond();

        return [$from, $to, $previousTo->subSeconds($duration), $previousTo];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array<int, array<string, mixed>>
     */
    private function changes(array $current, array $previous): array
    {
        $changes = [];
        foreach (['pageviews', 'conversions', 'conversionRate', 'lcpP75Ms', 'failureRate'] as $metric) {
            $now = $current[$metric];
            $then = $previous[$metric];
            $changes[] = [
                'metric' => $metric,
                'current' => $now,
                'previous' => $then,
                'changePercent' => is_numeric($now) && is_numeric($then) && (float) $then !== 0.0
                    ? round((((float) $now - (float) $then) / (float) $then) * 100, 1)
                    : null,
                'changePoints' => is_numeric($now) && is_numeric($then) && in_array($metric, ['conversionRate', 'failureRate'], true)
                    ? round((float) $now - (float) $then, 1)
                    : null,
            ];
        }

        return $changes;
    }

    /** @return array<int, array<string, mixed>> */
    private function segmentChanges(Project $project, CarbonImmutable $currentFrom, CarbonImmutable $currentTo, CarbonImmutable $previousFrom, CarbonImmutable $previousTo, ?string $path): array
    {
        $current = $this->sessions($project, $currentFrom, $currentTo, $path);
        $previous = $this->sessions($project, $previousFrom, $previousTo, $path);
        $currentConversions = $this->conversionSessionIds($project, $currentFrom, $currentTo, $current->pluck('session_id')->all());
        $previousConversions = $this->conversionSessionIds($project, $previousFrom, $previousTo, $previous->pluck('session_id')->all());
        $results = [];

        foreach (['device', 'browser', 'utm_source', 'utm_campaign'] as $dimension) {
            $currentGroups = $current->groupBy(fn (AnalyticsSession $session): string => (string) ($session->{$dimension} ?: 'unknown'));
            $previousGroups = $previous->groupBy(fn (AnalyticsSession $session): string => (string) ($session->{$dimension} ?: 'unknown'));
            foreach ($currentGroups as $value => $currentGroup) {
                $previousGroup = $previousGroups->get($value, collect());
                if ($currentGroup->count() < self::MIN_SESSIONS || $previousGroup->count() < self::MIN_SESSIONS) {
                    continue;
                }
                $currentRate = $this->rate($currentGroup->pluck('session_id'), $currentConversions);
                $previousRate = $this->rate($previousGroup->pluck('session_id'), $previousConversions);
                $results[] = [
                    'dimension' => $dimension,
                    'value' => $value,
                    'currentSessions' => $currentGroup->count(),
                    'previousSessions' => $previousGroup->count(),
                    'currentConversionRate' => $currentRate,
                    'previousConversionRate' => $previousRate,
                    'changePoints' => round($currentRate - $previousRate, 1),
                ];
            }
        }

        return collect($results)->sortBy('changePoints')->take(20)->values()->all();
    }

    /**
     * @param  array<int, string>  $sessionIds
     * @return Collection<int, string>
     */
    private function conversionSessionIds(Project $project, CarbonImmutable $from, CarbonImmutable $to, array $sessionIds): Collection
    {
        if ($sessionIds === []) {
            return collect();
        }

        return collect(DB::table('goal_conversions')
            ->where('project_id', $project->getKey())
            ->whereIn('session_id', $sessionIds)
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->distinct()
            ->pluck('session_id'));
    }

    /**
     * @param  Collection<int, mixed>  $sessionIds
     * @param  Collection<int, string>  $conversions
     */
    private function rate(Collection $sessionIds, Collection $conversions): float
    {
        return $sessionIds->count() > 0 ? round(($sessionIds->intersect($conversions)->count() / $sessionIds->count()) * 100, 1) : 0.0;
    }
}
