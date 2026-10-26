<?php

use App\Contracts\SearchConsoleClient;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\ChatConversationContext;
use App\Models\Goal;
use App\Models\Project;
use App\Models\SearchConsoleConnection;
use App\Models\SearchConsoleMetric;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('owners can delete a website and all of its data', function () {
    $owner = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $keptProject = $owner->projects()->sole();
    $project = Project::factory()->for($owner)->create(['name' => 'Old website']);
    $project->domains()->create(['domain' => 'old.example.test', 'is_verified' => true]);
    $owner->currentProject()->associate($project)->save();

    AnalyticsEvent::factory()->for($project)->create();
    AnalyticsSession::factory()->for($project)->create();
    Goal::factory()->for($project)->create();
    SearchConsoleMetric::factory()->for($project)->create();
    $connection = SearchConsoleConnection::factory()->for($project)->create([
        'connected_by_user_id' => $owner->getKey(),
    ]);
    $context = ChatConversationContext::factory()->for($project)->create();
    $keptEvent = AnalyticsEvent::factory()->for($keptProject)->create();

    $client = Mockery::mock(SearchConsoleClient::class);
    $client->shouldReceive('revoke')->once()->with(
        Mockery::on(fn (SearchConsoleConnection $candidate): bool => $candidate->is($connection)),
    );
    app()->instance(SearchConsoleClient::class, $client);

    $this->actingAs($owner)
        ->delete(route('websites.destroy', $project), ['confirmation' => 'old.example.test'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $this->assertModelMissing($project);
    $this->assertDatabaseMissing('project_domains', ['project_id' => $project->getKey()]);
    $this->assertDatabaseMissing('events', ['project_id' => $project->getKey()]);
    $this->assertDatabaseMissing('analytics_sessions', ['project_id' => $project->getKey()]);
    $this->assertDatabaseMissing('goals', ['project_id' => $project->getKey()]);
    $this->assertDatabaseMissing('search_console_metrics', ['project_id' => $project->getKey()]);
    $this->assertModelMissing($connection);
    $this->assertModelMissing($context);
    $this->assertDatabaseMissing('agent_conversations', ['id' => $context->conversation_id]);
    $this->assertModelExists($keptProject);
    $this->assertModelExists($keptEvent);
    expect($owner->refresh()->current_project_id)->toBeNull();
});

test('deleting a website requires typing its domain', function () {
    $owner = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $owner->projects()->sole();

    $this->actingAs($owner)
        ->delete(route('websites.destroy', $project), ['confirmation' => 'wrong.example.test'])
        ->assertSessionHasErrors('confirmation');

    $this->assertModelExists($project);
});

test('websites without a domain are confirmed by name', function () {
    $owner = User::factory()->create(['is_admin' => true]);
    $project = Project::factory()->for($owner)->create(['name' => 'Draft website']);

    $this->actingAs($owner)
        ->delete(route('websites.destroy', $project), ['confirmation' => 'Draft website'])
        ->assertRedirect(route('dashboard'));

    $this->assertModelMissing($project);
});

test('workspace members cannot delete websites', function () {
    $owner = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $owner->projects()->sole();
    $member = User::factory()->create([
        'is_admin' => false,
        'workspace_owner_id' => $owner->getKey(),
        'current_project_id' => $project->getKey(),
    ]);

    $this->actingAs($member)
        ->delete(route('websites.destroy', $project), ['confirmation' => $project->domains()->value('domain')])
        ->assertForbidden();

    $this->assertModelExists($project);
});

test('users cannot delete websites in another workspace', function () {
    $project = User::factory()->withVerifiedWebsite()->create()->projects()->sole();
    $otherUser = User::factory()->withVerifiedWebsite()->create();

    $this->actingAs($otherUser)
        ->delete(route('websites.destroy', $project), ['confirmation' => $project->domains()->value('domain')])
        ->assertForbidden();

    $this->assertModelExists($project);
});

test('website settings only offer deletion to the workspace owner', function () {
    $owner = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $owner->projects()->sole();
    $member = User::factory()->create([
        'is_admin' => false,
        'workspace_owner_id' => $owner->getKey(),
        'current_project_id' => $project->getKey(),
    ]);

    $this->actingAs($owner)
        ->get(route('websites.settings.edit', $project))
        ->assertInertia(fn (Assert $page) => $page->where('website.canDelete', true));

    $this->actingAs($member)
        ->get(route('websites.settings.edit', $project))
        ->assertInertia(fn (Assert $page) => $page->where('website.canDelete', false));
});
