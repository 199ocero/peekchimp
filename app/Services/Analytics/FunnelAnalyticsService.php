<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Funnel;
use App\Models\FunnelStep;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class FunnelAnalyticsService
{
    /**
     * @return array{steps: array<int, array<string, mixed>>, conversionRate: float}
     */
    public function summary(Funnel $funnel, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $analysis = $this->analysis($funnel, $from, $to);

        return $this->summaryFromAnalysis($analysis);
    }

    /**
     * @return array{steps: array<int, array<string, mixed>>, largestDropOff: array<string, mixed>|null, segments: array<int, array<string, mixed>>}
     */
    public function investigate(Funnel $funnel, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $analysis = $this->analysis($funnel, $from, $to);
        $summary = $this->summaryFromAnalysis($analysis);
        $largest = collect($summary['steps'])->sortByDesc('dropOff')->first();

        if ($largest === null || $largest['dropOff'] === 0) {
            return ['steps' => $summary['steps'], 'largestDropOff' => null, 'segments' => []];
        }

        $stepIndex = (int) $largest['position'] - 1;
        $eligibleSessionIds = array_keys(array_filter($analysis['reached'], fn (int $reached): bool => $reached > $stepIndex));
        $progressedSessionIds = array_keys(array_filter($analysis['reached'], fn (int $reached): bool => $reached > $stepIndex + 1));
        $sessions = $analysis['sessions']->keyBy('session_id');
        $segments = [];
        foreach (['device', 'browser', 'utm_source', 'utm_campaign'] as $dimension) {
            $groups = [];
            foreach ($eligibleSessionIds as $sessionId) {
                $value = (string) ($sessions->get($sessionId)?->{$dimension} ?: 'unknown');
                $groups[$value][] = $sessionId;
            }
            foreach ($groups as $value => $segmentSessionIds) {
                $otherSessionIds = array_values(array_diff($eligibleSessionIds, $segmentSessionIds));
                if (count($segmentSessionIds) < 20 || count($otherSessionIds) < 20) {
                    continue;
                }
                $segmentProgressed = count(array_intersect($segmentSessionIds, $progressedSessionIds));
                $otherProgressed = count(array_intersect($otherSessionIds, $progressedSessionIds));
                $segmentRate = ($segmentProgressed / count($segmentSessionIds)) * 100;
                $otherRate = ($otherProgressed / count($otherSessionIds)) * 100;
                $gap = round($otherRate - $segmentRate, 1);
                if ($gap < 15) {
                    continue;
                }
                $segments[] = [
                    'dimension' => $dimension,
                    'value' => $value,
                    'entrants' => count($segmentSessionIds),
                    'progressed' => $segmentProgressed,
                    'progressionRate' => round($segmentRate, 1),
                    'otherProgressionRate' => round($otherRate, 1),
                    'gap' => $gap,
                ];
            }
        }
        usort($segments, fn (array $left, array $right): int => $right['gap'] <=> $left['gap']);

        return ['steps' => $summary['steps'], 'largestDropOff' => $largest, 'segments' => array_slice($segments, 0, 10)];
    }

    /**
     * @return array{steps: Collection<int, FunnelStep>, counts: array<int, int>, reached: array<string, int>, sessions: Collection<int, AnalyticsSession>}
     */
    private function analysis(Funnel $funnel, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sessions = AnalyticsSession::query()
            ->where('project_id', $funnel->project_id)
            ->whereBetween('started_at', [$from->utc(), $to->utc()])
            ->get(['session_id', 'device', 'browser', 'utm_source', 'utm_campaign']);
        $sessionIds = $sessions->pluck('session_id')->map(fn ($id): string => (string) $id)->values()->all();
        $events = AnalyticsEvent::query()
            ->where('project_id', $funnel->project_id)
            ->whereIn('session_id', $sessionIds)
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->orderBy('occurred_at')
            ->get(['session_id', 'event_name', 'path', 'occurred_at'])
            ->groupBy('session_id');
        $steps = $funnel->relationLoaded('steps') ? $funnel->steps : $funnel->steps()->get();
        $counts = array_fill(0, $steps->count(), 0);
        $reached = [];

        foreach ($sessionIds as $sessionId) {
            $cursor = null;
            $reached[$sessionId] = 0;
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
                $reached[$sessionId] = $index + 1;
                $cursor = $match->occurred_at instanceof CarbonImmutable
                    ? $match->occurred_at
                    : CarbonImmutable::parse((string) $match->occurred_at);
            }
        }

        return compact('steps', 'counts', 'reached', 'sessions');
    }

    /**
     * @param  array{steps: Collection<int, FunnelStep>, counts: array<int, int>}  $analysis
     * @return array{steps: array<int, array<string, mixed>>, conversionRate: float}
     */
    private function summaryFromAnalysis(array $analysis): array
    {
        $counts = $analysis['counts'];
        $steps = $analysis['steps'];
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

        return ['steps' => $resultSteps, 'conversionRate' => $first > 0 && $counts !== [] ? round((($counts[array_key_last($counts)] ?? 0) / $first) * 100, 1) : 0.0];
    }
}
