<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Activity,
    CircleCheck,
    CircleGauge,
    FileStack,
    FileText,
    Lightbulb,
    Link2,
    LogOut,
    MoreHorizontal,
    RefreshCw,
    ScanEye,
    Settings,
    Timer,
    TriangleAlert,
    UserRound,
    UsersRound,
    Waypoints,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import DashboardBreakdownCard from '@/components/dashboard/DashboardBreakdownCard.vue';
import DashboardOverview from '@/components/dashboard/DashboardOverview.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { dashboard } from '@/routes';
import { store as insightActionStore } from '@/routes/insights/actions';
import { aiTraffic as aiTrafficRoute } from '@/routes/websites';
import { index as actionsIndex } from '@/routes/websites/actions';
import { show as aiVisibilityShow } from '@/routes/websites/ai-visibility';
import { index as funnelsIndex } from '@/routes/websites/funnels';
import { index as goalsIndex } from '@/routes/websites/goals';
import { edit as editWebsiteSettings } from '@/routes/websites/settings';

type Breakdown = { label: string; value: number };
type CountryVisits = {
    total: number;
    unknown: number;
    countries: Array<{ code: string; visits: number }>;
};
type MetricTrend = {
    previous: number;
    change: number | null;
    series: number[];
};
type DashboardInsight = {
    type:
        | 'insufficient_data'
        | 'high_single_page_rate'
        | 'unattributed_traffic'
        | 'healthy_engagement';
    tone: 'neutral' | 'attention' | 'positive';
    value: number;
};
type ActionableInsight = {
    id: number;
    fingerprint: string;
    category: string;
    label: string;
    metric: string;
    current_value: number;
    previous_value: number;
    percentage_change: number | null;
    confidence: string;
    summary: string;
    explanation: string;
    recommendation: string;
    severity: string;
    actions: Array<{ key: string; label: string; description: string }>;
};
type Analytics = {
    range: {
        key: string;
        label: string;
        from: string;
        to: string;
        interval: 'hour' | 'day';
    };
    metrics: {
        pageviews: number;
        visitors: number;
        activeVisitors: number;
        sessions: number;
        bounceRate: number;
        averageDuration: number;
        viewsPerVisitor: number;
        conversions: number;
        conversionRate: number;
    };
    metricTrends: {
        activeVisitors: MetricTrend;
        visitors: MetricTrend;
        sessions: MetricTrend;
        pageviews: MetricTrend;
        bounceRate: MetricTrend;
        viewsPerVisitor: MetricTrend;
        averageDuration: MetricTrend;
    };
    timeseries: Array<{
        label: string;
        pageviews: number;
        visitors: number;
    }>;
    topPages: Breakdown[];
    entryPages: Breakdown[];
    exitPages: Breakdown[];
    referrers: Breakdown[];
    sources?: Breakdown[];
    countryVisits: CountryVisits;
    devices: Breakdown[];
    browsers: Breakdown[];
    operatingSystems: Breakdown[];
    campaigns: Breakdown[];
    mediums: Breakdown[];
    aiReferrals: { totalVisits: number; sources: Breakdown[] };
    insights: DashboardInsight[];
    whatChanged: ActionableInsight[];
    actionableInsights: ActionableInsight[];
    importantActions: Array<{
        id: number;
        name: string;
        views: number;
        clicks: number;
        ctr: number;
    }>;
    goals: Array<{
        id: number;
        name: string;
        conversions: number;
        conversionRate: number;
        trend: { previous: number; change: number | null };
    }>;
    aiTraffic: {
        visitors: number;
        visits: number;
        pageviews: number;
        conversions: number;
        sources: Breakdown[];
        pages: Breakdown[];
    };
};

const props = defineProps<{
    project: {
        id: number;
        name: string;
        siteKey: string;
        timezone: string;
        domains: string[];
    };
    analytics: Analytics;
    filters: Record<string, string | undefined>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const selectedRange = ref(props.analytics.range.key);
const isManualRefreshing = ref(false);
const isChangingRange = ref(false);
let refreshTimer: number | undefined;

const metricCards = computed<
    Array<{
        label: string;
        value: string | number;
        detail: string;
        icon: Component;
        currentValue: number;
        previousValue: number;
        previousValueLabel: string;
        change: number | null;
        series: number[];
        comparisonLabel?: string;
        inverse?: boolean;
        isLive?: boolean;
    }>
>(() => [
    {
        label: 'Active now',
        value: formatNumber(props.analytics.metrics.activeVisitors),
        detail: 'Estimated unique visitors seen in the last five minutes. This ignores the selected date range.',
        icon: Activity,
        currentValue: props.analytics.metrics.activeVisitors,
        previousValue: props.analytics.metricTrends.activeVisitors.previous,
        previousValueLabel: formatNumber(
            props.analytics.metricTrends.activeVisitors.previous,
        ),
        change: props.analytics.metricTrends.activeVisitors.change,
        series: props.analytics.metricTrends.activeVisitors.series,
        comparisonLabel: 'Previous five minutes',
        isLive: true,
    },
    {
        label: 'Visitors',
        value: formatNumber(props.analytics.metrics.visitors),
        detail: 'Estimated visitors during this period.',
        icon: UserRound,
        currentValue: props.analytics.metrics.visitors,
        previousValue: props.analytics.metricTrends.visitors.previous,
        previousValueLabel: formatNumber(
            props.analytics.metricTrends.visitors.previous,
        ),
        change: props.analytics.metricTrends.visitors.change,
        series: props.analytics.metricTrends.visitors.series,
    },
    {
        label: 'Visits',
        value: formatNumber(props.analytics.metrics.sessions),
        detail: 'Total sessions during this period.',
        icon: UsersRound,
        currentValue: props.analytics.metrics.sessions,
        previousValue: props.analytics.metricTrends.sessions.previous,
        previousValueLabel: formatNumber(
            props.analytics.metricTrends.sessions.previous,
        ),
        change: props.analytics.metricTrends.sessions.change,
        series: props.analytics.metricTrends.sessions.series,
    },
    {
        label: 'Views',
        value: formatNumber(props.analytics.metrics.pageviews),
        detail: 'Total page loads, including repeats.',
        icon: ScanEye,
        currentValue: props.analytics.metrics.pageviews,
        previousValue: props.analytics.metricTrends.pageviews.previous,
        previousValueLabel: formatNumber(
            props.analytics.metricTrends.pageviews.previous,
        ),
        change: props.analytics.metricTrends.pageviews.change,
        series: props.analytics.metricTrends.pageviews.series,
    },
    {
        label: 'Bounce rate',
        value: formatPercentage(props.analytics.metrics.bounceRate),
        detail: 'Share of visits with only one page view. Lower is not always better.',
        icon: LogOut,
        currentValue: props.analytics.metrics.bounceRate,
        previousValue: props.analytics.metricTrends.bounceRate.previous,
        previousValueLabel: formatPercentage(
            props.analytics.metricTrends.bounceRate.previous,
        ),
        change: props.analytics.metricTrends.bounceRate.change,
        series: props.analytics.metricTrends.bounceRate.series,
        inverse: true,
    },
    {
        label: 'Pages per visitor',
        value: props.analytics.metrics.viewsPerVisitor,
        detail: 'Average pages viewed by each visitor.',
        icon: FileStack,
        currentValue: props.analytics.metrics.viewsPerVisitor,
        previousValue: props.analytics.metricTrends.viewsPerVisitor.previous,
        previousValueLabel: formatDecimal(
            props.analytics.metricTrends.viewsPerVisitor.previous,
        ),
        change: props.analytics.metricTrends.viewsPerVisitor.change,
        series: props.analytics.metricTrends.viewsPerVisitor.series,
    },
    {
        label: 'Average visit time',
        value: formatDuration(props.analytics.metrics.averageDuration),
        detail: 'How long each visit lasted on average.',
        icon: Timer,
        currentValue: props.analytics.metrics.averageDuration,
        previousValue: props.analytics.metricTrends.averageDuration.previous,
        previousValueLabel: formatDuration(
            props.analytics.metricTrends.averageDuration.previous,
        ),
        change: props.analytics.metricTrends.averageDuration.change,
        series: props.analytics.metricTrends.averageDuration.series,
    },
]);

const primaryMetrics = computed(() => [
    {
        ...metricCards.value.find((metric) => metric.label === 'Visitors')!,
        id: 'visitors',
    },
    {
        ...metricCards.value.find((metric) => metric.label === 'Visits')!,
        id: 'visits',
    },
    {
        ...metricCards.value.find((metric) => metric.label === 'Views')!,
        id: 'views',
    },
    {
        id: 'conversions',
        label: 'Conversions',
        value: formatNumber(props.analytics.metrics.conversions),
        detail: 'Completed goals during this period.',
        currentValue: props.analytics.metrics.conversions,
    },
]);

const secondaryMetrics = computed(() => [
    {
        ...metricCards.value.find((metric) => metric.label === 'Bounce rate')!,
        id: 'bounce-rate',
    },
    {
        ...metricCards.value.find(
            (metric) => metric.label === 'Pages per visitor',
        )!,
        id: 'pages-per-visitor',
    },
    {
        ...metricCards.value.find(
            (metric) => metric.label === 'Average visit time',
        )!,
        id: 'average-visit-time',
    },
    {
        id: 'conversion-rate',
        label: 'Conversion rate',
        value: formatPercentage(props.analytics.metrics.conversionRate),
        detail: 'Share of visits that completed a goal.',
        currentValue: props.analytics.metrics.conversionRate,
    },
]);

const insightCards = computed(() =>
    props.analytics.insights.map((insight) => {
        if (insight.type === 'high_single_page_rate') {
            return {
                ...insight,
                title: `${formatPercentage(insight.value)} of visits stop after one page`,
                description:
                    'Review your top landing page and make its next action easier to find.',
                icon: TriangleAlert,
            };
        }

        if (insight.type === 'unattributed_traffic') {
            return {
                ...insight,
                title: `${formatPercentage(insight.value)} of views have no source`,
                description:
                    'Add UTM tags to links you share so campaigns are easier to compare.',
                icon: Link2,
            };
        }

        if (insight.type === 'healthy_engagement') {
            return {
                ...insight,
                title: 'No obvious engagement issues',
                description:
                    'This range does not show either pattern Peekchimp can reliably flag.',
                icon: CircleCheck,
            };
        }

        return {
            ...insight,
            title: 'More visits will sharpen these tips',
            description: `This range has ${formatNumber(insight.value)} of the 20 visits needed for reliable suggestions.`,
            icon: CircleGauge,
        };
    }),
);

const acquisitionTabs = computed(() => [
    {
        id: 'sources',
        label: 'Sources',
        description: 'Search, other websites, and direct visits',
        emptyMessage: 'No traffic sources in this period.',
        items: props.analytics.sources ?? props.analytics.referrers,
        kind: 'source' as const,
        total: totalOf(props.analytics.sources ?? props.analytics.referrers),
    },
    {
        id: 'campaigns',
        label: 'Campaigns',
        description: 'Visits grouped by campaign tags',
        emptyMessage: 'No tagged campaign visits in this period.',
        items: props.analytics.campaigns,
        kind: 'campaign' as const,
        total: totalOf(props.analytics.campaigns),
    },
    {
        id: 'mediums',
        label: 'Mediums',
        description: 'Organic, social, email, and other tags',
        emptyMessage: 'No traffic mediums in this period.',
        items: props.analytics.mediums,
        kind: 'campaign' as const,
        total: totalOf(props.analytics.mediums),
    },
    {
        id: 'ai',
        label: 'AI referrals',
        description: 'Links and tags only; individual answers stay private',
        emptyMessage: 'No AI referral visits in this period.',
        items: props.analytics.aiReferrals.sources,
        kind: 'ai' as const,
        total: props.analytics.aiReferrals.totalVisits,
    },
]);

const actionableInsights = computed(
    () =>
        props.analytics.actionableInsights ?? props.analytics.whatChanged ?? [],
);
const primaryActionableInsight = computed(
    () => actionableInsights.value[0] ?? null,
);
const focusStatus = computed(() => insightCards.value[0] ?? null);
const activeInsightAction = ref<string | null>(null);

function runInsightAction(
    insight: ActionableInsight,
    action: { key: string; label: string; description: string },
): void {
    activeInsightAction.value = insight.id + ':' + action.key;
    router.post(
        insightActionStore(insight.id).url,
        { action: action.key },
        {
            preserveScroll: true,
            onFinish: () => {
                activeInsightAction.value = null;
            },
        },
    );
}

const audienceTabs = computed(() => [
    {
        id: 'countries',
        label: 'Countries',
        description: 'Approximate country for each visit',
        emptyMessage: 'No known country visits in this period.',
        items: [
            ...props.analytics.countryVisits.countries.map((country) => ({
                label: country.code,
                value: country.visits,
            })),
            ...(props.analytics.countryVisits.unknown > 0
                ? [
                      {
                          label: 'Unknown',
                          value: props.analytics.countryVisits.unknown,
                      },
                  ]
                : []),
        ],
        kind: 'country' as const,
        total: props.analytics.countryVisits.total,
    },
    {
        id: 'devices',
        label: 'Devices',
        description: 'Phones, tablets, and computers used to visit',
        emptyMessage: 'No device data in this period.',
        items: props.analytics.devices,
        kind: 'device' as const,
        total: totalOf(props.analytics.devices),
    },
    {
        id: 'browsers',
        label: 'Browsers',
        description: 'Web browsers used to view your site',
        emptyMessage: 'No browser data in this period.',
        items: props.analytics.browsers,
        kind: 'browser' as const,
        total: totalOf(props.analytics.browsers),
    },
    {
        id: 'operating-systems',
        label: 'OS',
        description: 'Operating systems used to visit your site',
        emptyMessage: 'No operating-system data in this period.',
        items: props.analytics.operatingSystems,
        kind: 'os' as const,
        total: totalOf(props.analytics.operatingSystems),
    },
]);

type AnalysisTab =
    'insights' | 'pages' | 'acquisition' | 'audience' | 'outcomes';
type PageTab = 'popular' | 'entry' | 'exit';

const activeAnalysisTab = ref<AnalysisTab>('pages');
const activePageTab = ref<PageTab>('popular');
const analysisTabs: Array<{ id: AnalysisTab; label: string }> = [
    { id: 'insights', label: 'Insights' },
    { id: 'pages', label: 'Pages' },
    { id: 'acquisition', label: 'Acquisition' },
    { id: 'audience', label: 'Audience' },
    { id: 'outcomes', label: 'Outcomes' },
];
const pageTabs: Array<{ id: PageTab; label: string }> = [
    { id: 'popular', label: 'Popular' },
    { id: 'entry', label: 'Entry' },
    { id: 'exit', label: 'Exit' },
];
const activePageItems = computed(() => {
    if (activePageTab.value === 'entry') {
        return props.analytics.entryPages;
    }

    if (activePageTab.value === 'exit') {
        return props.analytics.exitPages;
    }

    return props.analytics.topPages;
});
const activePageTotal = computed(() =>
    activePageTab.value === 'popular'
        ? props.analytics.metrics.pageviews
        : totalOf(activePageItems.value),
);
const activePageDescription = computed(() => {
    if (activePageTab.value === 'entry') {
        return 'Where visits begin';
    }

    if (activePageTab.value === 'exit') {
        return 'Where visits end';
    }

    return 'Pages with the most views';
});

function activateAdjacentTab(event: KeyboardEvent, offset: number): void {
    const currentButton = event.currentTarget as HTMLButtonElement;
    const tabList = currentButton.parentElement;
    const buttons = Array.from(
        tabList?.querySelectorAll<HTMLButtonElement>('[role="tab"]') ?? [],
    );
    const currentIndex = buttons.indexOf(currentButton);

    if (currentIndex < 0 || buttons.length === 0) {
        return;
    }

    const nextIndex = (currentIndex + offset + buttons.length) % buttons.length;
    buttons[nextIndex]?.focus();
    buttons[nextIndex]?.click();
}

function formatNumber(value: number): string {
    return new Intl.NumberFormat().format(value);
}

function formatPercentage(value: number): string {
    return `${new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(value)}%`;
}

function formatDecimal(value: number): string {
    return new Intl.NumberFormat(undefined, {
        maximumFractionDigits: 2,
    }).format(value);
}

function formatDuration(seconds: number): string {
    if (seconds < 60) {
        return `${seconds}s`;
    }

    return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
}

function totalOf(items: Breakdown[]): number {
    return items.reduce((total, item) => total + item.value, 0);
}

function percentageOf(value: number, total: number): number {
    if (total <= 0) {
        return 0;
    }

    return Math.max(4, Math.min(100, (value / total) * 100));
}

function loadRange(range: string): void {
    selectedRange.value = range;
    isChangingRange.value = true;
    router.get(
        dashboard(),
        { range },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['analytics', 'filters'],
            onFinish: () => {
                isChangingRange.value = false;
            },
        },
    );
}

function refresh(): void {
    isManualRefreshing.value = true;
    router.reload({
        only: ['analytics'],
        onFinish: () => {
            isManualRefreshing.value = false;
        },
    });
}

watch(
    () => props.analytics.range.key,
    (rangeKey) => {
        selectedRange.value = rangeKey;
    },
);

onMounted(() => {
    refreshTimer = window.setInterval(
        () => router.reload({ only: ['analytics'] }),
        30000,
    );
});

onBeforeUnmount(() => {
    if (refreshTimer !== undefined) {
        window.clearInterval(refreshTimer);
    }
});
</script>

<template>
    <TooltipProvider :delay-duration="100">
        <Head title="Dashboard" />

        <div
            class="mx-auto flex w-full max-w-6xl flex-col gap-5 px-4 pt-6 pb-24 sm:gap-6 sm:px-6 sm:pt-8"
        >
            <header
                class="flex flex-col justify-between gap-5 sm:flex-row sm:items-center"
            >
                <div class="min-w-0">
                    <p
                        class="mb-2 text-xs tracking-[0.12em] text-muted-foreground uppercase"
                    >
                        Website overview
                    </p>
                    <h1
                        class="truncate text-3xl font-medium tracking-[-0.045em] sm:text-4xl"
                    >
                        {{ project.name }}
                    </h1>
                    <p
                        class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
                    >
                        <span class="truncate">{{ project.domains[0] }}</span>
                        <span aria-hidden="true">·</span>
                        <span class="shrink-0">{{ project.timezone }}</span>
                        <span aria-hidden="true">·</span>
                        <span class="inline-flex items-center gap-1.5">
                            <span
                                class="size-1.5 rounded-full bg-success"
                                aria-hidden="true"
                            />
                            Tracking is active
                        </span>
                    </p>
                </div>

                <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                    <span
                        class="inline-flex h-9 items-center gap-2 rounded-full bg-secondary px-3 text-xs text-muted-foreground"
                    >
                        <span class="tracking-status" aria-hidden="true">
                            <span />
                        </span>
                        <strong
                            class="font-medium text-foreground tabular-nums"
                        >
                            {{ formatNumber(analytics.metrics.activeVisitors) }}
                        </strong>
                        active now
                    </span>
                    <select
                        v-model="selectedRange"
                        class="select-with-chevron h-9 min-w-0 flex-1 cursor-pointer rounded-md border border-input bg-card text-sm font-medium transition-colors outline-none hover:bg-accent/50 focus:border-ring focus:ring-2 focus:ring-ring/30 sm:flex-none"
                        aria-label="Date range"
                        :disabled="isChangingRange"
                        @change="loadRange(selectedRange)"
                    >
                        <option value="today">Today</option>
                        <option value="yesterday">Yesterday</option>
                        <option value="7d">Last 7 days</option>
                        <option value="30d">Last 30 days</option>
                        <option value="month">This month</option>
                    </select>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                variant="outline"
                                size="icon"
                                :disabled="isManualRefreshing"
                                aria-label="Refresh dashboard"
                                @click="refresh"
                            >
                                <RefreshCw
                                    :class="[
                                        'size-4',
                                        isManualRefreshing && 'animate-spin',
                                    ]"
                                />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent side="bottom">
                            Refresh now. Data also updates every 30 seconds.
                        </TooltipContent>
                    </Tooltip>
                    <DropdownMenu>
                        <DropdownMenuTrigger :as-child="true">
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="More dashboard options"
                            >
                                <MoreHorizontal class="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-44">
                            <DropdownMenuItem :as-child="true">
                                <Link :href="aiTrafficRoute(project.id).url">
                                    <Waypoints class="size-4" />
                                    AI traffic
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem :as-child="true">
                                <Link :href="aiVisibilityShow(project.id).url">
                                    <ScanEye class="size-4" />
                                    AI visibility
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem :as-child="true">
                                <Link :href="editWebsiteSettings(project.id)">
                                    <Settings class="size-4" />
                                    Website settings
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </header>

            <Card
                class="gap-0 overflow-hidden p-1"
                aria-labelledby="focus-title"
            >
                <div class="rounded-xl bg-card px-4 py-4 sm:px-5">
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-full bg-secondary"
                            :class="{
                                'text-destructive':
                                    primaryActionableInsight?.severity ===
                                    'critical',
                                'text-warning':
                                    primaryActionableInsight?.severity ===
                                    'warning',
                                'text-primary':
                                    primaryActionableInsight?.severity ===
                                    'info',
                                'text-success':
                                    !primaryActionableInsight &&
                                    focusStatus?.tone === 'positive',
                                'text-muted-foreground':
                                    !primaryActionableInsight &&
                                    focusStatus?.tone === 'neutral',
                            }"
                            aria-hidden="true"
                        >
                            <Lightbulb
                                v-if="primaryActionableInsight"
                                class="size-4"
                            />
                            <component
                                :is="focusStatus.icon"
                                v-else-if="focusStatus"
                                class="size-4"
                            />
                            <CircleGauge v-else class="size-4" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p
                                class="text-[11px] font-medium tracking-[0.1em] text-muted-foreground uppercase"
                            >
                                Focus
                            </p>
                            <template v-if="primaryActionableInsight">
                                <h2
                                    id="focus-title"
                                    class="mt-1 text-base font-medium tracking-[-0.02em]"
                                >
                                    {{ primaryActionableInsight.summary }}
                                </h2>
                                <p
                                    class="mt-1 max-w-3xl text-sm leading-6 text-muted-foreground"
                                >
                                    {{ primaryActionableInsight.explanation }}
                                </p>
                                <p class="mt-2 text-sm">
                                    <span class="text-muted-foreground"
                                        >Next:</span
                                    >
                                    {{
                                        primaryActionableInsight.recommendation
                                    }}
                                </p>
                                <div
                                    class="mt-3 flex flex-wrap items-center gap-2"
                                >
                                    <Button
                                        v-for="action in primaryActionableInsight.actions"
                                        :key="action.key"
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        class="h-8 rounded-full px-3 text-xs"
                                        :disabled="
                                            activeInsightAction ===
                                            primaryActionableInsight.id +
                                                ':' +
                                                action.key
                                        "
                                        :title="action.description"
                                        @click="
                                            runInsightAction(
                                                primaryActionableInsight,
                                                action,
                                            )
                                        "
                                    >
                                        {{
                                            activeInsightAction ===
                                            primaryActionableInsight.id +
                                                ':' +
                                                action.key
                                                ? 'Working…'
                                                : action.label
                                        }}
                                    </Button>
                                    <Button
                                        v-if="actionableInsights.length > 1"
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        class="h-8 rounded-full px-3 text-xs"
                                        @click="activeAnalysisTab = 'insights'"
                                    >
                                        View all changes
                                    </Button>
                                </div>
                            </template>
                            <template v-else-if="focusStatus">
                                <h2
                                    id="focus-title"
                                    class="mt-1 text-base font-medium tracking-[-0.02em]"
                                >
                                    {{ focusStatus.title }}
                                </h2>
                                <p
                                    class="mt-1 max-w-3xl text-sm leading-6 text-muted-foreground"
                                >
                                    {{ focusStatus.description }}
                                </p>
                            </template>
                            <template v-else>
                                <h2 id="focus-title" class="mt-1 font-medium">
                                    Collecting analytics
                                </h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Peekchimp will highlight meaningful changes
                                    after more visits arrive.
                                </p>
                            </template>
                        </div>
                    </div>
                </div>
            </Card>

            <DashboardOverview
                :primary-metrics="primaryMetrics"
                :secondary-metrics="secondaryMetrics"
                :range="analytics.range"
                :chart-metrics="analytics.metrics"
                :timeseries="analytics.timeseries"
            />

            <Card class="gap-0 overflow-hidden p-1">
                <div class="rounded-xl bg-background/70">
                    <div
                        class="flex flex-col gap-4 px-4 pt-4 sm:flex-row sm:items-center sm:justify-between sm:px-5"
                    >
                        <div>
                            <h2 class="font-medium">Explore</h2>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Open one area at a time when you need more
                                detail.
                            </p>
                        </div>
                        <div
                            class="max-w-full overflow-x-auto pb-1"
                            role="tablist"
                            aria-label="Dashboard analysis"
                        >
                            <div
                                class="inline-flex min-w-max items-center gap-1 rounded-full border border-border bg-card p-1"
                            >
                                <button
                                    v-for="tab in analysisTabs"
                                    :id="'analysis-' + tab.id + '-tab'"
                                    :key="tab.id"
                                    type="button"
                                    role="tab"
                                    class="h-8 cursor-pointer rounded-full px-3 text-xs font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                                    :class="
                                        activeAnalysisTab === tab.id
                                            ? 'bg-secondary text-foreground'
                                            : 'text-muted-foreground hover:text-foreground'
                                    "
                                    :aria-selected="
                                        activeAnalysisTab === tab.id
                                    "
                                    :aria-controls="
                                        'analysis-' + tab.id + '-panel'
                                    "
                                    :tabindex="
                                        activeAnalysisTab === tab.id ? 0 : -1
                                    "
                                    @click="activeAnalysisTab = tab.id"
                                    @keydown.left.prevent="
                                        activateAdjacentTab($event, -1)
                                    "
                                    @keydown.right.prevent="
                                        activateAdjacentTab($event, 1)
                                    "
                                >
                                    {{ tab.label }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <Transition name="analysis-panel" mode="out-in">
                        <section
                            v-if="activeAnalysisTab === 'insights'"
                            id="analysis-insights-panel"
                            key="insights"
                            class="analysis-panel px-4 py-5 sm:px-5"
                            role="tabpanel"
                            aria-labelledby="analysis-insights-tab"
                        >
                            <div class="grid gap-3 lg:grid-cols-2">
                                <div
                                    v-for="insight in actionableInsights"
                                    :key="insight.fingerprint"
                                    class="rounded-xl border border-border/80 bg-card/55 p-4"
                                >
                                    <div class="flex items-start gap-3">
                                        <Lightbulb
                                            class="mt-0.5 size-4 shrink-0 text-warning"
                                            aria-hidden="true"
                                        />
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium">
                                                {{ insight.summary }}
                                            </p>
                                            <p
                                                class="mt-1 text-xs leading-5 text-muted-foreground"
                                            >
                                                {{ insight.explanation }}
                                            </p>
                                            <p class="mt-2 text-xs">
                                                {{ insight.recommendation }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div
                                    v-for="insight in insightCards"
                                    :key="insight.type"
                                    class="rounded-xl border border-border/80 bg-card/55 p-4"
                                >
                                    <div class="flex items-start gap-3">
                                        <component
                                            :is="insight.icon"
                                            class="mt-0.5 size-4 shrink-0"
                                            :class="{
                                                'text-warning':
                                                    insight.tone ===
                                                    'attention',
                                                'text-success':
                                                    insight.tone === 'positive',
                                                'text-muted-foreground':
                                                    insight.tone === 'neutral',
                                            }"
                                            aria-hidden="true"
                                        />
                                        <div>
                                            <p class="text-sm font-medium">
                                                {{ insight.title }}
                                            </p>
                                            <p
                                                class="mt-1 text-xs leading-5 text-muted-foreground"
                                            >
                                                {{ insight.description }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p
                                v-if="
                                    actionableInsights.length === 0 &&
                                    insightCards.length === 0
                                "
                                class="py-12 text-center text-sm text-muted-foreground"
                                role="status"
                            >
                                No insights are available for this period.
                            </p>
                        </section>

                        <section
                            v-else-if="activeAnalysisTab === 'pages'"
                            id="analysis-pages-panel"
                            key="pages"
                            class="analysis-panel px-4 py-5 sm:px-5"
                            role="tabpanel"
                            aria-labelledby="analysis-pages-tab"
                        >
                            <div
                                class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div>
                                    <h3 class="text-sm font-medium">
                                        Website pages
                                    </h3>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ activePageDescription }}
                                    </p>
                                </div>
                                <div
                                    class="inline-flex w-fit items-center gap-1 rounded-full bg-secondary p-1"
                                    role="tablist"
                                    aria-label="Page views"
                                >
                                    <button
                                        v-for="tab in pageTabs"
                                        :id="'pages-' + tab.id + '-tab'"
                                        :key="tab.id"
                                        type="button"
                                        role="tab"
                                        class="h-7 rounded-full px-3 text-xs transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                                        :class="
                                            activePageTab === tab.id
                                                ? 'bg-card text-foreground shadow-[0_1px_2px_rgba(0,0,0,0.06)]'
                                                : 'text-muted-foreground hover:text-foreground'
                                        "
                                        :aria-selected="
                                            activePageTab === tab.id
                                        "
                                        @click="activePageTab = tab.id"
                                    >
                                        {{ tab.label }}
                                    </button>
                                </div>
                            </div>
                            <div
                                class="min-h-56 overflow-hidden rounded-xl border border-border/80 bg-card/55 py-2"
                            >
                                <div
                                    v-for="(
                                        page, index
                                    ) in activePageItems.slice(0, 8)"
                                    :key="page.label"
                                    class="group/page relative flex min-h-10 items-center gap-3 overflow-hidden px-3 py-2 text-sm transition-colors hover:bg-accent/70"
                                >
                                    <span
                                        class="absolute inset-y-0 left-0 bg-foreground/[0.035]"
                                        :style="{
                                            width:
                                                percentageOf(
                                                    page.value,
                                                    activePageTotal,
                                                ) + '%',
                                        }"
                                        aria-hidden="true"
                                    />
                                    <span
                                        class="relative w-5 shrink-0 font-mono text-[10px] text-muted-foreground"
                                    >
                                        {{ String(index + 1).padStart(2, '0') }}
                                    </span>
                                    <FileText
                                        class="relative size-4 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <span
                                        class="relative min-w-0 flex-1 truncate font-mono text-xs"
                                    >
                                        {{ page.label }}
                                    </span>
                                    <span
                                        class="relative shrink-0 font-mono text-xs text-muted-foreground tabular-nums"
                                    >
                                        {{ formatNumber(page.value) }}
                                    </span>
                                </div>
                                <p
                                    v-if="activePageItems.length === 0"
                                    class="py-20 text-center text-sm text-muted-foreground"
                                    role="status"
                                >
                                    No page activity in this period.
                                </p>
                            </div>
                        </section>

                        <section
                            v-else-if="activeAnalysisTab === 'acquisition'"
                            id="analysis-acquisition-panel"
                            key="acquisition"
                            class="analysis-panel"
                            role="tabpanel"
                            aria-labelledby="analysis-acquisition-tab"
                        >
                            <DashboardBreakdownCard
                                embedded
                                id="acquisition"
                                title="Acquisition"
                                description="How people reached your website"
                                :icon="Waypoints"
                                :tabs="acquisitionTabs"
                            />
                        </section>

                        <section
                            v-else-if="activeAnalysisTab === 'audience'"
                            id="analysis-audience-panel"
                            key="audience"
                            class="analysis-panel"
                            role="tabpanel"
                            aria-labelledby="analysis-audience-tab"
                        >
                            <DashboardBreakdownCard
                                embedded
                                id="audience"
                                title="Audience"
                                description="Anonymous details about your visitors"
                                :icon="UsersRound"
                                :tabs="audienceTabs"
                            />
                        </section>

                        <section
                            v-else
                            id="analysis-outcomes-panel"
                            key="outcomes"
                            class="analysis-panel px-4 py-5 sm:px-5"
                            role="tabpanel"
                            aria-labelledby="analysis-outcomes-tab"
                        >
                            <div class="grid gap-5 lg:grid-cols-2">
                                <div>
                                    <div
                                        class="flex items-start justify-between gap-4"
                                    >
                                        <div>
                                            <h3 class="text-sm font-medium">
                                                Important actions
                                            </h3>
                                            <p
                                                class="mt-1 text-xs text-muted-foreground"
                                            >
                                                Clicks against matching page
                                                views.
                                            </p>
                                        </div>
                                        <Link
                                            :href="actionsIndex(project.id).url"
                                            class="text-xs text-muted-foreground underline decoration-border underline-offset-4 hover:text-foreground"
                                        >
                                            Manage
                                        </Link>
                                    </div>
                                    <div
                                        class="mt-4 overflow-hidden rounded-xl border border-border/80 bg-card/55 py-2"
                                    >
                                        <div
                                            v-for="action in analytics.importantActions"
                                            :key="action.id"
                                            class="flex items-center justify-between gap-4 px-3 py-2 text-sm"
                                        >
                                            <span class="min-w-0 truncate">
                                                {{ action.name }}
                                            </span>
                                            <span
                                                class="shrink-0 font-mono text-[11px] text-muted-foreground tabular-nums"
                                            >
                                                {{
                                                    formatNumber(action.clicks)
                                                }}
                                                clicks ·
                                                {{
                                                    formatPercentage(action.ctr)
                                                }}
                                            </span>
                                        </div>
                                        <p
                                            v-if="
                                                analytics.importantActions
                                                    .length === 0
                                            "
                                            class="px-4 py-12 text-center text-sm text-muted-foreground"
                                        >
                                            No important actions configured.
                                        </p>
                                    </div>
                                </div>
                                <div>
                                    <div
                                        class="flex items-start justify-between gap-4"
                                    >
                                        <div>
                                            <h3 class="text-sm font-medium">
                                                Goals
                                            </h3>
                                            <p
                                                class="mt-1 text-xs text-muted-foreground"
                                            >
                                                Session outcomes worth
                                                measuring.
                                            </p>
                                        </div>
                                        <div
                                            class="flex gap-3 text-xs text-muted-foreground"
                                        >
                                            <Link
                                                :href="
                                                    goalsIndex(project.id).url
                                                "
                                                class="underline decoration-border underline-offset-4 hover:text-foreground"
                                            >
                                                Manage
                                            </Link>
                                            <Link
                                                :href="
                                                    funnelsIndex(project.id).url
                                                "
                                                class="underline decoration-border underline-offset-4 hover:text-foreground"
                                            >
                                                Funnels
                                            </Link>
                                        </div>
                                    </div>
                                    <div
                                        class="mt-4 overflow-hidden rounded-xl border border-border/80 bg-card/55 py-2"
                                    >
                                        <div
                                            v-for="goal in analytics.goals"
                                            :key="goal.id"
                                            class="flex items-center justify-between gap-4 px-3 py-2 text-sm"
                                        >
                                            <span class="min-w-0 truncate">
                                                {{ goal.name }}
                                            </span>
                                            <span
                                                class="shrink-0 font-mono text-[11px] text-muted-foreground tabular-nums"
                                            >
                                                {{
                                                    formatNumber(
                                                        goal.conversions,
                                                    )
                                                }}
                                                ·
                                                {{
                                                    formatPercentage(
                                                        goal.conversionRate,
                                                    )
                                                }}
                                            </span>
                                        </div>
                                        <p
                                            v-if="analytics.goals.length === 0"
                                            class="px-4 py-12 text-center text-sm text-muted-foreground"
                                        >
                                            No goals configured.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </Transition>
                </div>
            </Card>
        </div>
    </TooltipProvider>
</template>

<style scoped>
.tracking-status {
    position: relative;
    display: inline-flex;
    width: 0.5rem;
    height: 0.5rem;
}

.tracking-status::before,
.tracking-status span {
    position: absolute;
    inset: 0;
    border-radius: 9999px;
    background: var(--success);
}

.tracking-status::before {
    content: '';
    animation: tracking-pulse 1.6s ease-in-out infinite;
}

.analysis-panel-enter-active {
    transition:
        opacity 160ms ease-out,
        transform 160ms cubic-bezier(0.2, 0, 0, 1),
        filter 160ms ease-out;
}

.analysis-panel-leave-active {
    transition:
        opacity 100ms ease-in,
        transform 100ms ease-in;
}

.analysis-panel-enter-from {
    opacity: 0;
    filter: blur(3px);
    transform: translateY(2px);
}

.analysis-panel-leave-to {
    opacity: 0;
    transform: translateY(-2px);
}

@keyframes tracking-pulse {
    0%,
    100% {
        opacity: 0;
        transform: scale(1);
    }

    45% {
        opacity: 0.2;
    }

    80% {
        opacity: 0;
        transform: scale(2.2);
    }
}

@media (prefers-reduced-motion: reduce) {
    .tracking-status::before {
        animation: none;
    }
}
</style>
