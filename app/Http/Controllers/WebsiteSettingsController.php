<?php

namespace App\Http\Controllers;

use App\Actions\Websites\UpdateWebsiteSettingsAction;
use App\Http\Requests\UpdateWebsiteSettingsRequest;
use App\Models\Project;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteSettingsController extends Controller
{
    public function edit(Request $request, Project $project): Response
    {
        Gate::authorize('manage', $project);
        $project->loadMissing('domains');

        return Inertia::render('websites/Settings', [
            'website' => [
                'id' => $project->getKey(),
                'name' => $project->name,
                'timezone' => $project->timezone,
                'domain' => $project->domains->first()?->domain,
                'siteKey' => $project->site_key,
                'isVerified' => $project->domains->contains(fn ($domain): bool => $domain->is_verified),
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
            'trackerUrl' => asset('a.js'),
            'publicSharing' => [
                'enabled' => $project->hasPublicSharingEnabled(),
                'url' => $project->public_share_token === null
                    ? null
                    : route('shared.dashboard.show', ['token' => $project->public_share_token]),
                'sections' => $project->publicDashboardSections(),
            ],
        ]);
    }

    public function update(
        UpdateWebsiteSettingsRequest $request,
        Project $project,
        UpdateWebsiteSettingsAction $updateWebsiteSettings,
    ): RedirectResponse {
        Gate::authorize('manage', $project);
        $updateWebsiteSettings->handle($project, $request->website());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Website settings saved.']);

        return to_route('websites.settings.edit', $project);
    }
}
