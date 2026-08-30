<?php

test('dashboard directs users to goals without legacy AI insight controls', function () {
    $dashboardPage = file_get_contents(resource_path('js/pages/Dashboard.vue'));
    $metricTrendCard = file_get_contents(resource_path('js/components/dashboard/MetricTrendCard.vue'));

    expect($dashboardPage.$metricTrendCard)
        ->toContain("actionLabel: 'Set up goals'")
        ->toContain('goalsIndex(props.project.id).url')
        ->toContain("only: ['analytics', 'searchPerformance']")
        ->toContain('aria-label="Traffic over time"')
        ->not->toContain('Generate AI insight')
        ->not->toContain('aiInsightRun')
        ->not->toContain('Analytics insights')
        ->not->toContain('Top insights')
        ->toContain("accent: 'violet' as const")
        ->toContain("accent: 'rose' as const")
        ->toContain('vs previous · {{ previousValueLabel }}');
});
