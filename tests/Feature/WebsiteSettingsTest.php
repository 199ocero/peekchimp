<?php

use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('owners can view settings for each website', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = Project::factory()->for($user)->create(['name' => 'Second website']);
    $project->domains()->create(['domain' => 'second.example.test']);

    $this->actingAs($user)
        ->get(route('websites.settings.edit', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('websites/Settings')
            ->where('website.id', $project->id)
            ->where('website.name', 'Second website')
            ->where('website.domain', 'second.example.test')
            ->where('website.isVerified', false)
            ->where('publicSharing.enabled', false)
            ->where('publicSharing.url', null)
            ->has('publicSharing.sections', count(Project::defaultPublicDashboardSections())),
        );
});

test('owners can update website name and timezone without changing its domain', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    $domain = $project->domains()->sole()->domain;
    $siteKey = $project->site_key;

    $this->actingAs($user)
        ->patch(route('websites.settings.update', $project), [
            'name' => 'Updated website',
            'timezone' => 'Asia/Manila',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('websites.settings.edit', $project));

    expect($project->refresh()->name)->toBe('Updated website')
        ->and($project->timezone)->toBe('Asia/Manila')
        ->and($project->domains()->sole()->domain)->toBe($domain)
        ->and($project->site_key)->toBe($siteKey);
});

test('website settings reject invalid general values', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();

    $this->actingAs($user)
        ->patch(route('websites.settings.update', $project), [
            'name' => '',
            'timezone' => 'Moon/Base',
        ])
        ->assertSessionHasErrors(['name', 'timezone']);
});

test('users cannot manage another users website settings', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $otherUser = User::factory()->withVerifiedWebsite()->create();
    $project = $otherUser->projects()->sole();

    $this->actingAs($user)
        ->get(route('websites.settings.edit', $project))
        ->assertForbidden();

    $this->actingAs($user)
        ->patch(route('websites.settings.update', $project), [
            'name' => 'Not theirs',
            'timezone' => 'UTC',
        ])
        ->assertForbidden();
});
