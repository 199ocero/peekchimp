<?php

namespace App\Services\Mcp;

use App\Models\Project;
use App\Models\User;

class McpProjectResolver
{
    public function find(User $user, int $projectId): ?Project
    {
        $workspaceOwner = $user->workspaceOwnerUser();

        return $workspaceOwner->projects()
            ->whereKey($projectId)
            ->where('is_active', true)
            ->first();
    }
}
