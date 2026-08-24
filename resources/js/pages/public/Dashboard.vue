<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    FileStack,
    FileText,
    Globe2,
    LogOut,
    RefreshCw,
    ScanEye,
    Timer,
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
import { TooltipProvider } from '@/components/ui/tooltip';
import { show as publicDashboard } from '@/routes/shared/dashboard';

type PublicSection =
    'metrics' | 'traffic' | 'pages' | 'acquisition' | 'audience';
type Breakdown = { label: string; value: number };
type MetricTrend = {
    previous: number;
    change: number | null;
    series: number[];
};
type Metrics = {
    pageviews: number;
    visitors: number;
    bounceRate: number;
    averageDuration: number;
    viewsPerVisitor: number;
};
type PublicAnalytics = {
    range: {
        key: string;
        label: string;
        from: string;
        to: string;
        interval: 'hour' | 'day';
    };
    metrics?: Metrics;
    metricTrends?: {
        visitors: MetricTrend;
        pageviews: MetricTrend;
        bounceRate: MetricTrend;
        viewsPerVisitor: MetricTrend;
        averageDuration: MetricTrend;
    };
    traffic?: {
        metrics: { pageviews: number; visitors: number };
        timeseries: Array<{
            label: string;
            pageviews: number;
            visitors: number;
        }>;
    };
    pages?: { total: number; items: Breakdown[] };
    acquisition?: {
        referrers: Breakdown[];
        campaigns: Breakdown[];
        aiReferrals: { totalVisits: number; sources: Breakdown[] };
    };
    audience?: {
        countryVisits: {
            total: number;
            unknown: number;
            countries: Array<{ code: string; visits: number }>;
        };
        devices: Breakdown[];
        browsers: Breakdown[];
    };
};

const props = defineProps<{
    shareToken: string;
    project: { name: string; domain: string | null; timezone: string };
    analytics: PublicAnalytics;
    visibleSections: PublicSection[];
}>();

const selectedRange = ref(props.analytics.range.key);
const isChangingRange = ref(false);
const isRefreshing = ref(false);
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
>(() => {
    const metrics = props.analytics.metrics;
    const trends = props.analytics.metricTrends;

    if (!metrics || !trends) {
        return [];
    }

    return [
        {
            label: 'Visitors',
            value: formatNumber(metrics.visitors),
            detail: 'Estimated visitors during this period.',
            icon: UserRound,
            currentValue: metrics.visitors,
            previousValue: trends.visitors.previous,
            previousValueLabel: formatNumber(trends.visitors.previous),
            change: trends.visitors.change,
            series: trends.visitors.series,
        },
        {
            label: 'Views',
            value: formatNumber(metrics.pageviews),
            detail: 'Total page loads, including repeats.',
            icon: ScanEye,
            currentValue: metrics.pageviews,
            previousValue: trends.pageviews.previous,
            previousValueLabel: formatNumber(trends.pageviews.previous),
            change: trends.pageviews.change,
            series: trends.pageviews.series,
        },
        {
            label: 'Bounce rate',
            value: formatPercentage(metrics.bounceRate),
            detail: 'Share of visits with only one page view.',
            icon: LogOut,
            currentValue: metrics.bounceRate,
            previousValue: trends.bounceRate.previous,
            previousValueLabel: formatPercentage(trends.bounceRate.previous),
            change: trends.bounceRate.change,
            series: trends.bounceRate.series,
            inverse: true,
        },
        {
            label: 'Pages per visitor',
            value: metrics.viewsPerVisitor,
            detail: 'Average pages viewed by each visitor.',
            icon: FileStack,
            currentValue: metrics.viewsPerVisitor,
            previousValue: trends.viewsPerVisitor.previous,
            previousValueLabel: formatDecimal(trends.viewsPerVisitor.previous),
            change: trends.viewsPerVisitor.change,
            series: trends.viewsPerVisitor.series,
        },
        {
            label: 'Average visit time',
            value: formatDuration(metrics.averageDuration),
            detail: 'How long each visit lasted on average.',
            icon: Timer,
            currentValue: metrics.averageDuration,
            previousValue: trends.averageDuration.previous,
            previousValueLabel: formatDuration(trends.averageDuration.previous),
            change: trends.averageDuration.change,
            series: trends.averageDuration.series,
        },
    ];
});

const acquisitionTabs = computed(() => {
    const acquisition = props.analytics.acquisition;

    if (!acquisition) {
        return [];
    }

    return [
        {
            id: 'sources',
            label: 'Sources',
            description: 'Search, other websites, and direct visits',
            emptyMessage: 'No traffic sources in this period.',
            items: acquisition.referrers,
            kind: 'source' as const,
            total: totalOf(acquisition.referrers),
        },
        {
            id: 'campaigns',
            label: 'Campaigns',
            description: 'Visits grouped by campaign tags',
            emptyMessage: 'No tagged campaign visits in this period.',
            items: acquisition.campaigns,
            kind: 'campaign' as const,
            total: totalOf(acquisition.campaigns),
        },
        {
            id: 'ai',
            label: 'AI referrals',
            description: 'Links and tags only; individual answers stay private',
            emptyMessage: 'No AI referral visits in this period.',
            items: acquisition.aiReferrals.sources,
            kind: 'ai' as const,
            total: acquisition.aiReferrals.totalVisits,
        },
    ];
});

const audienceTabs = computed(() => {
    const audience = props.analytics.audience;

    if (!audience) {
        return [];
    }

    return [
        {
            id: 'countries',
            label: 'Countries',
            description: 'Approximate country for each visit',
            emptyMessage: 'No known country visits in this period.',
            items: [
                ...audience.countryVisits.countries.map((country) => ({
                    label: country.code,
                    value: country.visits,
                })),
                ...(audience.countryVisits.unknown > 0
                    ? [
                          {
                              label: 'Unknown',
                              value: audience.countryVisits.unknown,
                          },
                      ]
                    : []),
            ],
            kind: 'country' as const,
            total: audience.countryVisits.total,
        },
        {
            id: 'devices',
            label: 'Devices',
            description: 'Phones, tablets, and computers used to visit',
            emptyMessage: 'No device data in this period.',
            items: audience.devices,
            kind: 'device' as const,
            total: totalOf(audience.devices),
        },
        {
            id: 'browsers',
            label: 'Browsers',
            description: 'Web browsers used to view your site',
            emptyMessage: 'No browser data in this period.',
            items: audience.browsers,
            kind: 'browser' as const,
            total: totalOf(audience.browsers),
        },
    ];
});

function hasSection(section: PublicSection): boolean {
    return props.visibleSections.includes(section);
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

function loadRange(range: string): void {
    selectedRange.value = range;
    isChangingRange.value = true;
    router.get(
        publicDashboard(props.shareToken),
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
    isRefreshing.value = true;
    router.reload({
        only: ['analytics'],
        onFinish: () => {
            isRefreshing.value = false;
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
        <Head :title="`${project.name} analytics`">
            <meta name="robots" content="noindex, nofollow" />
        </Head>

        <div class="min-h-svh bg-background">
            <header class="border-b border-border/80">
                <div
                    class="mx-auto flex h-14 w-full max-w-6xl items-center justify-between gap-4 px-4 sm:px-6"
                >
                    <div class="flex min-w-0 items-center gap-2.5">
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary text-primary-foreground"
                            aria-hidden="true"
                        >
                            <Globe2 class="size-4" />
                        </span>
                        <span class="truncate text-sm font-medium">{{
                            project.name
                        }}</span>
                    </div>
                    <span class="shrink-0 text-xs text-muted-foreground"
                        >Shared dashboard</span
                    >
                </div>
            </header>

            <main
                class="mx-auto flex w-full max-w-6xl flex-col gap-5 px-4 pt-6 pb-20 sm:gap-6 sm:px-6 sm:pt-8"
            >
                <header
                    class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"
                >
                    <div class="min-w-0">
                        <h1
                            class="truncate text-2xl font-medium tracking-[-0.035em] sm:text-3xl"
                        >
                            {{ project.name }}
                        </h1>
                        <p
                            class="mt-1.5 flex items-center gap-1.5 text-sm text-muted-foreground"
                        >
                            <span class="truncate">{{
                                project.domain ?? 'Website analytics'
                            }}</span>
                            <span aria-hidden="true">·</span>
                            <span class="shrink-0">{{ project.timezone }}</span>
                        </p>
                    </div>

                    <div class="flex w-full items-center gap-2 sm:w-auto">
                        <select
                            v-model="selectedRange"
                            class="select-with-chevron h-9 min-w-0 flex-1 cursor-pointer rounded-md border border-input bg-card text-sm font-medium outline-none focus:border-ring focus:ring-2 focus:ring-ring/30 sm:flex-none"
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
                        <Button
                            variant="outline"
                            size="icon"
                            :disabled="isRefreshing"
                            aria-label="Refresh dashboard"
                            @click="refresh"
                        >
                            <RefreshCw
                                :class="[
                                    'size-4',
                                    isRefreshing && 'animate-spin',
                                ]"
                            />
                        </Button>
                    </div>
                </header>

                <section
                    v-if="hasSection('metrics') && metricCards.length > 0"
                    class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5"
                    aria-label="Key metrics"
                >
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
                </section>

                <DashboardTrafficChart
                    v-if="hasSection('traffic') && analytics.traffic"
                    :range="analytics.range"
                    :metrics="analytics.traffic.metrics"
                    :timeseries="analytics.traffic.timeseries"
                />

                <Card
                    v-if="hasSection('pages') && analytics.pages"
                    class="gap-0 overflow-hidden p-1"
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
                                {{ formatNumber(analytics.pages.total) }} views
                            </span>
                        </div>
                        <div class="mt-4 flex min-h-40 flex-col gap-1">
                            <div
                                v-for="(
                                    page, index
                                ) in analytics.pages.items.slice(0, 5)"
                                :key="page.label"
                                class="flex min-h-10 items-center gap-3 overflow-hidden rounded-md px-3 py-2 text-sm"
                            >
                                <FileText
                                    class="size-4 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span
                                    class="w-5 shrink-0 font-mono text-[10px] text-muted-foreground"
                                >
                                    {{ String(index + 1).padStart(2, '0') }}
                                </span>
                                <span
                                    class="min-w-0 flex-1 truncate font-mono text-xs"
                                    >{{ page.label }}</span
                                >
                                <span
                                    class="shrink-0 font-mono text-xs text-muted-foreground tabular-nums"
                                >
                                    {{ formatNumber(page.value) }}
                                </span>
                            </div>
                            <div
                                v-if="analytics.pages.items.length === 0"
                                class="flex flex-1 items-center justify-center text-sm text-muted-foreground"
                                role="status"
                            >
                                No page views in this period.
                            </div>
                        </div>
                    </div>
                </Card>

                <section
                    v-if="
                        hasSection('acquisition') && acquisitionTabs.length > 0
                    "
                    aria-label="Traffic acquisition"
                >
                    <DashboardBreakdownCard
                        id="public-acquisition"
                        title="Acquisition"
                        description="How people reached this website"
                        :icon="Waypoints"
                        :tabs="acquisitionTabs"
                    />
                </section>

                <section
                    v-if="hasSection('audience') && audienceTabs.length > 0"
                    aria-label="Audience details"
                >
                    <DashboardBreakdownCard
                        id="public-audience"
                        title="Audience"
                        description="Anonymous details about visitors"
                        :icon="UsersRound"
                        :tabs="audienceTabs"
                    />
                </section>

                <footer class="pt-2 text-center text-xs text-muted-foreground">
                    Shared with Peekchimp · Data updates as the website receives
                    visits.
                </footer>
            </main>
        </div>
    </TooltipProvider>
</template>
