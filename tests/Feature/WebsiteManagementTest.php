<?php

use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('verified users can open the add website flow', function () {
    $user = User::factory()->withVerifiedWebsite()->create();

    $this->actingAs($user)
        ->get(route('websites.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/Index')
            ->where('website', null)
            ->where('mode', 'create')
            ->where('backToDashboard', true),
        );
});

test('users can add a website without changing their existing website', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $firstProject = $user->projects()->sole();
    $firstDomain = $firstProject->domains()->sole()->domain;

    $this->actingAs($user)
        ->post(route('websites.store'), [
            'name' => 'Second website',
            'url' => 'https://second.example.test/docs',
            'timezone' => 'Asia/Manila',
        ])
        ->assertSessionHasNoErrors();

    $secondProject = $user->projects()->where('name', 'Second website')->sole();

    expect($user->projects()->count())->toBe(2)
        ->and($firstProject->refresh()->domains()->sole()->domain)->toBe($firstDomain)
        ->and($secondProject->domains()->sole()->domain)->toBe('second.example.test')
        ->and($secondProject->domains()->sole()->is_verified)->toBeFalse();
});

test('unfinished websites can be resumed and edited', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = Project::factory()->for($user)->create(['name' => 'Pending website']);
    $project->domains()->create(['domain' => 'pending.example.test']);

    $this->actingAs($user)
        ->get(route('websites.setup', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/Index')
            ->where('website.id', $project->id)
            ->where('website.domain', 'pending.example.test')
            ->where('mode', 'update')
            ->where('isVerified', false)
            ->where('websites.items.1.status', 'setup_required'),
        );

    $this->actingAs($user)
        ->patch(route('websites.update', $project), [
            'name' => 'Updated pending website',
            'url' => 'https://updated.example.test',
            'timezone' => 'UTC',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('websites.setup', $project));

    expect($project->refresh()->name)->toBe('Updated pending website')
        ->and($project->domains()->sole()->domain)->toBe('updated.example.test')
        ->and($project->domains()->sole()->is_verified)->toBeFalse();
});

test('users can select a verified website and dashboard follows it', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $secondProject = Project::factory()->for($user)->create(['name' => 'Second website']);
    $secondProject->domains()->create([
        'domain' => 'second.example.test',
        'is_verified' => true,
    ]);

    $this->actingAs($user)
        ->patch(route('websites.current', $secondProject))
        ->assertRedirect(route('dashboard'));

    expect($user->refresh()->current_project_id)->toBe($secondProject->id);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('project.id', $secondProject->id)
            ->where('project.name', 'Second website'),
        );
});

test('duplicate active domains are rejected when adding a website', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $domain = $user->projects()->sole()->domains()->sole()->domain;

    $this->actingAs($user)
        ->post(route('websites.store'), [
            'name' => 'Duplicate website',
            'url' => 'https://'.strtoupper($domain),
            'timezone' => 'UTC',
        ])
        ->assertSessionHasErrors(['url']);

    expect($user->projects()->count())->toBe(1);
});

test('duplicate active domains are rejected when editing an unfinished website', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $existingDomain = $user->projects()->sole()->domains()->sole()->domain;
    $project = Project::factory()->for($user)->create();
    $project->domains()->create(['domain' => 'pending.example.test']);

    $this->actingAs($user)
        ->patch(route('websites.update', $project), [
            'name' => 'Duplicate website',
            'url' => 'https://'.$existingDomain,
            'timezone' => 'UTC',
        ])
        ->assertSessionHasErrors(['url']);

    expect($project->refresh()->domains()->sole()->domain)->toBe('pending.example.test');
});

test('users cannot access or select another users website', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->withVerifiedWebsite()->create();
    $project = $otherUser->projects()->sole();

    $this->actingAs($user)
        ->get(route('websites.setup', $project))
        ->assertForbidden();

    $this->actingAs($user)
        ->patch(route('websites.current', $project))
        ->assertForbidden();
});

test('pending websites cannot be selected as current', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = Project::factory()->for($user)->create();
    $project->domains()->create(['domain' => 'pending.example.test']);

    $this->actingAs($user)
        ->patch(route('websites.current', $project))
        ->assertForbidden();
});
