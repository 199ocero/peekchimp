<?php

use App\Ai\Agents\AnalyticsInsightAgent;
use App\Jobs\GenerateInsightRecommendations;
use App\Models\AiInsightRun;
use App\Models\Insight;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceAiSetting;
use App\Services\Analytics\AiInsightContextBuilder;
use App\Services\Analytics\AiProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('workspace AI settings are restricted to the supported providers and encrypted', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);

    $this->actingAs($user)->patch(route('settings.ai.update'), [
        'provider' => 'deepseek',
        'model' => 'deepseek-chat',
        'api_key' => 'secret-key',
        'is_enabled' => true,
    ])->assertRedirect(route('settings.ai.edit'));

    $setting = WorkspaceAiSetting::query()->sole();
    expect($setting->api_key)->toBe('secret-key')
        ->and($setting->getRawOriginal('api_key'))->not->toBe('secret-key');
});

test('AI context is bounded and contains aggregate candidates only', function () {
    $project = Project::factory()->create();
    $candidates = array_map(fn (int $index): array => [
        'fingerprint' => "fingerprint-{$index}",
        'category' => 'traffic',
        'metric' => 'visitors',
        'label' => 'Visitors',
        'current_value' => 100,
        'previous_value' => 50,
        'percentage_change' => 100,
        'confidence' => 'medium',
        'summary' => str_repeat('x', 3000),
        'recommendation' => 'Review sources.',
    ], range(1, 10));

    $payload = app(AiInsightContextBuilder::class)->build($project, $candidates, '2026-08-01', '2026-08-07');
    expect(strlen(json_encode($payload)))->toBeLessThanOrEqual(config('analytics.ai.max_payload_bytes'))
        ->and($payload)->not->toHaveKey('visitor_id');
});

test('provider registry exposes only the configured BYOK providers', function () {
    $registry = app(AiProviderRegistry::class);
    expect($registry->providers())->toContain('openai', 'anthropic', 'gemini', 'openrouter', 'ollama', 'openai-compatible')
        ->and($registry->isSupported('ollama'))->toBeTrue()
        ->and($registry->requiresApiKey('ollama'))->toBeFalse()
        ->and($registry->requiresApiKey('openai'))->toBeTrue();
});

test('recommendation jobs send only bounded context and persist structured output', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $user->projects()->sole();
    $setting = $user->workspaceAiSetting()->create([
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'api_key' => 'secret-key',
        'is_enabled' => true,
    ]);
    $insight = Insight::factory()->for($project)->create([
        'period_start' => '2026-08-01 00:00:00',
        'period_end' => '2026-08-07 23:59:59',
    ]);
    $candidates = [[
        'fingerprint' => $insight->fingerprint,
        'category' => $insight->category,
        'metric' => $insight->metric,
        'label' => 'Visitors',
        'current_value' => 100,
        'previous_value' => 50,
        'percentage_change' => 100,
        'confidence' => 'medium',
        'summary' => $insight->summary,
        'recommendation' => $insight->recommendation,
    ]];

    AnalyticsInsightAgent::fake([[
        'insights' => [[
            'fingerprint' => $insight->fingerprint,
            'priority' => 1,
            'explanation' => 'The increase is concentrated in the selected period.',
            'recommendation' => 'Review the source mix before scaling the campaign.',
            'confidence_note' => 'This is a comparison, not proof of causation.',
        ]],
    ]])->preventStrayPrompts();
    (new GenerateInsightRecommendations(
        $project,
        $candidates,
        '2026-08-01T00:00:00+00:00',
        '2026-08-07T23:59:59+00:00',
    ))->handle(app(AiInsightContextBuilder::class), app(AiProviderRegistry::class));

    AnalyticsInsightAgent::assertPrompted(fn ($prompt): bool => ! str_contains($prompt->prompt, 'visitor_id'));
    expect($insight->refresh()->explanation)->toContain('selected period')
        ->and($insight->recommendation)->toContain('source mix')
        ->and(AiInsightRun::query()->where('project_id', $project->getKey())->sole()->status)->toBe('completed')
        ->and($setting->refresh()->api_key)->toBe('secret-key');
});

test('recommendation job records provider failures without breaking analytics', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $user->projects()->sole();
    $user->workspaceAiSetting()->create([
        'provider' => 'openai',
        'api_key' => 'secret-key',
        'is_enabled' => true,
    ]);
    $insight = Insight::factory()->for($project)->create();

    AnalyticsInsightAgent::fake(fn (): never => throw new RuntimeException('provider unavailable'))
        ->preventStrayPrompts();

    (new GenerateInsightRecommendations(
        $project,
        [[
            'fingerprint' => $insight->fingerprint,
            'category' => 'traffic',
            'metric' => 'visitors',
            'label' => 'Visitors',
            'current_value' => 100,
            'previous_value' => 50,
            'percentage_change' => 100,
            'confidence' => 'medium',
            'summary' => $insight->summary,
            'recommendation' => $insight->recommendation,
        ]],
        now()->subDays(6)->startOfDay()->toIso8601String(),
        now()->endOfDay()->toIso8601String(),
    ))->handle(app(AiInsightContextBuilder::class), app(AiProviderRegistry::class));

    expect(AiInsightRun::query()->where('project_id', $project->getKey())->sole()->status)->toBe('failed')
        ->and(AiInsightRun::query()->where('project_id', $project->getKey())->sole()->error)->toContain('provider unavailable');
});
