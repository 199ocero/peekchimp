<?php

namespace App\Mcp\Concerns;

use App\Models\Project;
use App\Models\User;
use App\Services\Mcp\McpProjectResolver;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait UsesMcpProject
{
    protected function project(Request $request): Project|Response
    {
        $user = $request->user();
        $projectId = $request->get('project_id');

        if (! $user instanceof User) {
            return Response::error('Authentication is required.');
        }

        if (! is_numeric($projectId) || (int) $projectId < 1) {
            return Response::error('A valid project_id is required.');
        }

        $project = app(McpProjectResolver::class)->find($user, (int) $projectId);

        return $project ?? Response::error('The requested website is not available to this account.');
    }
}
