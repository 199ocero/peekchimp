<?php

test('dashboard directs users to goals and AI settings from the correct places', function () {
    $dashboardPage = file_get_contents(resource_path('js/pages/Dashboard.vue'));
    $metricTrendCard = file_get_contents(resource_path('js/components/dashboard/MetricTrendCard.vue'));

    expect($dashboardPage.$metricTrendCard)
        ->toContain("actionLabel: 'Set up goals'")
        ->toContain('goalsIndex(props.project.id).url')
        ->toContain('<Link :href="editAi().url">AI settings</Link>')
        ->toContain('Generate AI insight')
        ->toContain('GenerateDashboardAiInsightsController().url')
        ->toContain('pollForAiInsights')
        ->toContain("only: ['analytics', 'aiInsightRun']")
        ->toContain('AI recommendations updated.')
        ->toContain('No AI job completed.')
        ->toContain('AI-enhanced recommendation')
        ->toContain('hasAiEnhancedInsights')
        ->toContain("accent: 'violet' as const")
        ->toContain("accent: 'rose' as const")
        ->toContain('!border-emerald-500/25')
        ->toContain('vs previous · {{ previousValueLabel }}');
});
