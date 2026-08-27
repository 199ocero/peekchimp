<?php

use App\Jobs\GenerateInsightRecommendations;
use App\Models\AiInsightRun;
use App\Models\AnalyticsEvent;
use App\Models\User;
use App\Queries\Analytics\DashboardQuery;
use App\Services\Analytics\AiInsightContextBuilder;
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
    $analytics = app(DashboardQuery::class)->run($project, ['range' => '7d']);
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

    $first = new GenerateInsightRecommendations($project, $candidates, now()->subDay()->toIso8601String(), now()->toIso8601String(), true);
    $second = new GenerateInsightRecommendations($project, $candidates, now()->subDay()->toIso8601String(), now()->toIso8601String(), true);

    expect($first->uniqueId())->toBe($second->uniqueId());
});

test('workspace members cannot manually generate AI recommendations', function () {
    $user = User::factory()->withVerifiedWebsite()->create();

    $this->actingAs($user)
        ->post(route('dashboard.ai-insights.generate'))
        ->assertForbidden();
});
