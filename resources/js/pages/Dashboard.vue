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
import DashboardTrafficChart from '@/components/dashboard/DashboardTrafficChart.vue';
import MetricTrendCard from '@/components/dashboard/MetricTrendCard.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { dashboard } from '@/routes';
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
    };
    metricTrends: {
        activeVisitors: MetricTrend;
        visitors: MetricTrend;
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
    referrers: Breakdown[];
    countryVisits: CountryVisits;
    devices: Breakdown[];
    browsers: Breakdown[];
    campaigns: Breakdown[];
    aiReferrals: { totalVisits: number; sources: Breakdown[] };
    insights: DashboardInsight[];
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
        items: props.analytics.referrers,
        kind: 'source' as const,
        total: totalOf(props.analytics.referrers),
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
        id: 'ai',
        label: 'AI referrals',
        description: 'Links and tags only; individual answers stay private',
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
]);

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
            class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 pt-6 pb-20 sm:px-6 sm:pt-8"
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
                            >{{
                                formatNumber(analytics.metrics.activeVisitors)
                            }}</strong
                        >
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
                            Refresh now. Data also updates quietly every 30
                            seconds.
                        </TooltipContent>
                    </Tooltip>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                as-child
                                variant="outline"
                                size="icon"
                                aria-label="Website settings"
                            >
                                <Link :href="editWebsiteSettings(project.id)">
                                    <Settings
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent side="bottom">
                            Website settings
                        </TooltipContent>
                    </Tooltip>
                </div>
            </header>

            <section
                class="flex flex-col gap-3"
                aria-labelledby="metrics-title"
            >
                <div>
                    <h2 id="metrics-title" class="text-sm font-medium">
                        At a glance
                    </h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Each metric is compared with the previous matching
                        period.
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
                    <MetricTrendCard
                        v-for="metric in metricCards"
                        :key="metric.label"
                        :animation-key="`${analytics.range.key}-${metric.label}`"
                        :label="metric.label"
                        :value="metric.value"
                        :detail="metric.detail"
                        :icon="metric.icon"
                        :current-value="metric.currentValue"
                        :previous-value="metric.previousValue"
                        :previous-value-label="metric.previousValueLabel"
                        :change="metric.change"
                        :series="metric.series"
                        :comparison-label="metric.comparisonLabel"
                        :inverse="metric.inverse"
                        :is-live="metric.isLive"
                    />
                </div>
            </section>

            <DashboardTrafficChart
                :range="analytics.range"
                :metrics="analytics.metrics"
                :timeseries="analytics.timeseries"
            />

            <div class="grid gap-3 lg:grid-cols-12">
                <Card
                    class="order-2 gap-0 overflow-hidden p-1 lg:order-1 lg:col-span-7"
                >
                    <div
                        class="flex h-full flex-col rounded-xl bg-background/70 p-4 sm:p-5"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex items-start gap-3">
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-card text-muted-foreground"
                                    aria-hidden="true"
                                >
                                    <FileText class="size-4" />
                                </span>
                                <div>
                                    <h2 class="font-medium">
                                        Most visited pages
                                    </h2>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        Pages with the most views
                                    </p>
                                </div>
                            </div>
                            <span
                                class="shrink-0 font-mono text-xs text-muted-foreground tabular-nums"
                            >
                                {{ formatNumber(analytics.metrics.pageviews) }}
                                views
                            </span>
                        </div>
                        <div class="mt-4 flex h-56 flex-col gap-1">
                            <div
                                v-for="(
                                    page, index
                                ) in analytics.topPages.slice(0, 5)"
                                :key="page.label"
                                class="group/page relative flex min-h-10 items-center gap-3 overflow-hidden rounded-md px-3 py-2 text-sm transition-colors duration-100 hover:bg-accent/70"
                            >
                                <span
                                    class="absolute inset-y-0 left-0 bg-foreground/[0.035] transition-[width] duration-200 ease-out motion-reduce:transition-none"
                                    :style="{
                                        width: `${percentageOf(page.value, analytics.metrics.pageviews)}%`,
                                    }"
                                    aria-hidden="true"
                                />
                                <FileText
                                    class="relative size-4 shrink-0 text-muted-foreground transition-transform duration-200 group-hover/page:translate-x-0.5 motion-reduce:transform-none"
                                    aria-hidden="true"
                                />
                                <span
                                    class="relative w-5 shrink-0 font-mono text-[10px] text-muted-foreground"
                                >
                                    {{ String(index + 1).padStart(2, '0') }}
                                </span>
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
                            <div
                                v-if="analytics.topPages.length === 0"
                                class="flex flex-1 flex-col items-center justify-center gap-2 text-sm text-muted-foreground"
                                role="status"
                            >
                                <FileText class="size-5" aria-hidden="true" />
                                No page views in this period.
                            </div>
                        </div>
                    </div>
                </Card>

                <Card
                    class="order-1 gap-0 overflow-hidden p-1 lg:order-2 lg:col-span-5"
                >
                    <div
                        class="flex h-full flex-col rounded-xl bg-background/70 p-4 sm:p-5"
                    >
                        <div class="flex items-start gap-3">
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-card text-muted-foreground"
                                aria-hidden="true"
                            >
                                <Lightbulb class="size-4" />
                            </span>
                            <div>
                                <h2 class="font-medium">What stands out</h2>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Patterns worth a closer look
                                </p>
                            </div>
                        </div>

                        <div class="mt-4 flex h-56 flex-col gap-2">
                            <div
                                v-for="insight in insightCards"
                                :key="insight.type"
                                class="flex flex-1 items-start gap-3 rounded-xl border border-border/80 bg-card/55 p-3.5"
                            >
                                <span
                                    class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-background"
                                    :class="{
                                        'text-warning':
                                            insight.tone === 'attention',
                                        'text-success':
                                            insight.tone === 'positive',
                                        'text-muted-foreground':
                                            insight.tone === 'neutral',
                                    }"
                                    aria-hidden="true"
                                >
                                    <component
                                        :is="insight.icon"
                                        class="size-3.5"
                                    />
                                </span>
                                <div class="min-w-0">
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
                </Card>
            </div>

            <section
                class="grid gap-3 lg:grid-cols-2"
                aria-label="Traffic and audience details"
            >
                <DashboardBreakdownCard
                    id="acquisition"
                    title="Acquisition"
                    description="How people reached your website"
                    :icon="Waypoints"
                    :tabs="acquisitionTabs"
                />
                <DashboardBreakdownCard
                    id="audience"
                    title="Audience"
                    description="Anonymous details about your visitors"
                    :icon="UsersRound"
                    :tabs="audienceTabs"
                />
            </section>
        </div>
    </TooltipProvider>
</template>

<style scoped>
.data-arrive {
    animation: data-arrive 160ms ease-out both;
}

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

@keyframes data-arrive {
    from {
        opacity: 0.45;
        filter: blur(4px);
        transform: translateY(2px);
    }

    to {
        opacity: 1;
        filter: blur(0);
        transform: translateY(0);
    }
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
    .data-arrive,
    .tracking-status::before {
        animation: none;
    }
}
</style>
