<script setup lang="ts">
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import {
    CalendarDays,
    CircleGauge,
    FileText,
    GitBranch,
    Lightbulb,
    RefreshCw,
    ScanEye,
    Search,
    Share2,
    Target,
    Timer,
    UsersRound,
    Waypoints,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DashboardBreakdownCard from '@/components/dashboard/DashboardBreakdownCard.vue';
import DashboardTrafficChart from '@/components/dashboard/DashboardTrafficChart.vue';
import MetricTrendCard from '@/components/dashboard/MetricTrendCard.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { TooltipProvider } from '@/components/ui/tooltip';
import { dashboard } from '@/routes';
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
};
type SearchMetric = {
    current: number | null;
    previous: number | null;
    change: number | null;
    improved: boolean | null;
};
type SearchPerformance = {
    status:
        | 'not_connected'
        | 'connected'
        | 'syncing'
        | 'error'
        | 'reconnect_required'
        | 'no_data';
    connection?: {
        propertySiteUrl: string;
        dataThrough: string | null;
        lastSyncedAt: string | null;
        lastError: string | null;
    };
    range?: { from: string; to: string };
    metrics?: {
        clicks: SearchMetric;
        impressions: SearchMetric;
        ctr: SearchMetric;
        position: SearchMetric;
    } | null;
    timeseries?: Array<{
        date: string;
        clicks: number;
        impressions: number;
    }>;
    pages?: SearchBreakdown[];
    queries?: SearchBreakdown[];
    organicFunnel?: {
        impressions: number;
        clicks: number;
        visits: number;
        engagedVisits: number;
        conversions: number;
        searchCtr: number;
        trackedVisitRate: number | null;
        engagementRate: number | null;
        conversionRate: number | null;
    };
    landingPages?: OrganicLandingPage[];
    insights?: Array<{
        title: string;
        detail: string;
        recommendation: string;
    }>;
};
type SearchBreakdown = {
    label: string;
    clicks: number;
    impressions: number;
    ctr: number;
    position: number | null;
};
type OrganicLandingPage = {
    path: string;
    impressions: number;
    clicks: number;
    ctr: number;
    position: number | null;
    visits: number;
    visitors: number;
    engagedVisits: number;
    bounceRate: number;
    averageDuration: number;
    conversions: number;
    conversionRate: number;
    trackedVisitRate: number | null;
    topQueries: Array<{
        query: string;
        clicks: number;
        impressions: number;
        ctr: number;
        position: number | null;
    }>;
};

const props = defineProps<{
    project: {
        id: number;
        name: string;
        timezone: string;
        domains: string[];
    };
    analytics: Analytics;
    searchPerformance?: SearchPerformance;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const selectedRange = ref(props.analytics.range.key);
const isManualRefreshing = ref(false);
const isChangingRange = ref(false);
const activePageTab = ref<'top' | 'entry' | 'exit'>('top');
const showAllPages = ref(false);
const activeSearchTab = ref<'landingPages' | 'queries'>('landingPages');
const selectedLandingPagePath = ref<string | null>(null);
const comparisonLabel = computed(() => {
    if (props.analytics.range.key === '7d') {
        return 'Previous 7 days';
    }

    if (props.analytics.range.key === '30d') {
        return 'Previous 30 days';
    }

    return 'Previous matching period';
});

const selectedLandingPage = computed(
    () =>
        props.searchPerformance?.landingPages?.find(
            (landingPage) => landingPage.path === selectedLandingPagePath.value,
        ) ??
        props.searchPerformance?.landingPages?.[0] ??
        null,
);

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

const sources = computed(
    () => props.analytics.sources ?? props.analytics.referrers,
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
            only: ['analytics', 'searchPerformance'],
            onFinish: () => {
                isChangingRange.value = false;
            },
        },
    );
}

function refresh(): void {
    isManualRefreshing.value = true;
    router.reload({
        only: ['analytics', 'searchPerformance'],
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

watch(activePageTab, () => {
    showAllPages.value = false;
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

            <section aria-label="Traffic over time">
                <DashboardTrafficChart
                    :range="analytics.range"
                    :metrics="analytics.metrics"
                    :timeseries="analytics.timeseries"
                />
            </section>

            <section aria-label="Top pages">
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

            <Deferred data="searchPerformance">
                <section aria-label="Google Search Console performance">
                    <Card
                        v-if="searchPerformance?.status === 'not_connected'"
                        class="rounded-2xl border-border/80 p-5 shadow-xs sm:p-6"
                    >
                        <div
                            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="flex items-start gap-3">
                                <span
                                    class="flex size-9 shrink-0 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                                >
                                    <Search class="size-4.5" />
                                </span>
                                <div>
                                    <h2 class="font-semibold">
                                        Add organic search performance
                                    </h2>
                                    <p
                                        class="mt-1 text-sm text-muted-foreground"
                                    >
                                        Connect Google Search Console to see how
                                        people find the website and what they do
                                        after arriving.
                                    </p>
                                </div>
                            </div>
                            <Button as-child class="shrink-0 rounded-xl">
                                <Link
                                    :href="editWebsiteSettings(project.id).url"
                                >
                                    Connect Search Console
                                </Link>
                            </Button>
                        </div>
                    </Card>

                    <Card
                        v-else-if="searchPerformance?.metrics"
                        class="overflow-hidden rounded-2xl border-border/80 p-0 shadow-xs"
                    >
                        <div
                            class="flex flex-col gap-3 border-b border-border/70 p-5 sm:flex-row sm:items-start sm:justify-between sm:p-6"
                        >
                            <div class="flex items-start gap-3">
                                <span
                                    class="flex size-9 shrink-0 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                                >
                                    <Search class="size-4.5" />
                                </span>
                                <div>
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <h2 class="font-semibold">
                                            Your Google search story
                                        </h2>
                                        <span
                                            v-if="
                                                searchPerformance.status ===
                                                'syncing'
                                            "
                                            class="rounded-full bg-cyan-500/10 px-2 py-0.5 text-[10px] font-medium text-cyan-700 dark:text-cyan-300"
                                        >
                                            Syncing
                                        </span>
                                        <span
                                            v-if="
                                                searchPerformance.status ===
                                                'reconnect_required'
                                            "
                                            class="rounded-full bg-destructive/10 px-2 py-0.5 text-[10px] font-medium text-destructive"
                                        >
                                            Reconnect required
                                        </span>
                                    </div>
                                    <p
                                        class="mt-1 max-w-2xl text-sm leading-6 text-muted-foreground"
                                    >
                                        Google Search Console shows how people
                                        discovered you. Peekchimp shows what
                                        they did after arriving.
                                    </p>
                                </div>
                            </div>
                            <Button
                                as-child
                                variant="outline"
                                size="sm"
                                class="rounded-xl"
                            >
                                <Link
                                    :href="editWebsiteSettings(project.id).url"
                                >
                                    Manage connection
                                </Link>
                            </Button>
                        </div>

                        <div
                            v-if="searchPerformance.organicFunnel"
                            class="border-b border-border/70 bg-emerald-500/[0.04] p-5 sm:p-6"
                        >
                            <p
                                class="max-w-4xl text-lg leading-8 font-medium tracking-[-0.02em] sm:text-xl"
                            >
                                Your pages appeared in Google
                                <span
                                    class="text-emerald-700 dark:text-emerald-300"
                                    >{{
                                        formatNumber(
                                            searchPerformance.organicFunnel
                                                .impressions,
                                        )
                                    }}
                                    times</span
                                >
                                and received
                                <span
                                    class="text-emerald-700 dark:text-emerald-300"
                                    >{{
                                        formatNumber(
                                            searchPerformance.organicFunnel
                                                .clicks,
                                        )
                                    }}
                                    clicks</span
                                >. Peekchimp tracked
                                <span
                                    class="text-emerald-700 dark:text-emerald-300"
                                    >{{
                                        formatNumber(
                                            searchPerformance.organicFunnel
                                                .visits,
                                        )
                                    }}
                                    visits</span
                                >;
                                {{
                                    formatNumber(
                                        searchPerformance.organicFunnel
                                            .engagedVisits,
                                    )
                                }}
                                engaged and
                                {{
                                    formatNumber(
                                        searchPerformance.organicFunnel
                                            .conversions,
                                    )
                                }}
                                completed a goal.
                            </p>
                            <p class="mt-2 text-xs text-muted-foreground">
                                This is an aggregate journey for the selected
                                period, not individual visitor tracking.
                            </p>
                        </div>

                        <div
                            v-if="searchPerformance.organicFunnel"
                            class="border-b border-border/70 p-5 sm:p-6"
                        >
                            <h3 class="text-sm font-semibold">
                                Three answers that matter
                            </h3>
                            <div class="mt-4 grid gap-3 lg:grid-cols-3">
                                <article
                                    class="rounded-xl border border-border/80 bg-muted/20 p-4"
                                >
                                    <p
                                        class="text-xs font-medium text-muted-foreground"
                                    >
                                        Are people finding the website?
                                    </p>
                                    <p
                                        class="mt-4 text-3xl font-semibold tracking-tight tabular-nums"
                                    >
                                        {{
                                            formatNumber(
                                                searchPerformance.organicFunnel
                                                    .clicks,
                                            )
                                        }}
                                        <span
                                            class="text-sm font-normal text-muted-foreground"
                                            >Google clicks</span
                                        >
                                    </p>
                                    <p
                                        class="mt-2 text-sm leading-6 text-muted-foreground"
                                    >
                                        Google showed your pages to people who
                                        were searching for related topics.
                                    </p>
                                </article>
                                <article
                                    class="rounded-xl border border-border/80 bg-muted/20 p-4"
                                >
                                    <p
                                        class="text-xs font-medium text-muted-foreground"
                                    >
                                        What happened after they clicked?
                                    </p>
                                    <p
                                        class="mt-4 text-3xl font-semibold tracking-tight tabular-nums"
                                    >
                                        {{
                                            formatNumber(
                                                searchPerformance.organicFunnel
                                                    .engagedVisits,
                                            )
                                        }}
                                        <span
                                            class="text-sm font-normal text-muted-foreground"
                                            >engaged visits</span
                                        >
                                    </p>
                                    <p
                                        class="mt-2 text-sm leading-6 text-muted-foreground"
                                    >
                                        Peekchimp measured what visitors did
                                        after they reached the website.
                                    </p>
                                </article>
                                <article
                                    class="rounded-xl border border-emerald-500/20 bg-emerald-500/[0.04] p-4"
                                >
                                    <p
                                        class="text-xs font-medium text-muted-foreground"
                                    >
                                        Did the traffic create value?
                                    </p>
                                    <p
                                        class="mt-4 text-3xl font-semibold tracking-tight tabular-nums"
                                    >
                                        {{
                                            formatNumber(
                                                searchPerformance.organicFunnel
                                                    .conversions,
                                            )
                                        }}
                                        <span
                                            class="text-sm font-normal text-muted-foreground"
                                            >goal conversions</span
                                        >
                                    </p>
                                    <p
                                        class="mt-2 text-sm leading-6 text-muted-foreground"
                                    >
                                        These visits completed a goal configured
                                        in Peekchimp.
                                    </p>
                                </article>
                            </div>
                        </div>

                        <div
                            v-if="searchPerformance.insights?.length"
                            class="border-b border-border/70 p-5 sm:p-6"
                        >
                            <article class="flex items-start gap-3">
                                <span
                                    class="flex size-9 shrink-0 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-700 dark:text-emerald-300"
                                >
                                    <Lightbulb class="size-4" />
                                </span>
                                <div>
                                    <p
                                        class="text-[10px] font-medium tracking-wider text-muted-foreground uppercase"
                                    >
                                        What to improve next
                                    </p>
                                    <p class="mt-1 text-sm font-semibold">
                                        {{
                                            searchPerformance.insights[0].title
                                        }}
                                    </p>
                                    <p
                                        class="mt-1 text-sm leading-6 text-muted-foreground"
                                    >
                                        {{
                                            searchPerformance.insights[0]
                                                .recommendation
                                        }}
                                    </p>
                                </div>
                            </article>
                        </div>

                        <details class="group p-5 sm:p-6">
                            <summary
                                class="flex cursor-pointer list-none items-center justify-between gap-4 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                            >
                                <span>
                                    <span class="block text-sm font-semibold">
                                        Explore the supporting data
                                    </span>
                                    <span
                                        class="mt-1 block text-xs text-muted-foreground"
                                    >
                                        Landing pages, search queries, and
                                        detailed performance metrics
                                    </span>
                                </span>
                                <span
                                    class="flex size-7 shrink-0 items-center justify-center rounded-full border border-border text-sm text-muted-foreground transition-transform group-open:rotate-45"
                                    aria-hidden="true"
                                >
                                    +
                                </span>
                            </summary>

                            <div class="mt-5 border-t border-border/70 pt-5">
                                <div
                                    class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div>
                                        <h3 class="text-sm font-semibold">
                                            Supporting data
                                        </h3>
                                        <p
                                            class="mt-1 text-xs text-muted-foreground"
                                        >
                                            Use this to investigate a specific
                                            page or query.
                                        </p>
                                    </div>
                                    <div
                                        class="grid grid-cols-2 rounded-xl bg-muted/60 p-1"
                                        role="tablist"
                                    >
                                        <button
                                            v-for="tab in [
                                                {
                                                    id: 'landingPages',
                                                    label: 'Landing pages',
                                                },
                                                {
                                                    id: 'queries',
                                                    label: 'Queries',
                                                },
                                            ] as const"
                                            :key="tab.id"
                                            type="button"
                                            role="tab"
                                            class="rounded-lg px-4 py-1.5 text-xs font-medium transition-colors"
                                            :class="
                                                activeSearchTab === tab.id
                                                    ? 'bg-card text-primary shadow-xs'
                                                    : 'text-muted-foreground hover:text-foreground'
                                            "
                                            :aria-selected="
                                                activeSearchTab === tab.id
                                            "
                                            @click="activeSearchTab = tab.id"
                                        >
                                            {{ tab.label }}
                                        </button>
                                    </div>
                                </div>

                                <template
                                    v-if="activeSearchTab === 'landingPages'"
                                >
                                    <div class="mt-4 overflow-x-auto">
                                        <table
                                            class="w-full min-w-[760px] text-left"
                                        >
                                            <thead>
                                                <tr
                                                    class="border-b border-border/70 text-[10px] tracking-wider text-muted-foreground uppercase"
                                                >
                                                    <th
                                                        class="pb-2 font-medium"
                                                    >
                                                        Landing page
                                                    </th>
                                                    <th
                                                        class="pb-2 text-right font-medium"
                                                    >
                                                        Impressions
                                                    </th>
                                                    <th
                                                        class="pb-2 text-right font-medium"
                                                    >
                                                        Clicks
                                                    </th>
                                                    <th
                                                        class="pb-2 text-right font-medium"
                                                    >
                                                        Visits
                                                    </th>
                                                    <th
                                                        class="pb-2 text-right font-medium"
                                                    >
                                                        Conversions
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr
                                                    v-for="landingPage in searchPerformance.landingPages ??
                                                    []"
                                                    :key="landingPage.path"
                                                    class="cursor-pointer border-b border-border/50 last:border-0 hover:bg-muted/30"
                                                    @click="
                                                        selectedLandingPagePath =
                                                            landingPage.path
                                                    "
                                                >
                                                    <td
                                                        class="py-3 pr-4 font-mono text-xs"
                                                    >
                                                        {{ landingPage.path }}
                                                    </td>
                                                    <td
                                                        class="py-3 text-right text-xs tabular-nums"
                                                    >
                                                        {{
                                                            formatNumber(
                                                                landingPage.impressions,
                                                            )
                                                        }}
                                                    </td>
                                                    <td
                                                        class="py-3 text-right text-xs tabular-nums"
                                                    >
                                                        {{
                                                            formatNumber(
                                                                landingPage.clicks,
                                                            )
                                                        }}
                                                    </td>
                                                    <td
                                                        class="py-3 text-right text-xs tabular-nums"
                                                    >
                                                        {{
                                                            formatNumber(
                                                                landingPage.visits,
                                                            )
                                                        }}
                                                    </td>
                                                    <td
                                                        class="py-3 text-right text-xs tabular-nums"
                                                    >
                                                        {{
                                                            formatNumber(
                                                                landingPage.conversions,
                                                            )
                                                        }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div
                                        v-if="selectedLandingPage"
                                        class="mt-4 rounded-xl border border-border/80 bg-muted/20 p-4"
                                    >
                                        <p class="text-xs font-semibold">
                                            Queries leading to
                                            {{ selectedLandingPage.path }}
                                        </p>
                                        <p
                                            class="mt-1 text-[10px] text-muted-foreground"
                                        >
                                            Aggregate correlation, not
                                            visitor-level attribution
                                        </p>
                                        <div
                                            v-if="
                                                selectedLandingPage.topQueries
                                                    .length
                                            "
                                            class="mt-3 grid gap-2 md:grid-cols-2"
                                        >
                                            <div
                                                v-for="query in selectedLandingPage.topQueries"
                                                :key="query.query"
                                                class="rounded-lg bg-card px-3 py-2 text-xs"
                                            >
                                                {{ query.query }}
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                <div v-else class="mt-4 grid gap-2">
                                    <div
                                        v-for="query in searchPerformance.queries ??
                                        []"
                                        :key="query.label"
                                        class="flex items-center justify-between gap-4 rounded-lg border border-border/70 px-3 py-2"
                                    >
                                        <span
                                            class="truncate font-mono text-xs"
                                        >
                                            {{ query.label }}
                                        </span>
                                        <span
                                            class="shrink-0 text-xs text-muted-foreground tabular-nums"
                                        >
                                            {{ formatNumber(query.clicks) }}
                                            clicks
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </details>
                    </Card>

                    <Card
                        v-else
                        class="rounded-2xl border-border/80 p-6 text-center shadow-xs"
                    >
                        <Search class="mx-auto size-5 text-muted-foreground" />
                        <h2 class="mt-3 font-semibold">
                            Search data is being prepared
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            The first import can take a few minutes. Existing
                            data remains visible if Google asks you to
                            reconnect.
                        </p>
                    </Card>
                </section>

                <template #fallback>
                    <Card
                        class="h-72 animate-pulse rounded-2xl border-border/80 bg-muted/30 shadow-xs"
                        aria-label="Loading organic search performance"
                    />
                </template>
            </Deferred>

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
