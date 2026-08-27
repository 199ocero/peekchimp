<?php

use App\Ai\Agents\AnalyticsInsightAgent;
use App\Jobs\GenerateInsightRecommendations;
use App\Models\AiInsightRun;
use App\Models\AnalyticsEvent;
use App\Models\Insight;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceAiSetting;
use App\Services\Analytics\AiInsightContextBuilder;
use App\Services\Analytics\AiProviderRegistry;
use App\Services\Analytics\InsightGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

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
    $enrichedCandidates = $candidates;
    $enrichedCandidates[0]['recommendation'] = 'A previously generated AI recommendation.';
    $enrichedCandidates[0]['explanation'] = 'A previously generated AI explanation.';
    $enrichedPayload = app(AiInsightContextBuilder::class)->build($project, $enrichedCandidates, '2026-08-01', '2026-08-07');
    expect(strlen(json_encode($payload)))->toBeLessThanOrEqual(config('analytics.ai.max_payload_bytes'))
        ->and($payload)->not->toHaveKey('visitor_id')
        ->and(json_encode($payload))->not->toContain('deterministic_recommendation')
        ->and($payload['rules'])->toContain('Give every candidate a distinct, metric-specific recommendation with a concrete next check.')
        ->and(app(AiInsightContextBuilder::class)->hash($payload))->toBe(app(AiInsightContextBuilder::class)->hash($enrichedPayload));
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

    AnalyticsInsightAgent::assertPrompted(fn ($prompt): bool => ! str_contains($prompt->prompt, 'visitor_id')
        && ! str_contains($prompt->prompt, 'deterministic_recommendation'));
    expect($insight->refresh()->explanation)->toContain('selected period')
        ->and($insight->recommendation)->toContain('source mix')
        ->and($insight->metadata)->toMatchArray(['ai_enhanced' => true])
        ->and(AiInsightRun::query()->where('project_id', $project->getKey())->sole()->status)->toBe('completed')
        ->and($setting->refresh()->api_key)->toBe('secret-key');
});

test('forced recommendation jobs reuse a completed analytics context', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $user->projects()->sole();
    $project->forceFill(['timezone' => 'Asia/Manila'])->save();
    $user->workspaceAiSetting()->create([
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
        'id' => (int) $insight->getKey(),
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
    $periodStart = '2026-08-01T00:00:00+08:00';
    $periodEnd = '2026-08-07T23:59:59+08:00';
    $payload = app(AiInsightContextBuilder::class)->build($project, $candidates, $periodStart, $periodEnd);
    $run = AiInsightRun::factory()->for($project)->create([
        'context_hash' => sha1(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: ''),
        'status' => 'completed',
        'updated_at' => now(),
    ]);

    AnalyticsInsightAgent::fake()->preventStrayPrompts();

    (new GenerateInsightRecommendations(
        $project,
        $candidates,
        $periodStart,
        $periodEnd,
        force: true,
    ))->handle(app(AiInsightContextBuilder::class), app(AiProviderRegistry::class));

    AnalyticsInsightAgent::assertNeverPrompted();
    expect($run->refresh()->status)->toBe('completed')
        ->and($insight->refresh()->explanation)->not->toBe('AI reran this recently completed context.')
        ->and(Cache::get('dashboard:ai-insights-version:'.$project->getKey()))->toBeNull();
});

test('recommendation jobs reject copied deterministic fallback text as AI enhancement', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $user->projects()->sole();
    $user->workspaceAiSetting()->create([
        'provider' => 'openai',
        'api_key' => 'secret-key',
        'is_enabled' => true,
    ]);
    $insight = Insight::factory()->for($project)->create([
        'recommendation' => 'Review the source and page changes behind this movement, then measure the next equivalent period.',
    ]);
    $candidates = [[
        'id' => (int) $insight->getKey(),
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
    ]];

    AnalyticsInsightAgent::fake([[
        'insights' => [[
            'fingerprint' => $insight->fingerprint,
            'priority' => 1,
            'explanation' => 'Visitors increased from 50 to 100.',
            'recommendation' => $insight->recommendation,
            'confidence_note' => 'Based on aggregate analytics changes.',
        ]],
    ]])->preventStrayPrompts();

    (new GenerateInsightRecommendations(
        $project,
        $candidates,
        now()->subDays(6)->startOfDay()->toIso8601String(),
        now()->endOfDay()->toIso8601String(),
        force: true,
    ))->handle(app(AiInsightContextBuilder::class), app(AiProviderRegistry::class));

    $run = AiInsightRun::query()->where('project_id', $project->getKey())->sole();
    expect($insight->refresh()->metadata)->not->toHaveKey('ai_enhanced')
        ->and($run->status)->toBe('skipped')
        ->and($run->error)->toBe('AI returned no distinct recommendations for the current analytics changes.');
});

test('recommendation jobs reject requests for individual visitor data', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $user->projects()->sole();
    $user->workspaceAiSetting()->create([
        'provider' => 'openai',
        'api_key' => 'secret-key',
        'is_enabled' => true,
    ]);
    $insight = Insight::factory()->for($project)->create();
    $candidates = [[
        'id' => (int) $insight->getKey(),
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
    ]];

    AnalyticsInsightAgent::fake([[
        'insights' => [[
            'fingerprint' => $insight->fingerprint,
            'priority' => 1,
            'explanation' => 'Returning visits increased during the selected period.',
            'recommendation' => 'Pull the list of returning visitors and inspect each person’s activity.',
            'confidence_note' => 'Based on aggregate analytics changes.',
        ]],
    ]])->preventStrayPrompts();

    (new GenerateInsightRecommendations(
        $project,
        $candidates,
        now()->subDays(6)->startOfDay()->toIso8601String(),
        now()->endOfDay()->toIso8601String(),
        force: true,
    ))->handle(app(AiInsightContextBuilder::class), app(AiProviderRegistry::class));

    expect($insight->refresh()->metadata)->not->toHaveKey('ai_enhanced')
        ->and(AiInsightRun::query()->sole()->status)->toBe('skipped');
});

test('AI-enhanced recommendations remain visible after dashboard insights refresh', function () {
    $project = Project::factory()->create();
    $from = CarbonImmutable::parse('2026-08-08 00:00:00', 'UTC');
    $to = CarbonImmutable::parse('2026-08-14 23:59:59', 'UTC');
    $previousFrom = $from->subWeek();
    $previousTo = $to->subWeek();

    AnalyticsEvent::factory()->count(60)->create([
        'project_id' => $project->getKey(),
        'event_name' => 'page_view',
        'occurred_at' => $from->addDay(),
    ]);
    AnalyticsEvent::factory()->count(40)->create([
        'project_id' => $project->getKey(),
        'event_name' => 'page_view',
        'occurred_at' => $previousFrom->addDay(),
    ]);

    $insights = app(InsightGenerationService::class);
    $generated = $insights->generate(
        $project,
        $from,
        $to,
        previousFrom: $previousFrom,
        previousTo: $previousTo,
    );
    $record = Insight::query()->where('fingerprint', $generated[0]['fingerprint'])->sole();
    $record->forceFill([
        'explanation' => 'AI found a meaningful shift in visitor behavior.',
        'recommendation' => 'AI recommends reviewing the campaign that drove the change.',
        'metadata' => ['ai_enhanced' => true, 'ai_priority' => 1],
    ])->save();

    $refreshed = $insights->generate(
        $project,
        $from,
        $to,
        previousFrom: $previousFrom,
        previousTo: $previousTo,
    );
    $visibleInsight = collect($refreshed)->firstWhere('fingerprint', $record->fingerprint);

    expect($visibleInsight)->toMatchArray([
        'explanation' => 'AI found a meaningful shift in visitor behavior.',
        'recommendation' => 'AI recommends reviewing the campaign that drove the change.',
        'ai_enhanced' => true,
    ]);
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
