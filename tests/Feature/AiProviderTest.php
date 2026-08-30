<?php

use App\Models\User;
use App\Models\WorkspaceAiSetting;
use App\Services\Analytics\AiProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('workspace AI chat settings are restricted to the supported providers and encrypted', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);

    $this->actingAs($user)->patch(route('settings.ai.update'), [
        'provider' => 'deepseek',
        'model' => 'deepseek-v4-flash',
        'api_key' => 'secret-key',
        'is_enabled' => true,
    ])->assertRedirect(route('settings.ai.edit'));

    $setting = WorkspaceAiSetting::query()->sole();
    expect($setting->api_key)->toBe('secret-key')
        ->and($setting->model)->toBe('deepseek-v4-flash')
        ->and($setting->getRawOriginal('api_key'))->not->toBe('secret-key');
});

test('workspace AI chat settings expose models for the supported providers', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);

    $this->actingAs($user)
        ->get(route('settings.ai.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Ai')
            ->has('providers', 4)
            ->where('providers.0.value', 'openai')
            ->where('providers.1.value', 'anthropic')
            ->where('providers.2.value', 'gemini')
            ->where('providers.3.value', 'deepseek')
            ->where('providers.2.models.0.value', 'gemini-3.5-flash-lite')
            ->where('providers.3.models.0.value', 'deepseek-v4-flash'),
        );
});

test('workspace AI chat settings reject removed providers and models from another provider', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);

    $this->actingAs($user)->patch(route('settings.ai.update'), [
        'provider' => 'openrouter',
        'model' => 'openai/gpt-5.6-luna',
        'api_key' => 'secret-key',
        'is_enabled' => true,
    ])->assertSessionHasErrors(['provider', 'model']);

    $this->actingAs($user)->patch(route('settings.ai.update'), [
        'provider' => 'gemini',
        'model' => 'deepseek-v4-flash',
        'api_key' => 'secret-key',
        'is_enabled' => true,
    ])->assertSessionHasErrors(['model']);
});

test('provider registry exposes only the configured BYOK providers', function () {
    $registry = app(AiProviderRegistry::class);

    expect($registry->providers())->toBe(['openai', 'anthropic', 'gemini', 'deepseek'])
        ->and($registry->isSupported('openrouter'))->toBeFalse()
        ->and($registry->isSupported('ollama'))->toBeFalse()
        ->and($registry->modelsFor('gemini'))->toContain('gemini-3.5-flash-lite')
        ->and($registry->modelsFor('deepseek'))->toBe(['deepseek-v4-flash', 'deepseek-v4-pro'])
        ->and($registry->requiresApiKey('openai'))->toBeTrue();
});
