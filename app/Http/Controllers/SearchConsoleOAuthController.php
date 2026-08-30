<?php

namespace App\Http\Controllers;

use App\Contracts\SearchConsoleClient;
use App\Models\Project;
use App\Services\SearchConsole\SearchConsoleConnectionManager;
use App\Services\SearchConsole\SearchConsolePropertyMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SearchConsoleOAuthController extends Controller
{
    public function __construct(
        private readonly SearchConsoleClient $client,
        private readonly SearchConsolePropertyMatcher $propertyMatcher,
        private readonly SearchConsoleConnectionManager $connectionManager,
    ) {}

    public function connect(Request $request, Project $project): Response
    {
        Gate::authorize('manageIntegrations', $project);
        $state = Str::random(64);
        $request->session()->put('search_console.oauth', [
            'state' => $state,
            'project_id' => $project->getKey(),
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        try {
            return Inertia::location($this->client->authorizationUrl($state));
        } catch (Throwable $exception) {
            report($exception);
            $request->session()->forget('search_console.oauth');

            return to_route('websites.settings.edit', $project)->withErrors([
                'search_console' => 'Google Search Console OAuth is not configured. Add the Google Cloud credentials and try again.',
            ]);
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        $oauth = $request->session()->pull('search_console.oauth');
        $project = is_array($oauth)
            ? Project::query()->find($oauth['project_id'] ?? null)
            : null;

        if (! $project instanceof Project) {
            return to_route('dashboard')->withErrors(['search_console' => 'The Google connection request expired. Please try again.']);
        }

        Gate::authorize('manageIntegrations', $project);

        if (! $this->validState($oauth, $request->string('state')->toString())) {
            return to_route('websites.settings.edit', $project)
                ->withErrors(['search_console' => 'Google returned an invalid connection state. Please try again.']);
        }

        if ($request->filled('error')) {
            return to_route('websites.settings.edit', $project)
                ->withErrors(['search_console' => 'Google Search Console access was not granted.']);
        }

        try {
            $code = $request->string('code')->toString();

            if ($code === '') {
                throw new RuntimeException('Google did not return an authorization code.');
            }

            $tokens = $this->client->exchangeAuthorizationCode($code);
            $properties = $this->propertyMatcher->matching($project, $this->client->listSites($tokens['access_token']));

            if ($properties === []) {
                return to_route('websites.settings.edit', $project)->withErrors([
                    'search_console' => 'No verified Search Console property exactly matches this website domain.',
                ]);
            }

            if (count($properties) === 1) {
                $this->connectionManager->connect($project, $request->user(), $tokens, $properties[0]);
                Inertia::flash('toast', ['type' => 'success', 'message' => 'Google Search Console connected. Importing the last 90 days now.']);

                return to_route('websites.settings.edit', $project);
            }

            $request->session()->put('search_console.pending', [
                'project_id' => $project->getKey(),
                'expires_at' => now()->addMinutes(10)->timestamp,
                'payload' => Crypt::encryptString((string) json_encode([
                    'tokens' => $tokens,
                    'properties' => $properties,
                ], JSON_THROW_ON_ERROR)),
            ]);

            return to_route('websites.settings.edit', $project);
        } catch (Throwable $exception) {
            report($exception);

            return to_route('websites.settings.edit', $project)->withErrors([
                'search_console' => 'Google Search Console could not be connected. Please try again.',
            ]);
        }
    }

    /** @param array<string, mixed> $oauth */
    private function validState(array $oauth, string $state): bool
    {
        return is_string($oauth['state'] ?? null)
            && is_numeric($oauth['expires_at'] ?? null)
            && (int) $oauth['expires_at'] >= now()->timestamp
            && $state !== ''
            && hash_equals($oauth['state'], $state);
    }
}
