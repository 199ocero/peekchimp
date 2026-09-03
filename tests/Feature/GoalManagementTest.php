<?php

use App\Jobs\BackfillGoalConversions;
use App\Models\Goal;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('the goals page exposes editable goal data and controls', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    Goal::factory()->for($project)->create([
        'type' => 'url',
        'event_name' => null,
        'path' => '/thanks',
        'path_operator' => 'prefix',
        'property_match' => ['plan' => 'pro'],
        'is_active' => false,
    ]);

    $this->actingAs($user)
        ->get(route('websites.goals.index', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('websites/Goals')
            ->where('goals.0.pathOperator', 'prefix')
            ->where('goals.0.propertyMatch.plan', 'pro')
            ->where('goals.0.isActive', false));

    expect(file_get_contents(resource_path('js/pages/websites/Goals.vue')))
        ->toContain("import { destroy, store, update } from '@/routes/websites/goals';")
        ->toContain('@click="editGoal(goal)"')
        ->toContain('@click="deleteGoal(goal)"');
});

test('goals can be updated and deleted', function () {
    Queue::fake();
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    $goal = Goal::factory()->for($project)->create([
        'property_match' => ['plan' => 'pro'],
    ]);
    $goal->conversions()->create([
        'project_id' => $project->getKey(),
        'session_id' => 'converted-session',
        'occurred_at' => now(),
    ]);

    $this->actingAs($user)
        ->patch(route('websites.goals.update', [$project, $goal]), [
            'name' => 'Thank you page',
            'type' => 'url',
            'path' => '/thanks',
            'path_operator' => 'prefix',
            'property_match' => ['plan' => 'team'],
            'is_active' => false,
        ])
        ->assertRedirect(route('websites.goals.index', $project));

    expect($goal->refresh())
        ->name->toBe('Thank you page')
        ->type->toBe('url')
        ->event_name->toBeNull()
        ->path->toBe('/thanks')
        ->path_operator->toBe('prefix')
        ->property_match->toBe(['plan' => 'team'])
        ->is_active->toBeFalse();
    expect($goal->conversions()->count())->toBe(0);
    Queue::assertPushed(BackfillGoalConversions::class);

    $this->delete(route('websites.goals.destroy', [$project, $goal]))
        ->assertRedirect(route('websites.goals.index', $project));

    $this->assertModelMissing($goal);
});

test('a goal cannot be changed through another project', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    $otherProject = Project::factory()->for($user)->create();
    $goal = Goal::factory()->for($otherProject)->create();

    $this->actingAs($user)
        ->patch(route('websites.goals.update', [$project, $goal]), [
            'name' => 'Changed',
        ])
        ->assertNotFound();

    expect($goal->refresh()->name)->toBe('Signup');
});
