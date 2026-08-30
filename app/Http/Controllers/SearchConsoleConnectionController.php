<?php

namespace App\Http\Controllers;

use App\Contracts\SearchConsoleClient;
use App\Http\Requests\StoreSearchConsolePropertyRequest;
use App\Jobs\StartSearchConsoleSync;
use App\Models\Project;
use App\Models\User;
use App\Services\SearchConsole\SearchConsoleConnectionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Throwable;

class SearchConsoleConnectionController extends Controller
{
    public function store(
        StoreSearchConsolePropertyRequest $request,
        Project $project,
        SearchConsoleConnectionManager $connectionManager,
    ): RedirectResponse {
        $pending = $request->session()->get('search_console.pending');
        $payload = $this->pendingPayload($pending, $project);
        $property = null;

        foreach ($payload['properties'] ?? [] as $candidate) {
            if ($candidate['siteUrl'] === $request->siteUrl()) {
                $property = $candidate;
                break;
            }
        }

        if ($property === null || $payload === null) {
            return to_route('websites.settings.edit', $project)
                ->withErrors(['search_console' => 'The property selection expired. Connect to Google again.']);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $connectionManager->connect($project, $user, $payload['tokens'], $property);
        $request->session()->forget('search_console.pending');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Google Search Console connected. Importing the last 90 days now.']);

        return to_route('websites.settings.edit', $project);
    }

    public function sync(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('manageIntegrations', $project);
        $connection = $project->searchConsoleConnection()->firstOrFail();
        StartSearchConsoleSync::dispatch($connection->getKey());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Search Console sync queued.']);

        return to_route('websites.settings.edit', $project);
    }

    public function destroy(Request $request, Project $project, SearchConsoleClient $client): RedirectResponse
    {
        Gate::authorize('manageIntegrations', $project);
        $connection = $project->searchConsoleConnection()->firstOrFail();

        try {
            $client->revoke($connection);
        } catch (Throwable $exception) {
            report($exception);
        }

        DB::transaction(function () use ($project, $connection): void {
            $project->searchConsoleMetrics()->delete();
            $connection->delete();
        });
        Cache::forget('dashboard:search-console:'.$project->getKey());
        $request->session()->forget('search_console.pending');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Google Search Console disconnected and imported data removed.']);

        return to_route('websites.settings.edit', $project);
    }

    /**
     * @return array{tokens: array{access_token: string, refresh_token: string, expires_in: int}, properties: array<int, array{siteUrl: string, permissionLevel: string, propertyType: string, host: string}>}|null
     */
    private function pendingPayload(mixed $pending, Project $project): ?array
    {
        if (! is_array($pending)
            || (int) ($pending['project_id'] ?? 0) !== $project->getKey()
            || (int) ($pending['expires_at'] ?? 0) < now()->timestamp
            || ! is_string($pending['payload'] ?? null)) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($pending['payload']), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($payload)
                || ! is_array($payload['tokens'] ?? null)
                || ! is_string($payload['tokens']['access_token'] ?? null)
                || ! is_string($payload['tokens']['refresh_token'] ?? null)
                || ! is_numeric($payload['tokens']['expires_in'] ?? null)
                || ! is_array($payload['properties'] ?? null)) {
                return null;
            }

            $properties = [];

            foreach ($payload['properties'] as $property) {
                if (! is_array($property)
                    || ! is_string($property['siteUrl'] ?? null)
                    || ! is_string($property['permissionLevel'] ?? null)
                    || ! is_string($property['propertyType'] ?? null)
                    || ! is_string($property['host'] ?? null)) {
                    continue;
                }

                $properties[] = [
                    'siteUrl' => $property['siteUrl'],
                    'permissionLevel' => $property['permissionLevel'],
                    'propertyType' => $property['propertyType'],
                    'host' => $property['host'],
                ];
            }

            return [
                'tokens' => [
                    'access_token' => $payload['tokens']['access_token'],
                    'refresh_token' => $payload['tokens']['refresh_token'],
                    'expires_in' => (int) $payload['tokens']['expires_in'],
                ],
                'properties' => $properties,
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
