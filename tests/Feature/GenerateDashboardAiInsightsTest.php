<?php

use App\Jobs\GenerateInsightRecommendations;
use App\Models\AiInsightRun;
use App\Models\AnalyticsEvent;
use App\Models\User;
use App\Queries\Analytics\DashboardQuery;
use App\Services\Analytics\AiInsightContextBuilder;
use App\Services\Analytics\AiInsightGenerationCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('workspace admins can manually generate AI recommendations for dashboard changes', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $user->projects()->sole();
    AnalyticsEvent::factory()->count(60)->create([
        'project_id' => $project->getKey(),
        'event_name' => 'page_view',
        'occurred_at' => now()->subDay(),
    ]);
    AnalyticsEvent::factory()->count(40)->create([
        'project_id' => $project->getKey(),
        'event_name' => 'page_view',
        'occurred_at' => now()->subDays(8),
    ]);
    Queue::fake();

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('dashboard.ai-insights.generate'), ['range' => '7d'])
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('aiInsightGeneration.queued', true)
        ->assertInertiaFlash('toast.message', 'AI insight generation queued. The dashboard will update when it is ready.');

    Queue::assertPushed(GenerateInsightRecommendations::class, fn (GenerateInsightRecommendations $job): bool => $job->project->is($project) && $job->force);
    expect(AiInsightRun::query()->where('project_id', $project->getKey())->sole()->status)->toBe('queued');
});

test('repeated manual requests reuse the active AI run', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $user->projects()->sole();
    AnalyticsEvent::factory()->count(60)->create([
        'project_id' => $project->getKey(),
        'event_name' => 'page_view',
        'occurred_at' => now()->subDay(),
    ]);
    AnalyticsEvent::factory()->count(40)->create([
        'project_id' => $project->getKey(),
        'event_name' => 'page_view',
        'occurred_at' => now()->subDays(8),
    ]);
    Queue::fake();

    $this->actingAs($user)->post(route('dashboard.ai-insights.generate'), ['range' => '7d']);
    $this->actingAs($user)
        ->post(route('dashboard.ai-insights.generate'), ['range' => '7d'])
        ->assertInertiaFlash('toast.message', 'AI insight generation is already in progress. The dashboard will update when it is ready.');

    Queue::assertPushed(GenerateInsightRecommendations::class, 1);
    expect(AiInsightRun::query()->where('project_id', $project->getKey())->count())->toBe(1);
});

test('workspace admins can manually generate AI recommendations for this month', function () {
    $this->travelTo(now()->startOfMonth()->addDays(20));
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $user->projects()->sole();
    AnalyticsEvent::factory()->count(60)->create([
        'project_id' => $project->getKey(),
        'event_name' => 'page_view',
        'occurred_at' => now()->subDay(),
    ]);
    AnalyticsEvent::factory()->count(40)->create([
        'project_id' => $project->getKey(),
        'event_name' => 'page_view',
        'occurred_at' => now()->subMonth(),
    ]);
    Queue::fake();

    $this->actingAs($user)
        ->from(route('dashboard', ['range' => 'month']))
        ->post(route('dashboard.ai-insights.generate'), ['range' => 'month'])
        ->assertRedirect(route('dashboard', ['range' => 'month']))
        ->assertInertiaFlash('aiInsightGeneration.queued', true);

    Queue::assertPushed(GenerateInsightRecommendations::class, fn (GenerateInsightRecommendations $job): bool => $job->project->is($project) && $job->force);
});

test('manual AI generation reports when there are no analytics changes to queue', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    Queue::fake();

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('dashboard.ai-insights.generate'), ['range' => 'month'])
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('aiInsightGeneration.queued', false);

    Queue::assertNothingPushed();
});

test('manual AI generation reuses a completed run for unchanged data', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $user->projects()->sole();
    AnalyticsEvent::factory()->count(60)->create([
        'project_id' => $project->getKey(),
        'event_name' => 'page_view',
        'occurred_at' => now()->subDay(),
    ]);
    AnalyticsEvent::factory()->count(40)->create([
        'project_id' => $project->getKey(),
        'event_name' => 'page_view',
        'occurred_at' => now()->subDays(8),
    ]);
    Queue::fake();
    $analytics = app(DashboardQuery::class)->run($project, ['range' => '7d'], queueAiInsights: false);
    $candidates = $analytics['actionableInsights'];
    $periodStart = data_get($candidates, '0.period_start');
    $periodEnd = data_get($candidates, '0.period_end');
    $contextBuilder = app(AiInsightContextBuilder::class);
    $context = $contextBuilder->build($project, $candidates, $periodStart, $periodEnd);
    AiInsightRun::factory()->for($project)->create([
        'context_hash' => $contextBuilder->hash($context),
        'status' => 'completed',
    ]);
    Queue::fake();

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('dashboard.ai-insights.generate'), ['range' => '7d'])
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('aiInsightGeneration.queued', false)
        ->assertInertiaFlash('toast.message', 'AI insights are already up to date. No new AI request was made.');

    Queue::assertNothingPushed();
});

test('jobs for the same aggregate changes have the same queue identity', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    $candidates = [[
        'fingerprint' => 'visitors-increased',
        'current_value' => 100,
        'previous_value' => 50,
        'percentage_change' => 100,
    ]];

    $first = new GenerateInsightRecommendations(123, $project, $candidates, now()->subDay()->toIso8601String(), now()->toIso8601String(), true);
    $second = new GenerateInsightRecommendations(123, $project, $candidates, now()->subDay()->toIso8601String(), now()->toIso8601String(), true);

    expect($first->uniqueId())->toBe($second->uniqueId());
});

test('AI queue timeouts leave enough time for failure cleanup', function () {
    $project = User::factory()->withVerifiedWebsite()->create()->projects()->sole();
    $job = new GenerateInsightRecommendations(123, $project, [], now()->subDay()->toIso8601String(), now()->toIso8601String());
    $requestTimeout = (int) config('analytics.ai.request_timeout_seconds');
    $horizonTimeout = (int) config('horizon.defaults.supervisor-ai.timeout');
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    expect($job->queue)->toBe('ai')
        ->and($requestTimeout)->toBeLessThan($job->timeout)
        ->and($job->timeout)->toBeLessThan($horizonTimeout)
        ->and($horizonTimeout)->toBeLessThan($retryAfter);
});

test('stale active runs stop generating and can be queued again', function () {
    $project = User::factory()->withVerifiedWebsite()->create()->projects()->sole();
    $periodStart = now()->subDays(6)->startOfDay()->toIso8601String();
    $periodEnd = now()->endOfDay()->toIso8601String();
    $candidates = [[
        'fingerprint' => 'visitors-increased',
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
    ]];
    $contextBuilder = app(AiInsightContextBuilder::class);
    $context = $contextBuilder->build($project, $candidates, $periodStart, $periodEnd);
    $run = AiInsightRun::factory()->for($project)->create([
        'context_hash' => $contextBuilder->hash($context),
        'status' => 'running',
        'updated_at' => now()->subMinutes(4),
        'completed_at' => null,
    ]);
    $coordinator = app(AiInsightGenerationCoordinator::class);

    expect($coordinator->status($project, $candidates))->toMatchArray([
        'id' => (int) $run->getKey(),
        'status' => 'failed',
        'error' => 'AI generation stopped before it finished. Please try again.',
    ]);

    Queue::fake();
    $generation = $coordinator->request($project, $candidates, $periodStart, $periodEnd, force: true);

    expect($generation['reason'])->toBe('queued')
        ->and($run->refresh()->status)->toBe('queued')
        ->and($run->error)->toBeNull();
    Queue::assertPushed(GenerateInsightRecommendations::class, 1);
});

test('workspace members cannot manually generate AI recommendations', function () {
    $user = User::factory()->withVerifiedWebsite()->create();

    $this->actingAs($user)
        ->post(route('dashboard.ai-insights.generate'))
        ->assertForbidden();
});
