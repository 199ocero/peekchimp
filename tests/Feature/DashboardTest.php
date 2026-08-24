<?php

use App\Models\User;

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

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
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

test('dashboard prioritizes helpful plain-language metrics', function () {
    $dashboardPage = file_get_contents(resource_path('js/pages/Dashboard.vue'));
    $metricTrendCard = file_get_contents(resource_path('js/components/dashboard/MetricTrendCard.vue'));

    expect($dashboardPage.$metricTrendCard)
        ->toContain("label: 'Active now'")
        ->toContain('icon: Activity')
        ->toContain('Estimated unique visitors seen in the last five minutes.')
        ->toContain("label: 'Visitors'")
        ->toContain('icon: UserRound')
        ->toContain("detail: 'Estimated visitors during this period.'")
        ->toContain("label: 'Views'")
        ->toContain('icon: ScanEye')
        ->toContain("detail: 'Total page loads, including repeats.'")
        ->toContain("label: 'Bounce rate'")
        ->toContain('icon: LogOut')
        ->toContain('Share of visits with only one page view.')
        ->toContain("label: 'Pages per visitor'")
        ->toContain('icon: FileStack')
        ->toContain("detail: 'Average pages viewed by each visitor.'")
        ->toContain("label: 'Average visit time'")
        ->toContain('icon: Timer')
        ->toContain("detail: 'How long each visit lasted on average.'")
        ->toContain('At a glance')
        ->toContain('What stands out')
        ->toContain('Acquisition')
        ->toContain('Audience')
        ->toContain('AI referrals')
        ->toContain('individual answers stay private')
        ->toContain('{{ comparisonLabel }}: {{ previousValueLabel }}')
        ->toContain('metric-sparkline-reveal')
        ->toContain(':aria-label="`${label}: ${value}. More information`"')
        ->toContain('<TooltipContent class="max-w-80 !text-wrap" side="top">')
        ->not->toContain('{{ detail }} {{ comparisonLabel }}:')
        ->toContain('flex items-start gap-3')
        ->toContain('data-arrive min-w-0 flex-1')
        ->toContain('flex flex-col items-start gap-1')
        ->toContain("return 'text-success'")
        ->toContain("return 'text-destructive'")
        ->not->toContain('<Info')
        ->not->toContain('Visitor overview')
        ->not->toContain("label: 'Pageviews'")
        ->not->toContain("label: 'Unique visitors'");
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

test('app header keeps only compact website and account controls', function () {
    $appHeader = file_get_contents(resource_path('js/components/AppHeader.vue'));

    expect($appHeader)
        ->toContain('<WebsiteSwitcher />')
        ->toContain('aria-label="Peekchimp dashboard"')
        ->toContain('bg-sidebar-border/80')
        ->toContain('Change appearance')
        ->not->toContain('mainNavItems')
        ->not->toContain('NavigationMenu');
});
