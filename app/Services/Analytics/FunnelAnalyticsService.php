<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Funnel;
use Carbon\CarbonImmutable;

class FunnelAnalyticsService
{
    /**
     * @return array{steps: array<int, array<string, mixed>>, conversionRate: float}
     */
    public function summary(Funnel $funnel, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sessions = AnalyticsSession::query()
            ->where('project_id', $funnel->project_id)
            ->whereBetween('started_at', [$from->utc(), $to->utc()])
            ->get(['session_id']);
        $sessionIds = $sessions->pluck('session_id')->map(fn ($id): string => (string) $id)->values()->all();
        $events = $sessionIds === [] ? collect() : AnalyticsEvent::query()
            ->where('project_id', $funnel->project_id)
            ->whereIn('session_id', $sessionIds)
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->orderBy('occurred_at')
            ->get(['session_id', 'event_name', 'path', 'occurred_at'])
            ->groupBy('session_id');
        $steps = $funnel->relationLoaded('steps') ? $funnel->steps : $funnel->steps()->get();
        $counts = array_fill(0, $steps->count(), 0);

        foreach ($sessionIds as $sessionId) {
            $cursor = null;
            $sessionEvents = $events->get($sessionId, collect());
            foreach ($steps as $index => $step) {
                $match = $sessionEvents->first(function ($event) use ($step, $cursor): bool {
                    $occurredAt = $event->occurred_at instanceof CarbonImmutable
                        ? $event->occurred_at
                        : CarbonImmutable::parse((string) $event->occurred_at);
                    if ($cursor !== null && $occurredAt->lte($cursor)) {
                        return false;
                    }
                    if (($step->type ?: 'event') === 'url') {
                        return $event->event_name === 'page_view'
                            && is_string($step->path)
                            && (($step->path_operator ?: 'exact') === 'prefix'
                                ? str_starts_with((string) $event->path, $step->path)
                                : $event->path === $step->path);
                    }

                    return $step->event_name !== null && $event->event_name === $step->event_name;
                });
                if ($match === null) {
                    break;
                }
                $counts[$index]++;
                $cursor = $match->occurred_at instanceof CarbonImmutable
                    ? $match->occurred_at
                    : CarbonImmutable::parse((string) $match->occurred_at);
            }
        }

        $first = $counts[0] ?? 0;
        $resultSteps = [];
        foreach ($steps as $index => $step) {
            $users = $counts[$index] ?? 0;
            $next = $counts[$index + 1] ?? 0;
            $resultSteps[] = [
                'position' => (int) $step->position,
                'name' => $step->name,
                'users' => $users,
                'progressed' => $next,
                'dropOff' => max(0, $users - $next),
                'dropOffPercentage' => $users > 0 ? round((($users - $next) / $users) * 100, 1) : 0.0,
                'conversionPercentage' => $first > 0 ? round(($users / $first) * 100, 1) : 0.0,
            ];
        }

        return [
            'steps' => $resultSteps,
            'conversionRate' => $first > 0 && $counts !== [] ? round((($counts[array_key_last($counts)] ?? 0) / $first) * 100, 1) : 0.0,
        ];
    }
}
