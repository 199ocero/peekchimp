<?php

use App\Models\AiVisibilityScan;
use App\Models\Project;
use App\Models\User;
use App\Models\WebsitePageSnapshot;
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
            ->where('website.growthContext.audience', '')
            ->where('website.growthContext.primary_conversion_goals', [])
            ->where('websiteCrawl.status', 'not_started')
            ->where('websiteCrawl.pageCount', 0)
            ->where('publicSharing.enabled', false)
            ->where('publicSharing.url', null)
            ->has('publicSharing.sections', count(Project::defaultPublicDashboardSections())),
        );
});

test('website settings expose the latest crawl status and snapshot count', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    $scan = AiVisibilityScan::factory()->for($project)->create([
        'status' => 'completed',
        'completed_at' => now(),
    ]);
    $crawledAt = now();
    WebsitePageSnapshot::factory()
        ->for($project)
        ->for($scan, 'scan')
        ->create(['crawled_at' => $crawledAt]);

    $this->actingAs($user)
        ->get(route('websites.settings.edit', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('websiteCrawl.status', 'completed')
            ->where('websiteCrawl.pageCount', 1)
            ->where('websiteCrawl.lastCrawledAt', $crawledAt->toIso8601String()),
        );
});

test('owners can save website growth context without replacing other project settings', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    $project->update([
        'settings' => [
            'public_dashboard' => ['sections' => ['metrics', 'traffic']],
        ],
    ]);

    $this->actingAs($user)
        ->patch(route('websites.settings.update', $project), [
            'name' => $project->name,
            'timezone' => $project->timezone,
            'growth_context' => [
                'audience' => 'Small software teams that need privacy-safe analytics.',
                'products_services' => 'A lightweight website analytics product.',
                'value_proposition' => 'Know what to improve without tracking people.',
                'brand_voice' => 'Direct, practical, and calm.',
                'primary_conversion_goals' => ['Start a trial', 'Book a demo'],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('websites.settings.edit', $project));

    expect($project->refresh()->growthContext())
        ->toMatchArray([
            'audience' => 'Small software teams that need privacy-safe analytics.',
            'products_services' => 'A lightweight website analytics product.',
            'value_proposition' => 'Know what to improve without tracking people.',
            'brand_voice' => 'Direct, practical, and calm.',
            'primary_conversion_goals' => ['Start a trial', 'Book a demo'],
        ])
        ->and($project->publicDashboardSections())->toBe(['metrics', 'traffic']);
});

test('owners can enable privacy-safe behavior signals from website settings', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();

    $this->actingAs($user)
        ->get(route('websites.settings.edit', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('website.autocaptureEnabled', false));

    $this->actingAs($user)
        ->patch(route('websites.settings.update', $project), [
            'name' => $project->name,
            'timezone' => $project->timezone,
            'autocapture_enabled' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('websites.settings.edit', $project));

    expect($project->refresh()->isAutocaptureEnabled())->toBeTrue();
});

test('website growth context is bounded', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();

    $this->actingAs($user)
        ->patch(route('websites.settings.update', $project), [
            'name' => $project->name,
            'timezone' => $project->timezone,
            'growth_context' => [
                'audience' => str_repeat('a', 2001),
                'primary_conversion_goals' => array_fill(0, 11, 'Convert'),
            ],
        ])
        ->assertSessionHasErrors([
            'growth_context.audience',
            'growth_context.primary_conversion_goals',
        ]);
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
