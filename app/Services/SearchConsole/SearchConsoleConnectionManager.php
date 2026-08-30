<?php

namespace App\Services\SearchConsole;

use App\Jobs\StartSearchConsoleSync;
use App\Models\Project;
use App\Models\SearchConsoleConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SearchConsoleConnectionManager
{
    /**
     * @param  array{access_token: string, refresh_token: string, expires_in: int}  $tokens
     * @param  array{siteUrl: string, permissionLevel: string, propertyType: string, host: string}  $property
     */
    public function connect(Project $project, User $user, array $tokens, array $property): SearchConsoleConnection
    {
        $connection = DB::transaction(function () use ($project, $user, $tokens, $property): SearchConsoleConnection {
            $existing = $project->searchConsoleConnection()->first();

            if ($existing !== null && $existing->property_site_url !== $property['siteUrl']) {
                $project->searchConsoleMetrics()->delete();
            }

            return $project->searchConsoleConnection()->updateOrCreate([], [
                'connected_by_user_id' => $user->getKey(),
                'property_site_url' => $property['siteUrl'],
                'property_type' => $property['propertyType'],
                'permission_level' => $property['permissionLevel'],
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'access_token_expires_at' => now()->addSeconds($tokens['expires_in']),
                'status' => 'connected',
                'sync_batch_id' => null,
                'last_error' => null,
            ]);
        });

        StartSearchConsoleSync::dispatch($connection->getKey());

        return $connection;
    }
}
