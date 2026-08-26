<?php

namespace App\Services\Analytics;

use App\Contracts\Analytics\InsightActionProvider;
use App\Models\Insight;
use App\Models\User;

class InternalInsightActionProvider implements InsightActionProvider
{
    public function actions(Insight $insight): array
    {
        $actions = [[
            'key' => 'mark_done',
            'label' => 'Mark as done',
            'description' => 'Hide this recommendation after you have reviewed it.',
        ]];

        if (data_get($insight->metadata, 'dimension') === 'sources') {
            $actions[] = [
                'key' => 'open_source',
                'label' => 'Open sources',
                'description' => 'Inspect this source in the dashboard.',
            ];
        }

        return $actions;
    }

    public function execute(string $key, Insight $insight, User $user): array
    {
        return match ($key) {
            'mark_done' => [
                'status' => 'completed',
                'redirect' => route('dashboard', ['refresh' => now()->timestamp]),
                'metadata' => [],
            ],
            'open_source' => [
                'status' => 'completed',
                'redirect' => route('dashboard', ['refresh' => now()->timestamp]),
                'metadata' => [
                    'destination' => 'dashboard',
                    'source' => data_get($insight->metadata, 'value'),
                ],
            ],
            default => ['status' => 'rejected', 'redirect' => null, 'metadata' => ['reason' => 'unknown_action']],
        };
    }
}
