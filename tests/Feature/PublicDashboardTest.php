<?php

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function publicDashboardUrl(Project $project): string
{
    return route('shared.dashboard.show', ['token' => $project->public_share_token]);
}

test('public dashboards are disabled until an owner enables sharing', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();

    $this->get(route('shared.dashboard.show', ['token' => str_repeat('a', 64)]))
        ->assertNotFound();

    $this->actingAs($user)
        ->patch(route('websites.sharing.update', $project), [
            'enabled' => true,
            'sections' => ['metrics', 'traffic'],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('websites.settings.edit', $project));

    expect($project->refresh()->public_share_token)->not->toBeNull()
        ->and($project->public_share_token_hash)->toBe(hash('sha256', $project->public_share_token));
});

test('public dashboards expose only selected sections', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    AnalyticsEvent::factory()->count(2)->create(['project_id' => $project->id]);
    AnalyticsSession::factory()->create(['project_id' => $project->id]);

    $this->actingAs($user)
        ->patch(route('websites.sharing.update', $project), [
            'enabled' => true,
            'sections' => ['metrics', 'traffic'],
        ])
        ->assertSessionHasNoErrors();

    $response = $this->get(publicDashboardUrl($project->refresh()));

    $response
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/Dashboard')
            ->where('project.name', $project->name)
            ->where('visibleSections', ['metrics', 'traffic'])
            ->has('analytics.metrics')
            ->missing('analytics.metrics.activeVisitors')
            ->missing('analytics.metrics.sessions')
            ->has('analytics.traffic')
            ->missing('analytics.pages')
            ->missing('analytics.acquisition')
            ->missing('analytics.audience')
            ->missing('analytics.insights'),
        );
});

test('public dashboards use the card-based dual-theme analytics layout', function () {
    $dashboard = file_get_contents(resource_path('js/pages/public/Dashboard.vue'));
    $metricCard = file_get_contents(resource_path('js/components/dashboard/MetricTrendCard.vue'));

    expect($dashboard.$metricCard)
        ->toContain('<AppearanceMenu />')
        ->toContain('<MetricTrendCard')
        ->toContain('comparisonAvailable')
        ->toContain('<DashboardTrafficChart')
        ->toContain("hasSection('pages')")
        ->toContain("hasSection('acquisition')")
        ->toContain("hasSection('audience')")
        ->not->toContain('Shared dashboard analysis')
        ->not->toContain('<DashboardOverview');
});

test('public links can be disabled and re-enabled without changing their URL', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();

    $this->actingAs($user)
        ->patch(route('websites.sharing.update', $project), [
            'enabled' => true,
            'sections' => ['metrics'],
        ]);

    $project->refresh();
    $token = $project->public_share_token;

    $this->actingAs($user)
        ->patch(route('websites.sharing.update', $project), [
            'enabled' => false,
            'sections' => ['metrics'],
        ])
        ->assertSessionHasNoErrors();

    $this->get(route('shared.dashboard.show', ['token' => $token]))->assertNotFound();

    $this->actingAs($user)
        ->patch(route('websites.sharing.update', $project), [
            'enabled' => true,
            'sections' => ['metrics'],
        ])
        ->assertSessionHasNoErrors();

    expect($project->refresh()->public_share_token)->toBe($token);
    $this->get(route('shared.dashboard.show', ['token' => $token]))->assertOk();
});

test('rotating a public link invalidates the previous URL immediately', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();

    $this->actingAs($user)
        ->patch(route('websites.sharing.update', $project), [
            'enabled' => true,
            'sections' => ['metrics'],
        ]);

    $oldToken = $project->refresh()->public_share_token;

    $this->actingAs($user)
        ->post(route('websites.sharing.rotate', $project))
        ->assertRedirect(route('websites.settings.edit', $project));

    $newToken = $project->refresh()->public_share_token;

    expect($newToken)->not->toBe($oldToken);
    $this->get(route('shared.dashboard.show', ['token' => $oldToken]))->assertNotFound();
    $this->get(route('shared.dashboard.show', ['token' => $newToken]))->assertOk();
});

test('public sharing requires a verified website and valid sections', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = Project::factory()->for($user)->create();
    $project->domains()->create(['domain' => 'pending.example.test']);

    $this->actingAs($user)
        ->patch(route('websites.sharing.update', $project), [
            'enabled' => true,
            'sections' => ['metrics'],
        ])
        ->assertForbidden();

    $verifiedProject = $user->projects()->whereKey($user->current_project_id)->firstOrFail();

    $this->actingAs($user)
        ->patch(route('websites.sharing.update', $verifiedProject), [
            'enabled' => true,
            'sections' => [],
        ])
        ->assertSessionHasErrors(['sections']);
});
