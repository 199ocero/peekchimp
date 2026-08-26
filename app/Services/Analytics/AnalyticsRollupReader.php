<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsRollup;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class AnalyticsRollupReader
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array{label: string, pageviews: int, visitors: int}>|null
     */
    public function timeSeries(Project $project, CarbonImmutable $from, CarbonImmutable $to, string $granularity, array $filters = []): ?array
    {
        if ($filters !== [] || ! config('analytics.rollups.enabled', true)) {
            return null;
        }

        $closedAt = CarbonImmutable::now('UTC')->subMinutes((int) config('analytics.rollups.closed_after_minutes', 60));

        if ($to->gt($closedAt)) {
            return null;
        }

        $rows = AnalyticsRollup::query()
            ->where('project_id', $project->getKey())
            ->where('granularity', $granularity)
            ->where('dimension', 'overall')
            ->whereBetween('bucket_start', [$from->utc(), $to->utc()])
            ->orderBy('bucket_start')
            ->get(['bucket_start', 'pageviews', 'visitors']);
        $expected = $this->bucketCount($from, $to, $granularity);

        if ($rows->count() < $expected) {
            return null;
        }

        return $rows->map(function (AnalyticsRollup $row) use ($project, $granularity): array {
            $bucketStart = $row->getAttribute('bucket_start');
            $bucketStart = $bucketStart instanceof CarbonInterface
                ? CarbonImmutable::instance($bucketStart)
                : CarbonImmutable::parse((string) $bucketStart, 'UTC');

            return [
                'label' => $bucketStart->setTimezone($project->timezone)->format($granularity === 'hour' ? 'g A' : 'M j'),
                'pageviews' => (int) $row->getAttribute('pageviews'),
                'visitors' => (int) $row->getAttribute('visitors'),
            ];
        })->values()->all();
    }

    private function bucketCount(CarbonImmutable $from, CarbonImmutable $to, string $granularity): int
    {
        $step = $granularity === 'hour' ? 'addHour' : 'addDay';
        $cursor = $granularity === 'hour' ? $from->startOfHour() : $from->startOfDay();
        $end = $granularity === 'hour' ? $to->startOfHour() : $to->startOfDay();
        $count = 0;

        while ($cursor->lte($end)) {
            $count++;
            $cursor = $cursor->{$step}();
        }

        return $count;
    }
}
