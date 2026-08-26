<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateWorkspaceAiSettingsRequest;
use App\Models\User;
use App\Services\Analytics\AiProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceAiSettingsController extends Controller
{
    public function edit(Request $request, AiProviderRegistry $providers): Response
    {
        Gate::authorize('manageMembers', $request->user());
        /** @var User $user */
        $user = $request->user()->workspaceOwnerUser();
        $setting = $user->workspaceAiSetting()->first();

        return Inertia::render('settings/Ai', [
            'providers' => $providers->providers(),
            'settings' => $setting === null ? null : [
                'provider' => $setting->provider,
                'model' => $setting->model,
                'baseUrl' => $setting->base_url,
                'isEnabled' => $setting->is_enabled,
                'status' => $setting->status,
                'hasApiKey' => filled($setting->api_key),
                'lastTestedAt' => ($lastTestedAt = $setting->getAttribute('last_tested_at')) instanceof CarbonImmutable
                    ? $lastTestedAt->toIso8601String()
                    : null,
            ],
        ]);
    }

    public function update(UpdateWorkspaceAiSettingsRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user()->workspaceOwnerUser();
        $setting = $user->workspaceAiSetting()->firstOrNew();
        $data = $request->validated();

        if (! array_key_exists('api_key', $data) || trim((string) $data['api_key']) === '') {
            unset($data['api_key']);
        }

        $setting->fill([
            ...$data,
            'workspace_owner_id' => $user->getKey(),
            'status' => ($data['is_enabled'] ?? $setting->is_enabled) ? 'configured' : 'disabled',
            'last_error' => null,
        ]);
        $setting->save();

        return to_route('settings.ai.edit');
    }

    public function test(UpdateWorkspaceAiSettingsRequest $request, AiProviderRegistry $providers): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user()->workspaceOwnerUser();
        $setting = $user->workspaceAiSetting()->first();

        if ($setting === null || ($providers->requiresApiKey((string) $setting->provider) && ! filled($setting->api_key))) {
            return back()->withErrors(['api_key' => 'Save an API key before testing the connection.']);
        }

        $setting->forceFill(['status' => 'tested', 'last_tested_at' => now(), 'last_error' => null])->save();

        return back()->with('status', 'AI provider settings are ready.');
    }
}
