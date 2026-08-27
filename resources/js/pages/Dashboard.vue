<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Bot,
    CalendarDays,
    ChartNoAxesCombined,
    CircleGauge,
    Clock3,
    FileInput,
    FileText,
    GitBranch,
    House,
    Info,
    Lightbulb,
    RefreshCw,
    ScanEye,
    Share2,
    Smartphone,
    Sparkles,
    Target,
    Timer,
    TrendingUp,
    UsersRound,
    Waypoints,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import GenerateDashboardAiInsightsController from '@/actions/App/Http/Controllers/GenerateDashboardAiInsightsController';
import DashboardBreakdownCard from '@/components/dashboard/DashboardBreakdownCard.vue';
import DashboardTrafficChart from '@/components/dashboard/DashboardTrafficChart.vue';
import MetricTrendCard from '@/components/dashboard/MetricTrendCard.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { TooltipProvider } from '@/components/ui/tooltip';
import { dashboard } from '@/routes';
import { edit as editAi } from '@/routes/settings/ai';
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
type ActionableInsight = {
    id: number;
    fingerprint: string;
    category: string;
    current_value: number;
    previous_value: number;
    percentage_change: number | null;
    summary: string;
    explanation: string;
    recommendation: string;
    ai_enhanced?: boolean;
    ai_generated_at?: string | null;
};
type Analytics = {
    range: {
        key: string;
        label: string;
        from: string;
        to: string;
        interval: 'hour' | 'day';
    };
    comparison: { available: boolean };
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
        visitors: MetricTrend;
        pageviews: MetricTrend;
        bounceRate: MetricTrend;
        averageDuration: MetricTrend;
        conversions: MetricTrend;
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
    actionableInsights: ActionableInsight[];
    whatChanged: ActionableInsight[];
};
type AiInsightRun = {
    status: 'running' | 'completed' | 'failed' | 'skipped';
    error: string | null;
    updatedAt: string;
};

const props = defineProps<{
    project: {
        id: number;
        name: string;
        timezone: string;
        domains: string[];
    };
    analytics: Analytics;
    aiInsightRun?: AiInsightRun | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const selectedRange = ref(props.analytics.range.key);
const page = usePage();
const isGeneratingAi = ref(false);
const isManualRefreshing = ref(false);
const isChangingRange = ref(false);
const activePageTab = ref<'top' | 'entry' | 'exit'>('top');
const showAllPages = ref(false);
let refreshTimer: number | undefined;
let aiInsightRefreshTimer: number | undefined;
let aiInsightRefreshAttempts = 0;
let aiInsightGenerationBaseline: string | null = null;
let aiInsightRunBaseline: string | null = null;

const comparisonLabel = computed(() => {
    if (props.analytics.range.key === '7d') {
        return 'Previous 7 days';
    }

    if (props.analytics.range.key === '30d') {
        return 'Previous 30 days';
    }

    return 'Previous matching period';
});

const metricCards = computed(() => [
    {
        id: 'visitors',
        label: 'Visitors',
        value: formatNumber(props.analytics.metrics.visitors),
        detail: 'Estimated unique visitors during the selected period.',
        icon: UsersRound,
        accent: 'emerald' as const,
        actionHref: '',
        actionLabel: '',
        currentValue: props.analytics.metrics.visitors,
        previousValue: props.analytics.metricTrends.visitors.previous,
        previousValueLabel: formatNumber(
            props.analytics.metricTrends.visitors.previous,
        ),
        change: props.analytics.metricTrends.visitors.change,
        series: props.analytics.metricTrends.visitors.series,
    },
    {
        id: 'views',
        label: 'Views',
        value: formatNumber(props.analytics.metrics.pageviews),
        detail: 'Total page loads, including repeat views.',
        icon: ScanEye,
        accent: 'cyan' as const,
        actionHref: '',
        actionLabel: '',
        currentValue: props.analytics.metrics.pageviews,
        previousValue: props.analytics.metricTrends.pageviews.previous,
        previousValueLabel: formatNumber(
            props.analytics.metricTrends.pageviews.previous,
        ),
        change: props.analytics.metricTrends.pageviews.change,
        series: props.analytics.metricTrends.pageviews.series,
    },
    {
        id: 'bounce-rate',
        label: 'Bounce rate',
        value: formatPercentage(props.analytics.metrics.bounceRate),
        detail: 'Share of visits that ended after one page. Lower is usually better.',
        icon: GitBranch,
        accent: 'orange' as const,
        actionHref: '',
        actionLabel: '',
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
        id: 'average-duration',
        label: 'Avg. visit time',
        value: formatDuration(props.analytics.metrics.averageDuration),
        detail: 'Average time spent during a visit.',
        icon: Timer,
        accent: 'violet' as const,
        actionHref: '',
        actionLabel: '',
        currentValue: props.analytics.metrics.averageDuration,
        previousValue: props.analytics.metricTrends.averageDuration.previous,
        previousValueLabel: formatDuration(
            props.analytics.metricTrends.averageDuration.previous,
        ),
        change: props.analytics.metricTrends.averageDuration.change,
        series: props.analytics.metricTrends.averageDuration.series,
    },
    {
        id: 'conversions',
        label: 'Conversions',
        value: formatNumber(props.analytics.metrics.conversions),
        detail: 'Completed goals during the selected period.',
        icon: Target,
        accent: 'rose' as const,
        actionHref: goalsIndex(props.project.id).url,
        actionLabel: 'Set up goals',
        currentValue: props.analytics.metrics.conversions,
        previousValue: props.analytics.metricTrends.conversions.previous,
        previousValueLabel: formatNumber(
            props.analytics.metricTrends.conversions.previous,
        ),
        change: props.analytics.metricTrends.conversions.change,
        series: props.analytics.metricTrends.conversions.series,
    },
]);

const actionableInsights = computed(
    () =>
        props.analytics.actionableInsights ?? props.analytics.whatChanged ?? [],
);

const sources = computed(
    () => props.analytics.sources ?? props.analytics.referrers,
);
const topSource = computed(() => sources.value[0] ?? null);
const topLandingPage = computed(
    () => props.analytics.entryPages[0] ?? props.analytics.topPages[0] ?? null,
);
const topDevice = computed(() => props.analytics.devices[0] ?? null);
const peakPoint = computed(() => {
    return props.analytics.timeseries.reduce<
        Analytics['timeseries'][number] | null
    >((peak, point) => {
        if (peak === null || point.visitors > peak.visitors) {
            return point;
        }

        return peak;
    }, null);
});

const topInsightFacts = computed(() => [
    {
        id: 'source',
        label: 'Top traffic source',
        value: topSource.value?.label ?? 'No source yet',
        detail: topSource.value
            ? formatShare(topSource.value.value, totalOf(sources.value)) +
              ' of visits'
            : 'Waiting for visits',
        icon: Target,
        iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    },
    {
        id: 'landing-page',
        label: 'Top landing page',
        value: topLandingPage.value?.label ?? 'No landing page yet',
        detail: topLandingPage.value
            ? formatShare(
                  topLandingPage.value.value,
                  Math.max(1, props.analytics.metrics.sessions),
              ) + ' of visits'
            : 'Waiting for visits',
        icon: FileInput,
        iconClass: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    },
    {
        id: 'device',
        label: 'Top device',
        value: topDevice.value?.label ?? 'No device yet',
        detail: topDevice.value
            ? formatShare(
                  topDevice.value.value,
                  totalOf(props.analytics.devices),
              ) + ' of visits'
            : 'Waiting for visits',
        icon: Smartphone,
        iconClass: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400',
    },
    {
        id: 'peak',
        label: 'Peak period',
        value: peakPoint.value?.label ?? 'Not enough data',
        detail: peakPoint.value
            ? formatNumber(peakPoint.value.visitors) + ' visitors'
            : 'Waiting for visits',
        icon: Clock3,
        iconClass: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
    },
]);

type DisplayInsight = {
    id: string;
    title: string;
    description: string;
    recommendation: string;
    aiEnhanced: boolean;
    icon: Component;
    iconClass: string;
};

const snapshotInsights = computed<DisplayInsight[]>(() => [
    {
        id: 'baseline',
        title:
            formatNumber(props.analytics.metrics.visitors) +
            ' visitors recorded',
        description: props.analytics.comparison.available
            ? 'Your current audience for this selected period.'
            : 'Peekchimp is using this traffic to build your first reliable baseline.',
        recommendation: props.analytics.comparison.available
            ? 'Use this as the reference for the next matching period.'
            : 'Keep tracking enabled until both matching periods contain traffic.',
        aiEnhanced: false,
        icon: TrendingUp,
        iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    },
    {
        id: 'source',
        title: topSource.value
            ? topSource.value.label +
              ' brought ' +
              formatNumber(topSource.value.value) +
              ' visits'
            : 'Traffic sources are still being collected',
        description: topSource.value
            ? 'This is the largest traffic source in the selected period.'
            : 'Source details will appear as new visits arrive.',
        recommendation: topSource.value
            ? 'Use campaign tags on shared links so direct traffic is easier to explain.'
            : 'Keep tracking enabled while Peekchimp collects source data.',
        aiEnhanced: false,
        icon: House,
        iconClass: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400',
    },
    {
        id: 'page',
        title: topLandingPage.value
            ? topLandingPage.value.label +
              ' led with ' +
              formatNumber(topLandingPage.value.value) +
              ' visits'
            : 'Landing pages are still being collected',
        description: topLandingPage.value
            ? 'This is where the most visits began in the selected period.'
            : 'Landing-page details will appear as visits arrive.',
        recommendation: topLandingPage.value
            ? 'Make the next action on this page clear and easy to find.'
            : 'Keep tracking enabled while Peekchimp collects page data.',
        aiEnhanced: false,
        icon: FileText,
        iconClass: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    },
]);

const displayedInsights = computed<DisplayInsight[]>(() => {
    if (
        !props.analytics.comparison.available ||
        actionableInsights.value.length === 0
    ) {
        return snapshotInsights.value;
    }

    return actionableInsights.value.slice(0, 3).map((insight) => ({
        id: insight.fingerprint,
        title: insight.summary,
        description: insight.explanation,
        recommendation: insight.recommendation,
        aiEnhanced: insight.ai_enhanced === true,
        icon: ChartNoAxesCombined,
        iconClass:
            insight.percentage_change !== null && insight.percentage_change < 0
                ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400'
                : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    }));
});

const hasAiEnhancedInsights = computed(() =>
    displayedInsights.value.some((insight) => insight.aiEnhanced),
);
const latestAiInsightGeneration = computed(() =>
    actionableInsights.value.reduce<string | null>((latest, insight) => {
        if (!insight.ai_generated_at) {
            return latest;
        }

        return latest === null || insight.ai_generated_at > latest
            ? insight.ai_generated_at
            : latest;
    }, null),
);

const acquisitionTabs = computed(() => [
    {
        id: 'sources',
        label: 'Sources',
        description: 'How visitors found your website',
        emptyMessage: 'No traffic sources in this period.',
        items: sources.value,
        kind: 'source' as const,
        total: totalOf(sources.value),
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
        description: 'Visits attributed to AI platforms',
        emptyMessage: 'No AI referral visits in this period.',
        items: props.analytics.aiReferrals.sources,
        kind: 'ai' as const,
        total: props.analytics.aiReferrals.totalVisits,
    },
]);

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

const pageTabs = [
    { id: 'top' as const, label: 'Top' },
    { id: 'entry' as const, label: 'Entry' },
    { id: 'exit' as const, label: 'Exit' },
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

const activePageTotal = computed(() => {
    if (activePageTab.value === 'top') {
        return props.analytics.metrics.pageviews;
    }

    return totalOf(activePageItems.value);
});

const visiblePageItems = computed(() =>
    activePageItems.value.slice(0, showAllPages.value ? 12 : 5),
);

const periodSummary = computed(() => {
    const from = formatDate(props.analytics.range.from);
    const to = formatDate(props.analytics.range.to);

    if (props.analytics.range.from === props.analytics.range.to) {
        return 'Here is how your website performed on ' + from + '.';
    }

    return 'Here is how your website performed from ' + from + ' – ' + to + '.';
});

function formatNumber(value: number): string {
    return new Intl.NumberFormat().format(value);
}

function formatPercentage(value: number): string {
    return (
        new Intl.NumberFormat(undefined, {
            maximumFractionDigits: 1,
        }).format(value) + '%'
    );
}

function formatDuration(seconds: number): string {
    if (seconds < 60) {
        return seconds + 's';
    }

    return Math.floor(seconds / 60) + 'm ' + (seconds % 60) + 's';
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(value + 'T00:00:00Z'));
}

function formatShare(value: number, total: number): string {
    if (total <= 0) {
        return '0%';
    }

    return formatPercentage((value / total) * 100);
}

function totalOf(items: Breakdown[]): number {
    return items.reduce((total, item) => total + item.value, 0);
}

function pageShare(value: number): number {
    if (activePageTotal.value <= 0) {
        return 0;
    }

    return Math.round((value / activePageTotal.value) * 100);
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
            only: ['analytics'],
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

function generateAiInsights(): void {
    stopAiInsightPolling();
    aiInsightGenerationBaseline = latestAiInsightGeneration.value;
    aiInsightRunBaseline = props.aiInsightRun?.updatedAt ?? null;
    isGeneratingAi.value = true;
    let receivedQueueConfirmation = false;
    router.post(
        GenerateDashboardAiInsightsController().url,
        { range: selectedRange.value },
        {
            preserveScroll: true,
            onFlash: (flash) => {
                const generation = flash.aiInsightGeneration as
                    | { queued?: boolean; message?: string }
                    | undefined;

                if (generation === undefined) {
                    return;
                }

                receivedQueueConfirmation = true;

                if (generation.queued === true) {
                    pollForAiInsights();

                    return;
                }

                stopAiInsightPolling();
            },
            onError: () => {
                stopAiInsightPolling();
                toast.error('Peekchimp could not queue AI insight generation.');
            },
            onFinish: () => {
                if (! receivedQueueConfirmation && isGeneratingAi.value) {
                    stopAiInsightPolling();
                    toast.error(
                        'Peekchimp did not confirm that the AI job was queued.',
                    );
                }
            },
        },
    );
}

function pollForAiInsights(): void {
    aiInsightRefreshTimer = window.setTimeout(() => {
        router.reload({
            only: ['analytics', 'aiInsightRun'],
            onFinish: () => {
                aiInsightRefreshAttempts += 1;
                const latestRun = props.aiInsightRun ?? null;
                const hasNewRunState =
                    latestRun !== null &&
                    latestRun.updatedAt !== aiInsightRunBaseline;

                if (
                    hasNewRunState &&
                    latestRun.status === 'completed' &&
                    latestAiInsightGeneration.value !== null &&
                    latestAiInsightGeneration.value !==
                        aiInsightGenerationBaseline
                ) {
                    stopAiInsightPolling();
                    toast.success('AI recommendations updated.');

                    return;
                }

                if (
                    hasNewRunState &&
                    (latestRun.status === 'failed' ||
                        latestRun.status === 'skipped')
                ) {
                    const message =
                        latestRun.error ||
                        'AI finished without producing a useful recommendation.';
                    stopAiInsightPolling();
                    toast.error(message);

                    return;
                }

                if (aiInsightRefreshAttempts >= 18) {
                    stopAiInsightPolling();
                    toast.error(
                        'No AI job completed. Confirm the queue worker is running, then try again.',
                    );

                    return;
                }

                pollForAiInsights();
            },
        });
    }, 2500);
}

function stopAiInsightPolling(): void {
    if (aiInsightRefreshTimer !== undefined) {
        window.clearTimeout(aiInsightRefreshTimer);
        aiInsightRefreshTimer = undefined;
    }

    aiInsightRefreshAttempts = 0;
    isGeneratingAi.value = false;
}

watch(
    () => props.analytics.range.key,
    (rangeKey) => {
        selectedRange.value = rangeKey;
    },
);

watch(activePageTab, () => {
    showAllPages.value = false;
});

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

    stopAiInsightPolling();
});
</script>

<template>
    <TooltipProvider :delay-duration="100">
        <Head title="Dashboard" />

        <main
            class="mx-auto flex w-full max-w-[1500px] flex-col gap-8 px-4 pt-7 pb-16 sm:px-6 lg:px-8"
        >
            <header
                class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between"
            >
                <div class="min-w-0">
                    <p class="text-sm font-medium text-muted-foreground">
                        Website Overview
                    </p>
                    <h1
                        class="mt-1 truncate text-4xl font-semibold tracking-[-0.05em] sm:text-5xl"
                    >
                        {{ project.name }}
                    </h1>
                    <p
                        class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
                    >
                        <span class="truncate">{{ project.domains[0] }}</span>
                        <span aria-hidden="true">•</span>
                        <span>{{ project.timezone }}</span>
                        <span aria-hidden="true">•</span>
                        <span class="inline-flex items-center gap-1.5">
                            <span
                                class="size-1.5 rounded-full bg-emerald-500"
                                aria-hidden="true"
                            />
                            Tracking is active
                        </span>
                    </p>
                    <p class="mt-3 text-sm text-muted-foreground">
                        {{ periodSummary }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex h-9 items-center gap-2 rounded-full bg-emerald-500/10 px-3 text-xs text-muted-foreground"
                    >
                        <span
                            class="size-1.5 rounded-full bg-emerald-500"
                            aria-hidden="true"
                        />
                        <strong
                            class="font-medium text-foreground tabular-nums"
                        >
                            {{ formatNumber(analytics.metrics.activeVisitors) }}
                        </strong>
                        active now
                    </span>
                    <label class="relative">
                        <CalendarDays
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <select
                            v-model="selectedRange"
                            class="select-with-chevron h-9 cursor-pointer rounded-xl border border-input bg-card pr-10 pl-9 text-xs font-medium transition-colors outline-none hover:bg-accent/50 focus:border-ring focus:ring-2 focus:ring-ring/30"
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
                    </label>
                    <Button
                        variant="outline"
                        size="icon"
                        class="rounded-xl"
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
                    <Button as-child variant="outline" class="rounded-xl">
                        <Link :href="editWebsiteSettings(project.id)">
                            <Share2 class="size-4" />
                            Share
                        </Link>
                    </Button>
                </div>
            </header>

            <section
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5"
                aria-label="Website performance metrics"
            >
                <MetricTrendCard
                    v-for="metric in metricCards"
                    :key="metric.id"
                    :animation-key="analytics.range.key + '-' + metric.id"
                    :accent="metric.accent"
                    :label="metric.label"
                    :value="metric.value"
                    :detail="metric.detail"
                    :icon="metric.icon"
                    :current-value="metric.currentValue"
                    :previous-value="metric.previousValue"
                    :previous-value-label="metric.previousValueLabel"
                    :change="metric.change"
                    :series="metric.series"
                    :comparison-label="comparisonLabel"
                    :comparison-available="analytics.comparison.available"
                    :inverse="metric.inverse"
                    :action-href="metric.actionHref"
                    :action-label="metric.actionLabel"
                />
            </section>

            <section
                class="grid gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(17rem,1fr)]"
                aria-label="Analytics insights"
            >
                <Card
                    class="overflow-hidden rounded-2xl !border-emerald-500/25 bg-gradient-to-br from-emerald-500/[0.07] via-card to-card p-5 shadow-none sm:p-6"
                >
                    <div
                        class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="flex items-start gap-3">
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                            >
                                <Sparkles class="size-5" />
                            </span>
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2
                                        class="text-xl font-semibold tracking-[-0.025em]"
                                    >
                                        Analytics insights
                                    </h2>
                                    <span
                                        :class="[
                                            'rounded-full border px-2 py-0.5 text-[10px] font-semibold',
                                            hasAiEnhancedInsights
                                                ? 'border-violet-500/20 bg-violet-500/10 text-violet-700 dark:text-violet-300'
                                                : 'border-emerald-500/20 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
                                        ]"
                                    >
                                        {{
                                            hasAiEnhancedInsights
                                                ? 'AI-enhanced'
                                                : analytics.comparison.available
                                                  ? 'Data-backed'
                                                  : 'Building baseline'
                                        }}
                                    </span>
                                </div>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{
                                        hasAiEnhancedInsights
                                            ? 'AI-enhanced recommendations based on aggregate analytics changes.'
                                            : analytics.comparison.available
                                              ? 'Data-backed changes and recommendations from your analytics.'
                                            : 'Useful current-period facts while Peekchimp builds a reliable comparison.'
                                    }}
                                </p>
                            </div>
                        </div>
                        <div
                            v-if="page.props.auth.user?.is_admin"
                            class="flex shrink-0 items-center gap-2"
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                class="rounded-xl bg-card"
                                :disabled="isGeneratingAi"
                                @click="generateAiInsights"
                            >
                                <Bot class="size-3.5" />
                                {{
                                    isGeneratingAi
                                        ? 'Generating…'
                                        : 'Generate AI insight'
                                }}
                            </Button>
                            <Button
                                as-child
                                variant="outline"
                                size="sm"
                                class="rounded-xl bg-card"
                            >
                                <Link :href="editAi().url">AI settings</Link>
                            </Button>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-3 md:grid-cols-3">
                        <article
                            v-for="insight in displayedInsights"
                            :key="insight.id"
                            class="flex min-h-60 flex-col rounded-2xl border border-border/80 bg-card p-4 shadow-xs"
                        >
                            <div class="flex items-start gap-3">
                                <span
                                    :class="[
                                        'flex size-9 shrink-0 items-center justify-center rounded-full',
                                        insight.iconClass,
                                    ]"
                                    aria-hidden="true"
                                >
                                    <component
                                        :is="insight.icon"
                                        class="size-4.5"
                                    />
                                </span>
                                <div class="min-w-0">
                                    <h3 class="text-sm leading-5 font-semibold">
                                        {{ insight.title }}
                                    </h3>
                                    <p
                                        class="mt-2 text-xs leading-5 text-muted-foreground"
                                    >
                                        {{ insight.description }}
                                    </p>
                                </div>
                            </div>
                            <div class="mt-auto rounded-xl bg-muted/70 p-3.5">
                                <p
                                    :class="[
                                        'flex items-center gap-1.5 text-[11px] font-semibold',
                                        insight.aiEnhanced
                                            ? 'text-violet-700 dark:text-violet-300'
                                            : 'text-emerald-700 dark:text-emerald-300',
                                    ]"
                                >
                                    <Bot
                                        v-if="insight.aiEnhanced"
                                        class="size-3.5"
                                    />
                                    <Lightbulb v-else class="size-3.5" />
                                    {{
                                        insight.aiEnhanced
                                            ? 'AI-enhanced recommendation'
                                            : 'Recommendation'
                                    }}
                                </p>
                                <p class="mt-1.5 text-xs leading-5">
                                    {{ insight.recommendation }}
                                </p>
                            </div>
                        </article>
                    </div>
                </Card>

                <Card class="rounded-2xl p-5 shadow-xs sm:p-6">
                    <div class="flex items-center gap-2">
                        <h2 class="font-semibold">Top insights</h2>
                        <Info
                            class="size-3.5 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </div>
                    <div class="mt-5 flex flex-col gap-5">
                        <div
                            v-for="fact in topInsightFacts"
                            :key="fact.id"
                            class="flex items-start gap-3"
                        >
                            <span
                                :class="[
                                    'flex size-9 shrink-0 items-center justify-center rounded-full',
                                    fact.iconClass,
                                ]"
                                aria-hidden="true"
                            >
                                <component :is="fact.icon" class="size-4.5" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs text-muted-foreground">
                                    {{ fact.label }}
                                </p>
                                <p
                                    class="mt-0.5 truncate text-sm font-semibold"
                                >
                                    {{ fact.value }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ fact.detail }}
                                </p>
                            </div>
                        </div>
                    </div>
                </Card>
            </section>

            <section
                class="grid gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(17rem,1fr)]"
                aria-label="Traffic and pages"
            >
                <DashboardTrafficChart
                    :range="analytics.range"
                    :metrics="analytics.metrics"
                    :timeseries="analytics.timeseries"
                />

                <Card
                    class="overflow-hidden rounded-2xl border-border/80 p-0 shadow-xs"
                >
                    <div class="flex items-center justify-between p-5 pb-3">
                        <h2 class="font-semibold">Top pages</h2>
                        <button
                            v-if="activePageItems.length > 5"
                            type="button"
                            class="text-xs font-medium text-primary hover:underline"
                            @click="showAllPages = !showAllPages"
                        >
                            {{ showAllPages ? 'Show less' : 'View all' }}
                        </button>
                    </div>
                    <div
                        class="mx-5 grid grid-cols-3 rounded-xl bg-muted/60 p-1"
                        role="tablist"
                        aria-label="Page type"
                    >
                        <button
                            v-for="tab in pageTabs"
                            :key="tab.id"
                            type="button"
                            role="tab"
                            class="rounded-lg px-3 py-1.5 text-xs font-medium transition-colors"
                            :class="
                                activePageTab === tab.id
                                    ? 'bg-card text-primary shadow-xs'
                                    : 'text-muted-foreground hover:text-foreground'
                            "
                            :aria-selected="activePageTab === tab.id"
                            @click="activePageTab = tab.id"
                        >
                            {{ tab.label }}
                        </button>
                    </div>
                    <div class="mt-3 border-t border-border/70 px-5 py-3">
                        <div
                            v-for="(page, index) in visiblePageItems"
                            :key="page.label"
                            class="grid grid-cols-[1.5rem_minmax(0,1fr)_2.5rem] items-center gap-2 py-2.5"
                        >
                            <span
                                class="font-mono text-[10px] text-muted-foreground"
                            >
                                {{ String(index + 1).padStart(2, '0') }}
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-mono text-xs">
                                    {{ page.label }}
                                </p>
                                <div
                                    class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted"
                                >
                                    <div
                                        class="h-full rounded-full bg-emerald-500"
                                        :style="{
                                            width:
                                                Math.max(
                                                    3,
                                                    pageShare(page.value),
                                                ) + '%',
                                        }"
                                    />
                                </div>
                            </div>
                            <span
                                class="text-right font-mono text-[10px] text-muted-foreground tabular-nums"
                            >
                                {{ pageShare(page.value) }}%
                            </span>
                        </div>
                        <div
                            v-if="visiblePageItems.length === 0"
                            class="flex min-h-48 flex-col items-center justify-center gap-2 text-center text-sm text-muted-foreground"
                            role="status"
                        >
                            <FileText class="size-5" />
                            No page data in this period.
                        </div>
                    </div>
                </Card>
            </section>

            <section
                class="grid gap-4 lg:grid-cols-2"
                aria-label="Acquisition and audience"
            >
                <DashboardBreakdownCard
                    id="acquisition"
                    title="Acquisition"
                    description="How visitors find your website"
                    :icon="Waypoints"
                    :tabs="acquisitionTabs"
                />
                <DashboardBreakdownCard
                    id="audience"
                    title="Audience"
                    description="Who visits and what they use"
                    :icon="UsersRound"
                    :tabs="audienceTabs"
                />
            </section>

            <div
                class="mx-auto inline-flex items-center gap-2 rounded-full border border-border/80 bg-card px-4 py-2 text-xs text-muted-foreground"
            >
                <CircleGauge class="size-4" />
                All times are in {{ project.timezone }}
            </div>
        </main>
    </TooltipProvider>
</template>
