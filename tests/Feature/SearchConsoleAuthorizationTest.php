<?php

use App\Models\SearchConsoleConnection;
use App\Models\User;

it('allows workspace members to view imported data but not manage the Google connection', function () {
    $owner = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $owner->projects()->sole();
    $member = User::factory()->create([
        'is_admin' => false,
        'workspace_owner_id' => $owner->getKey(),
        'current_project_id' => $project->getKey(),
    ]);
    SearchConsoleConnection::factory()->for($project)->create([
        'connected_by_user_id' => $owner->getKey(),
    ]);

    $this->actingAs($member)
        ->get(route('websites.settings.edit', $project))
        ->assertOk();

    $this->actingAs($member)
        ->get(route('websites.search-console.connect', $project))
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('websites.search-console.sync', $project))
        ->assertForbidden();

    $this->actingAs($member)
        ->delete(route('websites.search-console.destroy', $project))
        ->assertForbidden();
});
