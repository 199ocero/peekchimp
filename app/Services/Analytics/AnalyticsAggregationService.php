<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalyticsAggregationService
{
    public function __construct(private readonly SourceGrouping $sourceGrouping) {}

    public function rebuild(
        Project $project,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        string $granularity = 'hour',
        bool $includeOpenBuckets = false,
    ): int {
        $granularity = in_array($granularity, ['hour', 'day'], true) ? $granularity : 'hour';
        $now = CarbonImmutable::now('UTC');
        $to ??= $now;
        $from ??= $to->subDays((int) config('analytics.retention_days', 90));

        if (! $includeOpenBuckets) {
            $to = $to->min($now->subMinutes((int) config('analytics.rollups.closed_after_minutes', 60)));
        }

        if ($from->gte($to)) {
            return 0;
        }

        $aggregates = [];
        $this->collectEvents($project, $from, $to, $granularity, $aggregates);
        $this->collectSessions($project, $from, $to, $granularity, $aggregates);
        $this->collectConversions($project, $from, $to, $granularity, $aggregates);
        $this->seedOverallBuckets($project, $from, $to, $granularity, $aggregates);

        $bucketFrom = $this->bucket($from, $project, $granularity);
        $bucketTo = $this->bucket($to, $project, $granularity);

        DB::transaction(function () use ($project, $granularity, $bucketFrom, $bucketTo, $aggregates): void {
            DB::table('analytics_rollups')
                ->where('project_id', $project->getKey())
                ->where('granularity', $granularity)
                ->whereBetween('bucket_start', [$bucketFrom, $bucketTo])
                ->delete();

            foreach (array_chunk(array_values($aggregates), 500) as $chunk) {
                $rows = array_map(fn (array $aggregate): array => [
                    'project_id' => $project->getKey(),
                    'granularity' => $granularity,
                    'bucket_start' => $aggregate['bucket_start'],
                    'dimension' => $aggregate['dimension'],
                    'dimension_value' => $aggregate['dimension_value'],
                    'pageviews' => $aggregate['pageviews'],
                    'visitors' => count($aggregate['visitor_ids']),
                    'visits' => $aggregate['visits'],
                    'events' => $aggregate['events'],
                    'bounces' => $aggregate['bounces'],
                    'duration_seconds' => $aggregate['duration_seconds'],
                    'conversions' => $aggregate['conversions'],
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ], $chunk);

                DB::table('analytics_rollups')->insert($rows);
            }
        });

        return count($aggregates);
    }

    /**
     * @param  array<string, array<string, mixed>>  $aggregates
     */
    private function collectEvents(Project $project, CarbonImmutable $from, CarbonImmutable $to, string $granularity, array &$aggregates): void
    {
        $internalDomains = $project->domains->pluck('domain')->all();

        AnalyticsEvent::query()
            ->where('project_id', $project->getKey())
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->orderBy('id')
            ->chunkById(1000, function ($events) use ($project, $granularity, $internalDomains, &$aggregates): void {
                foreach ($events as $event) {
                    $occurredAt = CarbonImmutable::parse((string) $event->getAttribute('occurred_at'));
                    $bucketStart = $this->bucket($occurredAt, $project, $granularity);
                    $isPageView = $event->getAttribute('event_name') === 'page_view';
                    $source = $this->sourceGrouping->classify($event->getAttribute('referrer_host'), $event->getAttribute('utm_source'), $event->getAttribute('utm_medium'), $internalDomains);

                    $dimensions = [
                        ['overall', ''],
                        ['event', $event->getAttribute('event_name')],
                        ['page', $event->getAttribute('path')],
                        ['referrer', $event->getAttribute('referrer_host') ?: 'Direct'],
                        ['source', $source['source']],
                        ['category', $source['category']],
                        ['country', $event->getAttribute('country') ?: 'Unknown'],
                        ['device', $event->getAttribute('device') ?: 'Unknown'],
                        ['browser', $event->getAttribute('browser') ?: 'Unknown'],
                        ['os', $event->getAttribute('operating_system') ?: 'Unknown'],
                        ['utm_source', $event->getAttribute('utm_source') ?: 'None'],
                        ['utm_medium', $event->getAttribute('utm_medium') ?: 'None'],
                        ['utm_campaign', $event->getAttribute('utm_campaign') ?: 'None'],
                    ];

                    foreach ($dimensions as [$dimension, $value]) {
                        if ($value === null) {
                            continue;
                        }

                        $key = $this->key($bucketStart, $dimension, (string) $value);
                        $this->ensure($aggregates, $key, $bucketStart, $dimension, (string) $value);
                        $aggregates[$key]['events']++;
                        $aggregates[$key]['pageviews'] += $isPageView ? 1 : 0;
                        if ($isPageView) {
                            $aggregates[$key]['visitor_ids'][(string) $event->getAttribute('visitor_id')] = true;
                        }
                    }
                }
            });
    }

    /**
     * @param  array<string, array<string, mixed>>  $aggregates
     */
    private function collectSessions(Project $project, CarbonImmutable $from, CarbonImmutable $to, string $granularity, array &$aggregates): void
    {
        $internalDomains = $project->domains->pluck('domain')->all();

        AnalyticsSession::query()
            ->where('project_id', $project->getKey())
            ->whereBetween('started_at', [$from->utc(), $to->utc()])
            ->orderBy('id')
            ->chunkById(1000, function ($sessions) use ($project, $granularity, $internalDomains, &$aggregates): void {
                foreach ($sessions as $session) {
                    $startedAt = CarbonImmutable::parse((string) $session->getAttribute('started_at'));
                    $bucketStart = $this->bucket($startedAt, $project, $granularity);
                    $source = $this->sourceGrouping->classify($session->getAttribute('referrer_host'), $session->getAttribute('utm_source'), $session->getAttribute('utm_medium'), $internalDomains);
                    $dimensions = [
                        ['overall', ''],
                        ['entry', $session->getAttribute('entry_path')],
                        ['exit', $session->getAttribute('exit_path')],
                        ['referrer', $session->getAttribute('referrer_host') ?: 'Direct'],
                        ['source', $source['source']],
                        ['category', $source['category']],
                        ['country', $session->getAttribute('country') ?: 'Unknown'],
                        ['device', $session->getAttribute('device') ?: 'Unknown'],
                        ['browser', $session->getAttribute('browser') ?: 'Unknown'],
                        ['os', $session->getAttribute('operating_system') ?: 'Unknown'],
                        ['utm_source', $session->getAttribute('utm_source') ?: 'None'],
                        ['utm_medium', $session->getAttribute('utm_medium') ?: 'None'],
                        ['utm_campaign', $session->getAttribute('utm_campaign') ?: 'None'],
                    ];

                    foreach ($dimensions as [$dimension, $value]) {
                        if ($value === null) {
                            continue;
                        }

                        $key = $this->key($bucketStart, $dimension, (string) $value);
                        $this->ensure($aggregates, $key, $bucketStart, $dimension, (string) $value);
                        $aggregates[$key]['visits']++;
                        $aggregates[$key]['bounces'] += $session->getAttribute('is_bounce') ? 1 : 0;
                        $aggregates[$key]['duration_seconds'] += (int) $session->getAttribute('duration_seconds');
                        if ($dimension !== 'overall') {
                            $aggregates[$key]['visitor_ids'][(string) $session->getAttribute('visitor_id')] = true;
                        }
                    }
                }
            });
    }

    /**
     * Keep conversion totals in the same time buckets as traffic rollups.
     * Detailed goal and source reporting remains available through the goal services.
     *
     * @param  array<string, array<string, mixed>>  $aggregates
     */
    private function collectConversions(Project $project, CarbonImmutable $from, CarbonImmutable $to, string $granularity, array &$aggregates): void
    {
        DB::table('goal_conversions')
            ->where('project_id', $project->getKey())
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->orderBy('id')
            ->chunkById(1000, function ($conversions) use ($project, $granularity, &$aggregates): void {
                foreach ($conversions as $conversion) {
                    $occurredAt = CarbonImmutable::parse((string) $conversion->occurred_at);
                    $bucketStart = $this->bucket($occurredAt, $project, $granularity);
                    $key = $this->key($bucketStart, 'overall', '');
                    $this->ensure($aggregates, $key, $bucketStart, 'overall', '');
                    $aggregates[$key]['conversions']++;
                }
            });
    }

    /**
     * Empty buckets are materialized so readers can safely use rollups without
     * falling back to a raw event scan whenever a quiet interval has no rows.
     *
     * @param  array<string, array<string, mixed>>  $aggregates
     */
    private function seedOverallBuckets(Project $project, CarbonImmutable $from, CarbonImmutable $to, string $granularity, array &$aggregates): void
    {
        $cursor = $granularity === 'day'
            ? $from->setTimezone($project->timezone)->startOfDay()
            : $from->setTimezone($project->timezone)->startOfHour();
        $end = $granularity === 'day'
            ? $to->setTimezone($project->timezone)->startOfDay()
            : $to->setTimezone($project->timezone)->startOfHour();

        while ($cursor->lte($end)) {
            $bucketStart = $cursor->utc();
            $key = $this->key($bucketStart, 'overall', '');
            $this->ensure($aggregates, $key, $bucketStart, 'overall', '');
            $cursor = $granularity === 'day' ? $cursor->addDay() : $cursor->addHour();
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $aggregates
     */
    private function ensure(array &$aggregates, string $key, CarbonImmutable $bucketStart, string $dimension, string $value): void
    {
        if (isset($aggregates[$key])) {
            return;
        }

        $aggregates[$key] = [
            'bucket_start' => $bucketStart,
            'dimension' => $dimension,
            'dimension_value' => Str::limit($value, 255, ''),
            'pageviews' => 0,
            'visitors' => 0,
            'visitor_ids' => [],
            'visits' => 0,
            'events' => 0,
            'bounces' => 0,
            'duration_seconds' => 0,
            'conversions' => 0,
        ];
    }

    private function key(CarbonImmutable $bucketStart, string $dimension, string $value): string
    {
        return $bucketStart->toIso8601String().'|'.$dimension.'|'.Str::limit($value, 255, '');
    }

    private function bucket(CarbonImmutable $dateTime, Project $project, string $granularity): CarbonImmutable
    {
        $local = $dateTime->setTimezone($project->timezone);
        $bucket = $granularity === 'day' ? $local->startOfDay() : $local->startOfHour();

        return $bucket->utc();
    }
}
