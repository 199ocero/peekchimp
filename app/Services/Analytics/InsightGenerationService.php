<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Insight;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InsightGenerationService
{
    public function __construct(
        private readonly AnalyticsComparisonService $comparison,
        private readonly InsightPriority $insightPriority,
        private readonly SourceGrouping $sourceGrouping,
        private readonly FunnelAnalyticsService $funnelAnalytics,
        private readonly InsightActionService $insightActions,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function generate(
        Project $project,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $filters = [],
        ?CarbonImmutable $previousFrom = null,
        ?CarbonImmutable $previousTo = null,
        bool $persist = true,
    ): array {
        $duration = max(1, $to->getTimestamp() - $from->getTimestamp());
        $previousFrom ??= $from->subSeconds($duration + 1);
        $previousTo ??= $from->subSecond();
        $current = $this->snapshot($project, $from, $to, $filters);
        $previous = $this->snapshot($project, $previousFrom, $previousTo, $filters);

        if ($previous['pageviews'] === 0 && $previous['sessions'] === 0) {
            return [];
        }

        $candidates = [];

        foreach ([
            ['metric' => 'visitors', 'category' => 'traffic', 'label' => 'Visitors', 'sample' => $current['sessions'] + $previous['sessions']],
            ['metric' => 'pageviews', 'category' => 'traffic', 'label' => 'Page views', 'sample' => $current['pageviews'] + $previous['pageviews']],
            ['metric' => 'sessions', 'category' => 'traffic', 'label' => 'Visits', 'sample' => $current['sessions'] + $previous['sessions']],
        ] as $metric) {
            $candidate = $this->comparison->compare(
                $current[$metric['metric']],
                $previous[$metric['metric']],
                $metric['metric'],
                [...$metric, 'metadata' => ['scope' => 'overall']],
            );

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        foreach (['sources' => 'acquisition', 'pages' => 'page', 'devices' => 'audience'] as $key => $category) {
            $currentItems = $current[$key];
            $previousItems = $previous[$key];
            $labels = array_unique([...array_keys($currentItems), ...array_keys($previousItems)]);

            foreach ($labels as $label) {
                $candidate = $this->comparison->compare(
                    (int) ($currentItems[$label] ?? 0),
                    (int) ($previousItems[$label] ?? 0),
                    $key === 'pages' ? 'pageviews' : ($key === 'sources' ? 'visits' : 'visitors'),
                    [
                        'category' => $category,
                        'label' => (string) $label,
                        'sample' => (int) ($currentItems[$label] ?? 0) + (int) ($previousItems[$label] ?? 0),
                        'minimum_percentage' => config('analytics.change_detection.minimum_dimension_percentage', 30.0),
                        'minimum_combined_count' => 20,
                        'metadata' => ['dimension' => $key, 'value' => $label],
                    ],
                );

                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        foreach ([
            ['metric' => 'conversions', 'category' => 'conversion', 'label' => 'Conversions', 'sample' => $current['sessions'] + $previous['sessions']],
            ['metric' => 'conversionRate', 'category' => 'conversion', 'label' => 'Conversion rate', 'sample' => $current['sessions'] + $previous['sessions'], 'is_rate' => true],
        ] as $metric) {
            $candidate = $this->comparison->compare(
                $current[$metric['metric']],
                $previous[$metric['metric']],
                $metric['metric'],
                [...$metric, 'metadata' => ['scope' => 'overall']],
            );

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        if ($filters === []) {
            foreach ($project->funnels()->where('is_active', true)->with('steps')->get() as $funnel) {
                $currentFunnel = $this->funnelAnalytics->summary($funnel, $from, $to);
                $previousFunnel = $this->funnelAnalytics->summary($funnel, $previousFrom, $previousTo);
                $currentUsers = (int) ($currentFunnel['steps'][0]['users'] ?? 0);
                $previousUsers = (int) ($previousFunnel['steps'][0]['users'] ?? 0);
                $candidate = $this->comparison->compare(
                    $currentFunnel['conversionRate'],
                    $previousFunnel['conversionRate'],
                    'conversionRate',
                    [
                        'category' => 'funnel',
                        'label' => $funnel->name.' funnel',
                        'is_rate' => true,
                        'sample' => $currentUsers + $previousUsers,
                        'metadata' => ['dimension' => 'funnel', 'value' => $funnel->name, 'funnel_id' => $funnel->getKey()],
                    ],
                );

                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        $candidates = $this->insightPriority->sort($candidates);
        $candidates = array_slice($candidates, 0, (int) config('analytics.change_detection.max_candidates', 5));

        $results = [];

        foreach ($candidates as $candidate) {
            $fingerprint = sha1(implode('|', [
                $project->getKey(),
                $candidate['category'],
                $candidate['metric'],
                data_get($candidate, 'metadata.value', 'overall'),
                $from->toIso8601String(),
                $to->toIso8601String(),
            ]));
            $direction = $candidate['direction'];
            $label = $candidate['label'];
            $percentage = $candidate['percentage_change'];
            $summary = $this->summary($label, $direction, $percentage, $candidate['current_value'], $candidate['previous_value']);
            $recommendation = $this->recommendation($candidate);
            $payload = [
                ...$candidate,
                'fingerprint' => $fingerprint,
                'summary' => $summary,
                'explanation' => $this->explanation($candidate),
                'recommendation' => $recommendation,
                'period_start' => $from->toIso8601String(),
                'period_end' => $to->toIso8601String(),
            ];

            $record = null;

            if ($persist) {
                $record = Insight::query()->firstOrNew([
                    'project_id' => $project->getKey(),
                    'fingerprint' => $fingerprint,
                    'period_start' => $from,
                    'period_end' => $to,
                ]);
                $existingMetadata = (array) $record->metadata;
                $isAiEnhanced = (bool) ($existingMetadata['ai_enhanced'] ?? false);
                $record->fill([
                    'category' => $candidate['category'],
                    'type' => $candidate['category'].'_change',
                    'severity' => $candidate['severity'],
                    'metric' => $candidate['metric'],
                    'current_value' => $candidate['current_value'],
                    'previous_value' => $candidate['previous_value'],
                    'percentage_change' => $percentage,
                    'confidence' => $candidate['confidence'],
                    'summary' => $summary,
                    'explanation' => $isAiEnhanced ? $record->explanation : $payload['explanation'],
                    'recommendation' => $isAiEnhanced ? $record->recommendation : $recommendation,
                    'metadata' => [
                        ...$candidate['metadata'],
                        ...($isAiEnhanced ? [
                            'ai_enhanced' => true,
                            'ai_generated_at' => $existingMetadata['ai_generated_at'] ?? null,
                            'ai_priority' => $existingMetadata['ai_priority'] ?? null,
                            'ai_confidence_note' => $existingMetadata['ai_confidence_note'] ?? null,
                        ] : []),
                    ],
                    'generated_at' => now(),
                    'expires_at' => now()->addDays(2),
                ]);
                $record->save();

                if ($record->dismissed_at !== null) {
                    continue;
                }

                $payload['id'] = (int) $record->getKey();
                $payload['explanation'] = (string) $record->explanation;
                $payload['recommendation'] = (string) $record->recommendation;
                $payload['ai_enhanced'] = $isAiEnhanced;
                $payload['ai_generated_at'] = $isAiEnhanced
                    ? ($existingMetadata['ai_generated_at'] ?? null)
                    : null;
                $payload['actions'] = $this->insightActions->actions($record);
            }

            $results[] = $payload;
        }

        return $results;
    }

    /**
     * @return array<int, Insight>
     */
    public function active(Project $project, int $limit = 5): array
    {
        return Insight::query()
            ->where('project_id', $project->getKey())
            ->whereNull('dismissed_at')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('period_end')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function snapshot(Project $project, CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        $events = AnalyticsEvent::query()
            ->where('project_id', $project->getKey())
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()]);
        $sessions = AnalyticsSession::query()
            ->where('project_id', $project->getKey())
            ->whereBetween('started_at', [$from->utc(), $to->utc()]);
        $this->applyEventFilters($events, $filters);
        $this->applySessionFilters($sessions, $filters);
        $pageviews = (clone $events)->where('event_name', 'page_view')->count();
        $visitors = (clone $events)->where('event_name', 'page_view')->distinct('visitor_id')->count('visitor_id');
        $sessionRows = (clone $sessions)->get(['referrer_host', 'utm_source', 'utm_medium', 'entry_path', 'device']);
        $sources = [];
        $devices = [];
        foreach ($sessionRows as $session) {
            $source = $this->sourceGrouping->classify($session->referrer_host, $session->utm_source, $session->utm_medium)['source'];
            $sources[$source] = ($sources[$source] ?? 0) + 1;
            $device = $session->device ?: 'Unknown';
            $devices[$device] = ($devices[$device] ?? 0) + 1;
        }
        $pages = (clone $events)->where('event_name', 'page_view')->select('path')->selectRaw('COUNT(*) AS total')->groupBy('path')->pluck('total', 'path')->map(fn ($value): int => (int) $value)->all();
        $conversions = $this->conversionCount($project, $from, $to, $filters);
        $sessionsCount = (int) (clone $sessions)->count();

        return [
            'pageviews' => $pageviews,
            'visitors' => $visitors,
            'sessions' => $sessionsCount,
            'conversions' => (int) $conversions,
            'conversionRate' => $sessionsCount > 0 ? round(($conversions / $sessionsCount) * 100, 2) : 0.0,
            'sources' => $sources,
            'pages' => $pages,
            'devices' => $devices,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function conversionCount(Project $project, CarbonImmutable $from, CarbonImmutable $to, array $filters): int
    {
        $query = DB::table('goal_conversions as conversions')
            ->where('conversions.project_id', $project->getKey())
            ->whereBetween('conversions.occurred_at', [$from->utc(), $to->utc()]);

        if ($filters !== []) {
            $query->join('analytics_sessions as sessions', function ($join): void {
                $join->on('sessions.session_id', '=', 'conversions.session_id')
                    ->on('sessions.project_id', '=', 'conversions.project_id');
            });
            $this->applyQuerySessionFilters($query, $filters);
        }

        return (int) $query->count('conversions.id');
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

    /**
     * @param  Builder<AnalyticsEvent>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyEventFilters(Builder $query, array $filters): void
    {
        foreach (['country', 'device', 'browser', 'referrer_host', 'utm_campaign'] as $field) {
            $filter = $field === 'referrer_host' ? ($filters['referrer'] ?? null) : ($filters[$field] ?? null);
            if ($filter !== null && $filter !== '') {
                $query->where($field, $filter);
            }
        }
        if (! empty($filters['page'])) {
            $query->where('path', $filters['page']);
        }
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

    private function summary(string $label, string $direction, ?float $percentage, int|float $current, int|float $previous): string
    {
        if ($percentage === null) {
            return "{$label} first appeared with ".number_format($current, 0).' (previously '.number_format($previous, 0).').';
        }

        return sprintf('%s %s %s%% (%s vs %s).', $label, $direction, abs($percentage), number_format($current, 0), number_format($previous, 0));
    }

    /** @param array<string, mixed> $candidate */
    private function explanation(array $candidate): string
    {
        $dimension = data_get($candidate, 'metadata.dimension');

        return $dimension === null
            ? 'This comparison uses the current period against the previous equivalent period.'
            : 'The change is concentrated in the '.Str::lower((string) $candidate['label']).' '.$dimension.' segment.';
    }

    /** @param array<string, mixed> $candidate */
    private function recommendation(array $candidate): string
    {
        return match ($candidate['category']) {
            'acquisition' => 'Check the landing page and campaign messaging for this source before changing anything.',
            'page' => 'Review recent content, links, and page changes for this path.',
            'audience' => 'Check the experience for this device segment, especially navigation and primary calls to action.',
            default => 'Review the source and page changes behind this movement, then measure the next equivalent period.',
        };
    }
}
