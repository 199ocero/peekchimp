<?php

namespace App\Http\Controllers;

use App\Actions\Websites\UpdateWebsiteSharingAction;
use App\Http\Requests\UpdateWebsiteSharingRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class WebsiteSharingController extends Controller
{
    public function update(
        UpdateWebsiteSharingRequest $request,
        Project $project,
        UpdateWebsiteSharingAction $updateWebsiteSharing,
    ): RedirectResponse {
        Gate::authorize('share', $project);
        $sharing = $request->sharing();
        $updateWebsiteSharing->handle($project, $sharing['enabled'], $sharing['sections']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Public dashboard settings saved.']);

        return to_route('websites.settings.edit', $project);
    }

    public function rotate(
        Project $project,
        UpdateWebsiteSharingAction $updateWebsiteSharing,
    ): RedirectResponse {
        Gate::authorize('share', $project);
        $updateWebsiteSharing->rotate($project);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Public dashboard link rotated.']);

        return to_route('websites.settings.edit', $project);
    }
}
