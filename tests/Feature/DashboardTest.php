<?php

use App\Models\AiInsightRun;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users without a verified website are redirected to onboarding', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('onboarding.show'));
    expect($user->projects()->count())->toBe(0);
});

test('authenticated users with a verified website can visit the dashboard', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    $run = AiInsightRun::factory()->for($project)->create([
        'status' => 'failed',
        'error' => 'Provider unavailable.',
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('aiInsightRun.status', 'failed')
        ->where('aiInsightRun.error', 'Provider unavailable.')
        ->where('aiInsightRun.updatedAt', $run->updated_at->toIso8601String()));
});

test('dashboard keeps the activity visualization without the installation prompt', function () {
    $dashboardPage = file_get_contents(resource_path('js/pages/Dashboard.vue'));
    $trafficChart = file_get_contents(resource_path('js/components/dashboard/DashboardTrafficChart.vue'));

    expect($dashboardPage.$trafficChart)
        ->toContain('Visitors and views over time')
        ->toContain('traffic-line')
        ->toContain('traffic-line-arrive')
        ->not->toContain('stroke-dasharray: 1')
        ->not->toContain('Add Peekchimp to your site')
        ->not->toContain('installationSnippet');
});

test('dashboard follows the reference hierarchy with useful plain-language metrics', function () {
    $dashboardPage = file_get_contents(resource_path('js/pages/Dashboard.vue'));
    $metricTrendCard = file_get_contents(resource_path('js/components/dashboard/MetricTrendCard.vue'));
    $trafficChart = file_get_contents(resource_path('js/components/dashboard/DashboardTrafficChart.vue'));

    expect($dashboardPage.$metricTrendCard.$trafficChart)
        ->toContain("label: 'Visitors'")
        ->toContain('icon: UsersRound')
        ->toContain('Estimated unique visitors during the selected period.')
        ->toContain("label: 'Views'")
        ->toContain('icon: ScanEye')
        ->toContain('Total page loads, including repeat views.')
        ->toContain("label: 'Bounce rate'")
        ->toContain('icon: GitBranch')
        ->toContain("label: 'Avg. visit time'")
        ->toContain('icon: Timer')
        ->toContain("label: 'Conversions'")
        ->toContain('icon: Target')
        ->toContain('Analytics insights')
        ->toContain('Top insights')
        ->toContain('Traffic over time')
        ->toContain('Top pages')
        ->toContain('Acquisition')
        ->toContain('Audience')
        ->toContain('AI referrals')
        ->toContain('Building baseline')
        ->toContain('Useful current-period facts')
        ->toContain('<MetricTrendCard')
        ->toContain('comparisonAvailable')
        ->toContain('Collecting a previous matching period')
        ->toContain("return 'text-success'")
        ->toContain("return 'text-destructive'")
        ->toContain('metric-sparkline-reveal')
        ->toContain("activePageTab = ref<'top' | 'entry' | 'exit'>('top')")
        ->toContain('@click="showAllPages = !showAllPages"')
        ->toContain(':href="editAi().url"')
        ->not->toContain('View all changes')
        ->not->toContain('Open one area at a time');
});

test('dashboard uses local logos with readable fallbacks', function () {
    $brandLogo = file_get_contents(resource_path('js/components/dashboard/BrandLogo.vue'));

    expect($brandLogo)
        ->toContain("chatgpt: 'openai'")
        ->toContain("anthropic: 'claude'")
        ->toContain("'google chrome': 'chrome'")
        ->toContain("logoName === 'safari'")
        ->toContain("logoName === 'edge'")
        ->toContain('<Globe2 v-else');
});

test('dashboard date range select keeps its chevron inset from the edge', function () {
    $dashboardPage = file_get_contents(resource_path('js/pages/Dashboard.vue'));
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($dashboardPage.$appCss)
        ->toContain('class="select-with-chevron h-9')
        ->toContain('padding-inline: 0.75rem 2.75rem;')
        ->toContain('background-position: right 0.875rem center;');
});

test('app header includes the reference navigation and compact account controls', function () {
    $appHeader = file_get_contents(resource_path('js/components/AppHeader.vue'));

    expect($appHeader)
        ->toContain('<WebsiteSwitcher />')
        ->toContain('aria-label="Peekchimp dashboard"')
        ->toContain('bg-sidebar-border/80')
        ->toContain('<AppearanceMenu />')
        ->toContain("label: 'Overview'")
        ->toContain("label: 'AI visibility'")
        ->toContain("label: 'Goals'")
        ->not->toContain('mainNavItems')
        ->not->toContain('NavigationMenu');
});
