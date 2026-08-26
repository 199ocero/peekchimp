<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\Goal;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class GoalConversionService
{
    public function backfill(Goal $goal, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): int
    {
        $project = $goal->project()->first();
        if ($project === null) {
            return 0;
        }

        $to ??= CarbonImmutable::now('UTC');
        $from ??= $to->subDays((int) config('analytics.retention_days', 90));
        $recorded = 0;

        AnalyticsEvent::query()
            ->where('project_id', $project->getKey())
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->orderBy('id')
            ->chunkById(1000, function ($events) use ($project, $goal, &$recorded): void {
                foreach ($events as $event) {
                    $recorded += $this->record($project, (string) $event->getAttribute('session_id'), [
                        'event_id' => $event->getAttribute('event_id'),
                        'event_name' => $event->getAttribute('event_name'),
                        'path' => $event->getAttribute('path'),
                        'properties' => $event->getAttribute('properties'),
                        'occurred_at' => $event->getAttribute('occurred_at'),
                    ], $goal);
                }
            });

        return $recorded;
    }

    /**
     * Record at most one conversion for each goal and privacy-safe session.
     *
     * @param  array<string, mixed>  $event
     */
    public function record(Project $project, string $sessionId, array $event, ?Goal $onlyGoal = null): int
    {
        $eventName = (string) ($event['event_name'] ?? '');
        $path = is_string($event['path'] ?? null) ? $event['path'] : null;
        $properties = is_array($event['properties'] ?? null) ? $event['properties'] : [];
        $eventId = (string) ($event['event_id'] ?? '');
        $occurredAt = ($event['occurred_at'] ?? null) instanceof CarbonImmutable
            ? $event['occurred_at']
            : CarbonImmutable::parse((string) ($event['occurred_at'] ?? now()));
        $eventRowId = null;
        $recorded = 0;

        ($onlyGoal === null
            ? Goal::query()
                ->where('project_id', $project->getKey())
                ->where('is_active', true)
                ->get()
            : ($onlyGoal->is_active ? collect([$onlyGoal]) : collect()))
            ->each(function (Goal $goal) use ($project, $sessionId, $eventName, $path, $properties, $occurredAt, $eventId, &$eventRowId, &$recorded): void {
                if (! $this->matches($goal, $eventName, $path, $properties)) {
                    return;
                }

                if ($eventRowId === null && $eventId !== '') {
                    $eventRowId = DB::table('events')
                        ->where('project_id', $project->getKey())
                        ->where('event_id', $eventId)
                        ->value('id');
                }

                $inserted = DB::table('goal_conversions')->insertOrIgnore([
                    'goal_id' => $goal->getKey(),
                    'project_id' => $project->getKey(),
                    'session_id' => $sessionId,
                    'event_id' => $eventRowId,
                    'occurred_at' => $occurredAt->utc()->toDateTimeString(),
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ]);

                $recorded += (int) $inserted;
            });

        return $recorded;
    }

    /** @param array<string, mixed> $properties */
    private function matches(Goal $goal, string $eventName, ?string $path, array $properties): bool
    {
        $type = $goal->type ?: 'event';

        if ($type === 'url') {
            if ($eventName !== 'page_view' || $path === null || ! is_string($goal->path)) {
                return false;
            }

            $matchesPath = ($goal->path_operator ?: 'exact') === 'prefix'
                ? str_starts_with($path, $goal->path)
                : $path === $goal->path;

            return $matchesPath && $this->matchesProperties($goal->getAttribute('property_match'), $properties);
        }

        return $eventName !== ''
            && $goal->event_name === $eventName
            && $this->matchesProperties($goal->getAttribute('property_match'), $properties);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function matchesProperties(mixed $expected, array $properties): bool
    {
        if (! is_array($expected)) {
            return true;
        }

        if ($expected === []) {
            return true;
        }

        if (count($expected) > 5) {
            return false;
        }

        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $properties) || ! is_scalar($value) || $properties[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
