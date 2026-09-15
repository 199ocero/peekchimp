<?php

use App\Contracts\SearchConsoleClient;
use App\Jobs\StartSearchConsoleSync;
use App\Models\SearchConsoleConnection;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('allows workspace members to manage the Google connection', function () {
    Queue::fake();
    $owner = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $owner->projects()->sole();
    $member = User::factory()->create([
        'is_admin' => false,
        'workspace_owner_id' => $owner->getKey(),
        'current_project_id' => $project->getKey(),
    ]);
    $connection = SearchConsoleConnection::factory()->for($project)->create([
        'connected_by_user_id' => $owner->getKey(),
    ]);
    $client = Mockery::mock(SearchConsoleClient::class);
    $client->shouldReceive('authorizationUrl')->once()->andReturn('https://accounts.google.com/o/oauth2/auth');
    $client->shouldReceive('revoke')->once()->with(
        Mockery::on(fn (SearchConsoleConnection $candidate): bool => $candidate->is($connection)),
    );
    app()->instance(SearchConsoleClient::class, $client);

    $this->actingAs($member)
        ->get(route('websites.settings.edit', $project))
        ->assertOk();

    $this->actingAs($member)
        ->get(route('websites.search-console.connect', $project))
        ->assertRedirect('https://accounts.google.com/o/oauth2/auth');

    $this->actingAs($member)
        ->post(route('websites.search-console.sync', $project))
        ->assertRedirect(route('websites.settings.edit', $project));

    Queue::assertPushed(StartSearchConsoleSync::class);

    $this->actingAs($member)
        ->delete(route('websites.search-console.destroy', $project))
        ->assertRedirect(route('websites.settings.edit', $project));

    $this->assertModelMissing($connection);
});
