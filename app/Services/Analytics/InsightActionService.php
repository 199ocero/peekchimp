<?php

namespace App\Services\Analytics;

use App\Contracts\Analytics\InsightActionProvider;
use App\Models\Insight;
use App\Models\InsightActionAttempt;
use App\Models\User;

class InsightActionService
{
    public function __construct(private readonly InsightActionProvider $provider) {}

    /** @return array{status: string, redirect: string|null, metadata: array<string, mixed>} */
    public function execute(string $key, Insight $insight, User $user): array
    {
        $result = $this->provider->execute($key, $insight, $user);
        InsightActionAttempt::query()->create([
            'insight_id' => $insight->getKey(),
            'user_id' => $user->getKey(),
            'action_key' => $key,
            'status' => $result['status'],
            'metadata' => $result['metadata'],
            'acted_at' => now(),
        ]);

        if ($key === 'mark_done' && $result['status'] === 'completed') {
            $insight->forceFill(['dismissed_at' => now()])->save();
        }

        return $result;
    }

    /** @return array<int, array{key: string, label: string, description: string}> */
    public function actions(Insight $insight): array
    {
        return $this->provider->actions($insight);
    }
}
