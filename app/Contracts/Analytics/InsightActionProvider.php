<?php

namespace App\Contracts\Analytics;

use App\Models\Insight;
use App\Models\User;

interface InsightActionProvider
{
    /** @return array<int, array{key: string, label: string, description: string}> */
    public function actions(Insight $insight): array;

    /** @return array{status: string, redirect: string|null, metadata: array<string, mixed>} */
    public function execute(string $key, Insight $insight, User $user): array;
}
