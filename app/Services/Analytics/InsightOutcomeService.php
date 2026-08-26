<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Insight;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class InsightOutcomeService
{
    /**
     * Compare the equivalent period after a recorded action without asserting causality.
     */
    public function evaluate(Insight $insight, CarbonImmutable $actionAt, int $days = 7): string
    {
        $periodStart = $this->dateAttribute($insight, 'period_start');
        $periodEnd = $this->dateAttribute($insight, 'period_end');
        $periodLength = max(1, $periodEnd->diffInDays($periodStart) + 1);
        $afterFrom = $actionAt->startOfDay();
        $afterTo = $afterFrom->addDays($days - 1)->endOfDay();
        $beforeFrom = $afterFrom->subDays($periodLength);
        $beforeTo = $afterFrom->subSecond();
        $metric = $insight->metric;
        $current = $this->metric($insight, $metric, $afterFrom, $afterTo);
        $previous = $this->metric($insight, $metric, $beforeFrom, $beforeTo);
        $difference = $current - $previous;

        return abs($difference) < max(1, abs($previous) * 0.05)
            ? 'no_meaningful_change'
            : ($difference > 0 ? 'improved' : 'declined');
    }

    private function dateAttribute(Insight $insight, string $attribute): CarbonImmutable
    {
        $value = $insight->getAttribute($attribute);

        return $value instanceof CarbonInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value, 'UTC');
    }

    private function metric(Insight $insight, string $metric, CarbonImmutable $from, CarbonImmutable $to): int|float
    {
        $projectId = $insight->project_id;

        return match ($metric) {
            'pageviews' => AnalyticsEvent::query()->where('project_id', $projectId)->where('event_name', 'page_view')->whereBetween('occurred_at', [$from->utc(), $to->utc()])->count(),
            'sessions', 'visits' => AnalyticsSession::query()->where('project_id', $projectId)->whereBetween('started_at', [$from->utc(), $to->utc()])->count(),
            'conversions' => DB::table('goal_conversions')->where('project_id', $projectId)->whereBetween('occurred_at', [$from->utc(), $to->utc()])->count(),
            'conversionRate' => $this->conversionRate($projectId, $from, $to),
            default => AnalyticsEvent::query()->where('project_id', $projectId)->where('event_name', 'page_view')->whereBetween('occurred_at', [$from->utc(), $to->utc()])->distinct('visitor_id')->count('visitor_id'),
        };
    }

    private function conversionRate(int $projectId, CarbonImmutable $from, CarbonImmutable $to): float
    {
        $visits = AnalyticsSession::query()
            ->where('project_id', $projectId)
            ->whereBetween('started_at', [$from->utc(), $to->utc()])
            ->count();
        $conversions = DB::table('goal_conversions')
            ->where('project_id', $projectId)
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->count();

        return $visits > 0 ? ($conversions / $visits) * 100 : 0.0;
    }
}
