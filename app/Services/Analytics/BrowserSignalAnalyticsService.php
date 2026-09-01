<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BrowserSignalAnalyticsService
{
    /** @var array<int, string> */
    private const SIGNAL_EVENT_NAMES = [
        'autocapture.click',
        'autocapture.submit',
        'web_vital.lcp',
        'browser_error',
        'request_failure',
    ];

    private const MIN_SESSIONS = 20;

    private const MIN_AFFECTED_SESSIONS = 10;

    /** @return array<string, mixed> */
    public function collectionStatus(Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = $project->events()
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->whereIn('event_name', self::SIGNAL_EVENT_NAMES);
        $eventTypes = (clone $query)
            ->select('event_name')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('event_name')
            ->pluck('count', 'event_name')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
        $signalCount = array_sum($eventTypes);
        $lastSignalAt = (clone $query)->latest('occurred_at')->value('occurred_at');
        $status = ! $project->isAutocaptureEnabled()
            ? 'disabled'
            : ($signalCount === 0 ? 'no_data' : 'receiving');

        return [
            'status' => $status,
            'enabled' => $project->isAutocaptureEnabled(),
            'stored' => $signalCount > 0,
            'signalCount' => $signalCount,
            'sessionCount' => (int) (clone $query)->distinct('session_id')->count('session_id'),
            'pageviewSessions' => $this->pageviewSessions($project, $from, $to),
            'eventTypes' => $eventTypes,
            'lastSignalAt' => $lastSignalAt === null ? null : CarbonImmutable::parse((string) $lastSignalAt)->toIso8601String(),
            'evidenceRef' => 'analytics:signals:collection:'.$from->toDateString().':'.$to->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    public function page(Project $project, string $path, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $events = $this->events($project, $from, $to, $path);
        $pageviewSessions = $this->pageviewSessions($project, $from, $to, $path);

        return [
            ...$this->summary($events, $pageviewSessions),
            'status' => $events->isEmpty() ? 'no_data' : ($pageviewSessions < self::MIN_SESSIONS ? 'insufficient_data' : 'ok'),
            'sampleSessions' => $pageviewSessions,
            'evidenceRef' => 'analytics:signals:page:'.$path.':'.$from->toDateString().':'.$to->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    public function site(Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $events = $this->events($project, $from, $to);
        $pageviewSessions = $this->pageviewSessions($project, $from, $to);

        return [
            ...$this->summary($events, $pageviewSessions),
            'status' => $events->isEmpty() ? 'no_data' : ($pageviewSessions < self::MIN_SESSIONS ? 'insufficient_data' : 'ok'),
            'sampleSessions' => $pageviewSessions,
            'evidenceRef' => 'analytics:signals:site:'.$from->toDateString().':'.$to->toDateString(),
        ];
    }

    /** @return Collection<int, AnalyticsEvent> */
    private function events(Project $project, CarbonImmutable $from, CarbonImmutable $to, ?string $path = null): Collection
    {
        return $project->events()
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->whereIn('event_name', self::SIGNAL_EVENT_NAMES)
            ->when($path !== null, fn ($query) => $query->where('path', $path))
            ->get(['session_id', 'event_name', 'path', 'device', 'browser', 'properties', 'occurred_at']);
    }

    private function pageviewSessions(Project $project, CarbonImmutable $from, CarbonImmutable $to, ?string $path = null): int
    {
        return (int) $project->events()
            ->where('event_name', 'page_view')
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->when($path !== null, fn ($query) => $query->where('path', $path))
            ->distinct('session_id')
            ->count('session_id');
    }

    /**
     * @param  Collection<int, AnalyticsEvent>  $events
     * @return array<string, mixed>
     */
    private function summary(Collection $events, int $pageviewSessions): array
    {
        $clicks = $events->where('event_name', 'autocapture.click');
        $submits = $events->where('event_name', 'autocapture.submit');
        $submitAttempts = $clicks->filter(fn (AnalyticsEvent $event): bool => ($this->eventProperties($event)['kind'] ?? null) === 'submit');
        $submittedTimes = $submits->groupBy('session_id')->map(
            fn (Collection $sessionEvents): array => $sessionEvents->map(fn (AnalyticsEvent $event): int => $this->eventTime($event))->all(),
        );
        $abandonedSubmitSessions = $submitAttempts->groupBy('session_id')->filter(
            fn (Collection $attempts, string $sessionId): bool => ! $this->hasLaterSubmit($attempts, $submittedTimes->get($sessionId, [])),
        );
        $topTargets = $clicks->groupBy(fn (AnalyticsEvent $event): string => (string) ($this->eventProperties($event)['target'] ?? $this->eventProperties($event)['tag'] ?? 'unknown'))
            ->map(function (Collection $targetEvents, string $target): array {
                return ['target' => $target, 'events' => $targetEvents->count(), 'sessions' => $targetEvents->pluck('session_id')->unique()->count()];
            })
            ->filter(fn (array $target): bool => $target['sessions'] >= self::MIN_AFFECTED_SESSIONS)
            ->sortByDesc('sessions')
            ->take(10)
            ->values()
            ->all();
        $lcpEvents = $events->where('event_name', 'web_vital.lcp');
        $failureEvents = $events->whereIn('event_name', ['browser_error', 'request_failure']);
        $affectedSessions = $failureEvents->pluck('session_id')->unique()->count();

        return [
            'behavior' => [
                'clicks' => ['events' => $clicks->count(), 'sessions' => $clicks->pluck('session_id')->unique()->count()],
                'submits' => ['events' => $submits->count(), 'sessions' => $submits->pluck('session_id')->unique()->count()],
                'submitAttempts' => $submitAttempts->pluck('session_id')->unique()->count(),
                'submitAttemptsWithoutSubmission' => $abandonedSubmitSessions->count(),
                'topTargets' => $topTargets,
            ],
            'performance' => [
                'lcp' => $this->metric($lcpEvents, 'value_ms'),
                'segments' => $this->segments($lcpEvents, 'value_ms'),
            ],
            'failures' => [
                'affectedSessions' => $affectedSessions,
                'rate' => $pageviewSessions > 0 ? round(($affectedSessions / $pageviewSessions) * 100, 1) : null,
                'browserErrors' => $this->failureGroups($events->where('event_name', 'browser_error')),
                'requestFailures' => $this->failureGroups($events->where('event_name', 'request_failure')),
            ],
        ];
    }

    /**
     * @param  Collection<int, AnalyticsEvent>  $attempts
     * @param  array<int, int>  $submittedAt
     */
    private function hasLaterSubmit(Collection $attempts, array $submittedAt): bool
    {
        foreach ($attempts as $attempt) {
            foreach ($submittedAt as $timestamp) {
                if ($timestamp >= $this->eventTime($attempt)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, AnalyticsEvent>  $events
     * @return array{samples: int, p75Ms: int|null}
     */
    private function metric(Collection $events, string $property): array
    {
        $values = [];
        foreach ($events as $event) {
            $value = $this->eventProperties($event)[$property] ?? null;
            if (is_numeric($value)) {
                $values[] = (float) $value;
            }
        }
        sort($values);

        return [
            'samples' => count($values),
            'p75Ms' => $values === [] ? null : (int) ceil($values[(int) floor((count($values) - 1) * 0.75)]),
        ];
    }

    /**
     * @param  Collection<int, AnalyticsEvent>  $events
     * @return array<int, array<string, mixed>>
     */
    private function segments(Collection $events, string $property): array
    {
        $segments = [];
        foreach (['device', 'browser'] as $dimension) {
            $groups = $events->groupBy(fn (AnalyticsEvent $event): string => $this->eventDimension($event, $dimension));
            foreach ($groups as $value => $dimensionEvents) {
                $sessions = $dimensionEvents->pluck('session_id')->unique()->count();
                if ($sessions < self::MIN_SESSIONS) {
                    continue;
                }
                $segments[] = ['dimension' => $dimension, 'value' => (string) $value, ...$this->metric($dimensionEvents, $property), 'sessions' => $sessions];
            }
        }

        usort($segments, fn (array $left, array $right): int => $right['sessions'] <=> $left['sessions']);

        return array_slice($segments, 0, 10);
    }

    /**
     * @param  Collection<int, AnalyticsEvent>  $events
     * @return array<int, array<string, mixed>>
     */
    private function failureGroups(Collection $events): array
    {
        return $events->groupBy(function (AnalyticsEvent $event): string {
            $properties = $this->eventProperties($event);

            return (string) ($properties['fingerprint'] ?? $properties['error_type'] ?? $properties['request_path'] ?? 'unknown');
        })->map(function (Collection $failureEvents, string $fingerprint): array {
            $properties = $this->eventProperties($failureEvents->first());

            return [
                'fingerprint' => $fingerprint,
                'path' => $properties['script_path'] ?? $properties['request_path'] ?? null,
                'method' => $properties['method'] ?? null,
                'status' => $properties['status'] ?? null,
                'events' => $failureEvents->count(),
                'sessions' => $failureEvents->pluck('session_id')->unique()->count(),
            ];
        })->filter(fn (array $failure): bool => $failure['sessions'] >= self::MIN_AFFECTED_SESSIONS)
            ->sortByDesc('sessions')
            ->take(10)
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function eventProperties(AnalyticsEvent $event): array
    {
        $properties = $event->getAttribute('properties');

        return is_array($properties) ? $properties : [];
    }

    private function eventTime(AnalyticsEvent $event): int
    {
        $occurredAt = $event->getAttribute('occurred_at');

        return $occurredAt instanceof CarbonImmutable ? $occurredAt->getTimestamp() : CarbonImmutable::parse((string) $occurredAt)->getTimestamp();
    }

    private function eventDimension(AnalyticsEvent $event, string $dimension): string
    {
        $value = $event->getAttribute($dimension);

        return is_string($value) && $value !== '' ? $value : 'unknown';
    }
}
