<?php

use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to login from onboarding', function () {
    $this->get(route('onboarding.show'))->assertRedirect(route('login'));
});

test('users without a website can view onboarding', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('onboarding.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/Index')
            ->where('website', null)
            ->where('isVerified', false)
            ->has('timezones')
            ->has('trackerUrl'),
        );
});

test('onboarding keeps dashboard navigation beside logout and makes the logo clickable', function () {
    $layout = file_get_contents(resource_path('js/layouts/OnboardingLayout.vue'));
    $page = file_get_contents(resource_path('js/pages/onboarding/Index.vue'));

    expect($layout)
        ->toContain('aria-label="Peekchimp dashboard"')
        ->toContain('v-if="canReturnToDashboard"')
        ->toContain(':href="dashboard()"')
        ->toContain(':href="logout()"')
        ->toContain('<Button')
        ->toContain('as-child')
        ->toContain('variant="ghost"')
        ->toContain('size="sm"')
        ->toContain('<div class="flex items-center gap-1">');

    expect($page)
        ->not->toContain('Back to dashboard')
        ->not->toContain('canReturnToDashboard');
});

test('onboarding timezone select keeps its chevron inset from the edge', function () {
    $onboardingPage = file_get_contents(resource_path('js/pages/onboarding/Index.vue'));

    expect($onboardingPage)
        ->toContain('class="onboarding-select h-9')
        ->toContain('padding-right: 2.75rem;')
        ->toContain('background-position: right 0.875rem center;');
});

test('onboarding uses the visual stepper without redundant text progress', function () {
    $onboardingPage = file_get_contents(resource_path('js/pages/onboarding/Index.vue'));

    expect($onboardingPage)
        ->toContain('aria-label="Setup progress"')
        ->not->toContain('Step {{ stepNumber }} of 3')
        ->not->toContain('{{ stepNumber }}/3');
});

test('onboarding snippet uses an icon copy control with copied feedback', function () {
    $onboardingPage = file_get_contents(resource_path('js/pages/onboarding/Index.vue'));

    expect($onboardingPage)
        ->toContain('useClipboard')
        ->toContain('class="absolute top-2 right-2')
        ->toContain(':aria-label=')
        ->toContain("'Copied' : 'Copy snippet'")
        ->toContain('<Check')
        ->toContain('<Clipboard v-else')
        ->toContain('Copied')
        ->not->toContain('Copy snippet</Button>');
});

test('users can save their website details', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('onboarding.website.store'), [
            'name' => 'Peekchimp Docs',
            'url' => 'https://WWW.Example.test/docs',
            'timezone' => 'Asia/Manila',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('onboarding.show'));

    $project = $user->projects()->with('domains')->sole();

    expect($project->name)->toBe('Peekchimp Docs')
        ->and($project->timezone)->toBe('Asia/Manila')
        ->and($project->site_key)->toHaveLength(40)
        ->and($project->domains)->toHaveCount(1)
        ->and($project->domains->first()->domain)->toBe('www.example.test')
        ->and($project->domains->first()->is_verified)->toBeFalse();
});

test('editing website details preserves the site key and replaces the pending domain', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $oldDomain = $project->domains()->create(['domain' => 'old.example.test']);
    $siteKey = $project->site_key;

    $this->actingAs($user)
        ->post(route('onboarding.website.store'), [
            'name' => 'New name',
            'url' => 'https://new.example.test',
            'timezone' => 'UTC',
        ])
        ->assertSessionHasNoErrors();

    expect($project->refresh()->site_key)->toBe($siteKey)
        ->and($project->name)->toBe('New name')
        ->and($oldDomain->fresh())->toBeNull()
        ->and($project->domains()->sole()->domain)->toBe('new.example.test');
});

test('website details must contain a valid url and timezone', function (array $website, array $errors) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('onboarding.website.store'), $website)
        ->assertSessionHasErrors($errors);
})->with([
    'missing values' => [
        ['name' => '', 'url' => '', 'timezone' => ''],
        ['name', 'url', 'timezone'],
    ],
    'invalid url and timezone' => [
        ['name' => 'Example', 'url' => 'example.test', 'timezone' => 'Moon/Base'],
        ['url', 'timezone'],
    ],
]);

test('verified users cannot replace their website through onboarding', function () {
    $user = User::factory()->withVerifiedWebsite()->create();

    $this->actingAs($user)
        ->post(route('onboarding.website.store'), [
            'name' => 'Replacement',
            'url' => 'https://replacement.example.test',
            'timezone' => 'UTC',
        ])
        ->assertForbidden();
});

test('verified users see the completed onboarding state', function () {
    $user = User::factory()->withVerifiedWebsite()->create();

    $this->actingAs($user)
        ->get(route('onboarding.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/Index')
            ->where('isVerified', true),
        );
});

test('incomplete users cannot visit settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertRedirect(route('onboarding.show'));
});
