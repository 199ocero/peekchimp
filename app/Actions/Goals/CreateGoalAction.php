<?php

namespace App\Actions\Goals;

use App\Jobs\BackfillGoalConversions;
use App\Models\Goal;
use App\Models\Project;

class CreateGoalAction
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{goal: Goal, created: bool}
     */
    public function handle(Project $project, array $data): array
    {
        $attributes = $this->normalized($data);
        $existing = $project->goals()
            ->where('type', $attributes['type'])
            ->when(
                $attributes['type'] === 'event',
                fn ($query) => $query->where('event_name', $attributes['event_name']),
                fn ($query) => $query
                    ->where('path', $attributes['path'])
                    ->where('path_operator', $attributes['path_operator']),
            )
            ->get()
            ->first(fn (Goal $goal): bool => $this->normalizedProperties($goal->property_match) === $attributes['property_match']);

        if ($existing instanceof Goal) {
            return ['goal' => $existing, 'created' => false];
        }

        $goal = $project->goals()->create($attributes);
        BackfillGoalConversions::dispatch($goal)->afterCommit();

        return ['goal' => $goal, 'created' => true];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, type: string, event_name: string|null, path: string|null, path_operator: string, property_match: array<string, bool|float|int|string>|null, is_active: bool}
     */
    private function normalized(array $data): array
    {
        $type = (string) $data['type'];

        return [
            'name' => trim((string) $data['name']),
            'type' => $type,
            'event_name' => $type === 'event' ? trim((string) $data['event_name']) : null,
            'path' => $type === 'url' ? trim((string) $data['path']) : null,
            'path_operator' => $type === 'url' ? (string) ($data['path_operator'] ?? 'exact') : 'exact',
            'property_match' => $this->normalizedProperties($data['property_match'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @return array<string, bool|float|int|string>|null
     */
    private function normalizedProperties(mixed $properties): ?array
    {
        if (! is_array($properties)) {
            return null;
        }

        $normalizedProperties = [];

        foreach ($properties as $key => $value) {
            if (is_scalar($value)) {
                $normalizedProperties[(string) $key] = $value;
            }
        }

        $properties = $normalizedProperties;
        ksort($properties);

        return $properties === [] ? null : $properties;
    }
}
