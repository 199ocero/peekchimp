<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateWorkspaceMapboxSettingsRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceMapboxSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        Gate::authorize('manageMembers', $request->user());
        /** @var User $owner */
        $owner = $request->user()->workspaceOwnerUser();

        return Inertia::render('settings/Mapbox', [
            'hasToken' => filled($owner->mapbox_public_token),
        ]);
    }

    public function update(UpdateWorkspaceMapboxSettingsRequest $request): RedirectResponse
    {
        /** @var User $owner */
        $owner = $request->user()->workspaceOwnerUser();
        $owner->forceFill($request->validated())->save();

        return to_route('settings.mapbox.edit')->with('status', 'Mapbox token saved.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Gate::authorize('manageMembers', $request->user());
        /** @var User $owner */
        $owner = $request->user()->workspaceOwnerUser();
        $owner->forceFill(['mapbox_public_token' => null])->save();

        return to_route('settings.mapbox.edit')->with('status', 'Mapbox token removed.');
    }
}
