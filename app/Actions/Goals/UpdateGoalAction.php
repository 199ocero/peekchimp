<?php

namespace App\Actions\Goals;

use App\Jobs\BackfillGoalConversions;
use App\Models\Goal;
use Illuminate\Support\Facades\DB;

class UpdateGoalAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Goal $goal, array $data): Goal
    {
        return DB::transaction(function () use ($goal, $data): Goal {
            $type = (string) ($data['type'] ?? $goal->type);
            $properties = array_key_exists('property_match', $data)
                ? $this->normalizedProperties($data['property_match'])
                : $goal->property_match;

            $goal->conversions()->delete();
            $goal->update([
                ...$data,
                'type' => $type,
                'event_name' => $type === 'event' ? ($data['event_name'] ?? $goal->event_name) : null,
                'path' => $type === 'url' ? ($data['path'] ?? $goal->path) : null,
                'path_operator' => $type === 'url' ? ($data['path_operator'] ?? $goal->path_operator) : 'exact',
                'property_match' => $properties,
            ]);

            BackfillGoalConversions::dispatch($goal)->afterCommit();

            return $goal->refresh();
        });
    }

    /** @return array<string, bool|float|int|string>|null */
    private function normalizedProperties(mixed $properties): ?array
    {
        if (! is_array($properties)) {
            return null;
        }

        $normalized = array_filter($properties, 'is_scalar');
        ksort($normalized);

        return $normalized === [] ? null : $normalized;
    }
}
