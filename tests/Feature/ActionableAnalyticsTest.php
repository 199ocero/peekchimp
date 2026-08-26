<?php

use App\Jobs\EvaluateInsightOutcomes;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Funnel;
use App\Models\Goal;
use App\Models\Insight;
use App\Models\InsightActionAttempt;
use App\Models\Project;
use App\Models\User;
use App\Services\Analytics\FunnelAnalyticsService;
use App\Services\Analytics\GoalAnalyticsService;
use App\Services\Analytics\GoalConversionService;
use App\Services\Analytics\InsightOutcomeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('event and URL goals record one conversion per session', function () {
    $project = Project::factory()->create();
    $eventGoal = Goal::factory()->for($project)->create(['type' => 'event', 'event_name' => 'signup_completed']);
    $urlGoal = Goal::factory()->for($project)->create(['name' => 'Thank you', 'type' => 'url', 'event_name' => null, 'path' => '/thanks']);
    $service = app(GoalConversionService::class);
    $occurredAt = CarbonImmutable::now();

    foreach ([
        ['event_id' => '11111111-1111-4111-8111-111111111111', 'event_name' => 'signup_completed', 'path' => '/pricing'],
        ['event_id' => '22222222-2222-4222-8222-222222222222', 'event_name' => 'page_view', 'path' => '/thanks'],
    ] as $data) {
        AnalyticsEvent::factory()->for($project)->create([
            'event_id' => $data['event_id'],
            'event_name' => $data['event_name'],
            'session_id' => 'session-one',
            'path' => $data['path'],
            'occurred_at' => $occurredAt,
        ]);
        $service->record($project, 'session-one', [...$data, 'properties' => [], 'occurred_at' => $occurredAt]);
    }
    $service->record($project, 'session-one', ['event_id' => '11111111-1111-4111-8111-111111111111', 'event_name' => 'signup_completed', 'path' => '/pricing', 'properties' => [], 'occurred_at' => $occurredAt]);

    expect($eventGoal->conversions()->count())->toBe(1)
        ->and($urlGoal->conversions()->count())->toBe(1);
});

test('funnel analytics counts ordered session progression and drop-off', function () {
    $project = Project::factory()->create();
    $funnel = Funnel::factory()->for($project)->create();
    $funnel->steps()->createMany([
        ['position' => 1, 'name' => 'Landing', 'type' => 'url', 'path' => '/', 'path_operator' => 'exact'],
        ['position' => 2, 'name' => 'Signup', 'type' => 'event', 'event_name' => 'signup_completed', 'path_operator' => 'exact'],
    ]);
    foreach (['session-a', 'session-b'] as $sessionId) {
        AnalyticsSession::factory()->for($project)->create(['session_id' => $sessionId, 'started_at' => now(), 'last_seen_at' => now()]);
        AnalyticsEvent::factory()->for($project)->create(['session_id' => $sessionId, 'event_name' => 'page_view', 'path' => '/', 'occurred_at' => now()->subMinute()]);
    }
    AnalyticsEvent::factory()->for($project)->create(['session_id' => 'session-a', 'event_name' => 'signup_completed', 'path' => '/', 'occurred_at' => now()]);

    $summary = app(FunnelAnalyticsService::class)->summary($funnel->fresh(), CarbonImmutable::now()->subHour(), CarbonImmutable::now()->addHour());

    expect($summary['steps'][0]['users'])->toBe(2)
        ->and($summary['steps'][0]['progressed'])->toBe(1)
        ->and($summary['steps'][1]['users'])->toBe(1)
        ->and($summary['conversionRate'])->toBe(50.0);
});

test('goal analytics reports session conversion rate', function () {
    $project = Project::factory()->create();
    $goal = Goal::factory()->for($project)->create();
    AnalyticsSession::factory()->for($project)->count(4)->create(['started_at' => now()]);
    $goal->conversions()->createMany([
        ['project_id' => $project->id, 'session_id' => 'one', 'occurred_at' => now()],
        ['project_id' => $project->id, 'session_id' => 'two', 'occurred_at' => now()],
    ]);

    expect(app(GoalAnalyticsService::class)->summary($goal, CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay()))
        ->toMatchArray(['conversions' => 2, 'visits' => 4, 'conversionRate' => 50.0]);
});

test('dashboard exposes actionable outcome sections', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    $project->goals()->create(['name' => 'Signup', 'type' => 'event', 'event_name' => 'signup_completed']);

    $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->has('analytics.metrics.conversions')
        ->has('analytics.metrics.conversionRate')
        ->has('analytics.goals')
        ->has('analytics.importantActions')
        ->has('analytics.aiTraffic'));
});

test('insight actions are recorded and can dismiss an insight', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    $insight = Insight::factory()->for($project)->create();

    $this->actingAs($user)
        ->post(route('insights.actions.store', $insight), ['action' => 'mark_done'])
        ->assertRedirect();

    expect($insight->refresh()->dismissed_at)->not->toBeNull()
        ->and($insight->actionAttempts()->count())->toBe(1);
});

test('scheduled outcome evaluation records observed movement after an action', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-26 12:00:00', 'UTC'));

    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    $insight = Insight::factory()->for($project)->create([
        'metric' => 'visitors',
        'period_start' => '2026-08-11 00:00:00',
        'period_end' => '2026-08-17 23:59:59',
    ]);
    $attempt = InsightActionAttempt::factory()->for($insight)->for($user)->create([
        'acted_at' => '2026-08-18 12:00:00',
    ]);

    AnalyticsEvent::factory()->for($project)->create([
        'event_name' => 'page_view',
        'visitor_id' => 'before-visitor',
        'occurred_at' => '2026-08-12 12:00:00',
    ]);
    foreach (['after-one', 'after-two'] as $visitorId) {
        AnalyticsEvent::factory()->for($project)->create([
            'event_name' => 'page_view',
            'visitor_id' => $visitorId,
            'occurred_at' => '2026-08-19 12:00:00',
        ]);
    }

    (new EvaluateInsightOutcomes)->handle(app(InsightOutcomeService::class));

    expect($attempt->refresh()->outcome)->toBe('improved');
});
