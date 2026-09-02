<?php

use App\Models\AnalyticsEvent;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('authenticated users without a verified website are redirected to onboarding', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('onboarding.show'));

    expect($user->projects()->count())->toBe(0);
});

test('authenticated users with a verified website can visit the streamlined dashboard', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    AnalyticsEvent::factory()->count(3)->create([
        'project_id' => $project->getKey(),
        'event_name' => 'page_view',
        'occurred_at' => now()->subDay(),
    ]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('analytics.metrics')
            ->has('analytics.timeseries')
            ->missing('analytics.insights')
            ->missing('analytics.actionableInsights')
            ->missing('analytics.whatChanged')
            ->missing('aiInsightRun'));
});

test('dashboard keeps the traffic visualization without legacy insight cards', function () {
    $dashboardPage = file_get_contents(resource_path('js/pages/Dashboard.vue'));
    $trafficChart = file_get_contents(resource_path('js/components/dashboard/DashboardTrafficChart.vue'));

    expect($dashboardPage.$trafficChart)
        ->toContain('aria-label="Traffic over time"')
        ->toContain('Visitors and views over time')
        ->toContain('traffic-line')
        ->toContain('traffic-line-arrive')
        ->toContain('Top pages')
        ->not->toContain('Analytics insights')
        ->not->toContain('Top insights')
        ->not->toContain('Generate AI insight')
        ->not->toContain('aiInsightRun')
        ->not->toContain('installationSnippet');
});

test('dashboard follows the reference hierarchy with useful plain-language metrics', function () {
    $dashboardPage = file_get_contents(resource_path('js/pages/Dashboard.vue'));
    $metricTrendCard = file_get_contents(resource_path('js/components/dashboard/MetricTrendCard.vue'));
    $trafficChart = file_get_contents(resource_path('js/components/dashboard/DashboardTrafficChart.vue'));

    expect($dashboardPage.$metricTrendCard.$trafficChart)
        ->toContain("label: 'Visitors'")
        ->toContain('icon: UsersRound')
        ->toContain("label: 'Views'")
        ->toContain('icon: ScanEye')
        ->toContain("label: 'Conversions'")
        ->toContain('Traffic over time')
        ->toContain('Top pages')
        ->toContain('Acquisition')
        ->toContain('Audience')
        ->toContain('AI referrals')
        ->toContain('<MetricTrendCard')
        ->toContain('comparisonAvailable')
        ->toContain("activePageTab = ref<'top' | 'entry' | 'exit'>('top')")
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

test('visitor map starts on a visitor and handles token scope failures', function () {
    $visitorMap = file_get_contents(resource_path('js/components/dashboard/DashboardVisitorMap.vue'));

    expect($visitorMap)
        ->toContain('center: markerPosition(initialVisitor)')
        ->toContain('/403|access token|scope/i.test(error.message)');
});

test('app header includes the reference navigation and compact account controls', function () {
    $appHeader = file_get_contents(resource_path('js/components/AppHeader.vue'));

    expect($appHeader)
        ->toContain('<WebsiteSwitcher />')
        ->toContain('aria-label="Peekchimp dashboard"')
        ->toContain('bg-sidebar-border/80')
        ->toContain('<AppearanceMenu />')
        ->toContain("label: 'Overview'")
        ->toContain("label: 'Goals'")
        ->not->toContain("label: 'AI visibility'")
        ->not->toContain('mainNavItems')
        ->not->toContain('NavigationMenu');
});
