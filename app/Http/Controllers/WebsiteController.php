<?php

namespace App\Http\Controllers;

use App\Actions\Websites\CreateWebsiteAction;
use App\Actions\Websites\SelectCurrentWebsiteAction;
use App\Actions\Websites\UpdateWebsiteAction;
use App\Http\Requests\StoreWebsiteRequest;
use App\Http\Requests\UpdateWebsiteRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\Websites\CurrentWebsiteResolver;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteController extends Controller
{
    public function __construct(private readonly CurrentWebsiteResolver $websiteResolver) {}

    public function create(Request $request): Response
    {
        return $this->setupPage($request, null, 'create');
    }

    public function store(StoreWebsiteRequest $request, CreateWebsiteAction $createWebsite): RedirectResponse
    {
        $project = $createWebsite->handle($request->user(), $request->website());

        return to_route('websites.setup', $project);
    }

    public function setup(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        return $this->setupPage($request, $project, 'update');
    }

    public function update(
        UpdateWebsiteRequest $request,
        Project $project,
        UpdateWebsiteAction $updateWebsite,
    ): RedirectResponse {
        $updateWebsite->handle($project, $request->website());

        return to_route('websites.setup', $project);
    }

    public function select(
        Request $request,
        Project $project,
        SelectCurrentWebsiteAction $selectCurrentWebsite,
    ): RedirectResponse {
        Gate::authorize('select', $project);
        $selectCurrentWebsite->handle($request->user(), $project);

        return to_route('dashboard');
    }

    private function setupPage(Request $request, ?Project $project, string $mode): Response
    {
        /** @var User $user */
        $user = $request->user();
        $domain = $project === null ? null : $project->loadMissing('domains')->domains->first();

        return Inertia::render('onboarding/Index', [
            'website' => $project === null ? null : [
                'id' => $project->getKey(),
                'name' => $project->name,
                'url' => $domain === null ? '' : 'https://'.$domain->domain,
                'domain' => $domain?->domain,
                'timezone' => $project->timezone,
                'siteKey' => $project->site_key,
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
            'defaultTimezone' => config('app.timezone', 'UTC'),
            'trackerUrl' => asset('a.js'),
            'isVerified' => (bool) $domain?->is_verified,
            'mode' => $mode,
            'backToDashboard' => $this->websiteResolver->resolve($user) !== null,
        ]);
    }
}
