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
import AppearanceMenu from '@/components/AppearanceMenu.vue';
import DashboardBreakdownCard from '@/components/dashboard/DashboardBreakdownCard.vue';
import DashboardOverview from '@/components/dashboard/DashboardOverview.vue';
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

const primaryMetrics = computed(() => {
    if (metricCards.value.length > 0) {
        return metricCards.value.slice(0, 2).map((metric) => ({
            ...metric,
            id: metric.label.toLowerCase().replaceAll(' ', '-'),
        }));
    }

    const traffic = props.analytics.traffic;

    if (!traffic) {
        return [];
    }

    return [
        {
            id: 'visitors',
            label: 'Visitors',
            value: formatNumber(traffic.metrics.visitors),
            detail: 'Estimated visitors during this period.',
            currentValue: traffic.metrics.visitors,
        },
        {
            id: 'views',
            label: 'Views',
            value: formatNumber(traffic.metrics.pageviews),
            detail: 'Total page loads, including repeats.',
            currentValue: traffic.metrics.pageviews,
        },
    ];
});

const secondaryMetrics = computed(() =>
    metricCards.value.slice(2).map((metric) => ({
        ...metric,
        id: metric.label.toLowerCase().replaceAll(' ', '-'),
    })),
);

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

type PublicAnalysisTab = 'pages' | 'acquisition' | 'audience';

const analysisTabs = computed<Array<{ id: PublicAnalysisTab; label: string }>>(
    () => [
        ...(hasSection('pages')
            ? [{ id: 'pages' as const, label: 'Pages' }]
            : []),
        ...(hasSection('acquisition')
            ? [{ id: 'acquisition' as const, label: 'Acquisition' }]
            : []),
        ...(hasSection('audience')
            ? [{ id: 'audience' as const, label: 'Audience' }]
            : []),
    ],
);
const activeAnalysisTab = ref<PublicAnalysisTab>('pages');

watch(
    analysisTabs,
    (tabs) => {
        if (!tabs.some((tab) => tab.id === activeAnalysisTab.value)) {
            activeAnalysisTab.value = tabs[0]?.id ?? 'pages';
        }
    },
    { immediate: true },
);

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
        <Head :title="project.name + ' analytics'">
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
                        <span class="truncate text-sm font-medium">
                            {{ project.name }}
                        </span>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span class="text-xs text-muted-foreground">
                            Shared dashboard
                        </span>
                        <AppearanceMenu />
                    </div>
                </div>
            </header>

            <main
                class="mx-auto flex w-full max-w-6xl flex-col gap-5 px-4 pt-6 pb-20 sm:gap-6 sm:px-6 sm:pt-8"
            >
                <header
                    class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"
                >
                    <div class="min-w-0">
                        <p
                            class="mb-2 text-xs tracking-[0.12em] text-muted-foreground uppercase"
                        >
                            Website analytics
                        </p>
                        <h1
                            class="truncate text-3xl font-medium tracking-[-0.045em]"
                        >
                            {{ project.name }}
                        </h1>
                        <p
                            class="mt-2 flex items-center gap-1.5 text-sm text-muted-foreground"
                        >
                            <span class="truncate">
                                {{ project.domain ?? 'Website analytics' }}
                            </span>
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

                <DashboardOverview
                    v-if="analytics.metrics || analytics.traffic"
                    :primary-metrics="primaryMetrics"
                    :secondary-metrics="secondaryMetrics"
                    :range="analytics.range"
                    :chart-metrics="analytics.traffic?.metrics"
                    :timeseries="analytics.traffic?.timeseries ?? []"
                />

                <Card
                    v-if="analysisTabs.length"
                    class="gap-0 overflow-hidden p-1"
                >
                    <div class="rounded-xl bg-background/70">
                        <div
                            class="flex flex-col gap-4 px-4 pt-4 sm:flex-row sm:items-center sm:justify-between sm:px-5"
                        >
                            <div>
                                <h2 class="font-medium">Explore</h2>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Shared details for this website.
                                </p>
                            </div>
                            <div
                                v-if="analysisTabs.length > 1"
                                class="max-w-full overflow-x-auto pb-1"
                            >
                                <div
                                    class="inline-flex min-w-max items-center gap-1 rounded-full border border-border bg-card p-1"
                                    role="tablist"
                                    aria-label="Shared dashboard analysis"
                                >
                                    <button
                                        v-for="tab in analysisTabs"
                                        :id="'public-' + tab.id + '-tab'"
                                        :key="tab.id"
                                        type="button"
                                        role="tab"
                                        class="h-8 rounded-full px-3 text-xs font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                                        :class="
                                            activeAnalysisTab === tab.id
                                                ? 'bg-secondary text-foreground'
                                                : 'text-muted-foreground hover:text-foreground'
                                        "
                                        :aria-selected="
                                            activeAnalysisTab === tab.id
                                        "
                                        :aria-controls="
                                            'public-' + tab.id + '-panel'
                                        "
                                        :tabindex="
                                            activeAnalysisTab === tab.id
                                                ? 0
                                                : -1
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

                        <Transition name="public-panel" mode="out-in">
                            <section
                                v-if="
                                    activeAnalysisTab === 'pages' &&
                                    analytics.pages
                                "
                                id="public-pages-panel"
                                key="pages"
                                class="px-4 py-5 sm:px-5"
                                role="tabpanel"
                                aria-labelledby="public-pages-tab"
                            >
                                <div
                                    class="mb-4 flex items-start justify-between gap-4"
                                >
                                    <div>
                                        <h3 class="text-sm font-medium">
                                            Most visited pages
                                        </h3>
                                        <p
                                            class="mt-1 text-xs text-muted-foreground"
                                        >
                                            Pages with the most views
                                        </p>
                                    </div>
                                    <span
                                        class="font-mono text-xs text-muted-foreground tabular-nums"
                                    >
                                        {{
                                            formatNumber(analytics.pages.total)
                                        }}
                                        views
                                    </span>
                                </div>
                                <div
                                    class="min-h-56 overflow-hidden rounded-xl border border-border/80 bg-card/55 py-2"
                                >
                                    <div
                                        v-for="(
                                            page, index
                                        ) in analytics.pages.items.slice(0, 8)"
                                        :key="page.label"
                                        class="flex min-h-10 items-center gap-3 px-3 py-2 text-sm"
                                    >
                                        <span
                                            class="w-5 shrink-0 font-mono text-[10px] text-muted-foreground"
                                        >
                                            {{
                                                String(index + 1).padStart(
                                                    2,
                                                    '0',
                                                )
                                            }}
                                        </span>
                                        <FileText
                                            class="size-4 shrink-0 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <span
                                            class="min-w-0 flex-1 truncate font-mono text-xs"
                                        >
                                            {{ page.label }}
                                        </span>
                                        <span
                                            class="shrink-0 font-mono text-xs text-muted-foreground tabular-nums"
                                        >
                                            {{ formatNumber(page.value) }}
                                        </span>
                                    </div>
                                    <p
                                        v-if="
                                            analytics.pages.items.length === 0
                                        "
                                        class="py-20 text-center text-sm text-muted-foreground"
                                        role="status"
                                    >
                                        No page views in this period.
                                    </p>
                                </div>
                            </section>

                            <section
                                v-else-if="
                                    activeAnalysisTab === 'acquisition' &&
                                    acquisitionTabs.length
                                "
                                id="public-acquisition-panel"
                                key="acquisition"
                                role="tabpanel"
                                aria-labelledby="public-acquisition-tab"
                            >
                                <DashboardBreakdownCard
                                    embedded
                                    id="public-acquisition"
                                    title="Acquisition"
                                    description="How people reached this website"
                                    :icon="Waypoints"
                                    :tabs="acquisitionTabs"
                                />
                            </section>

                            <section
                                v-else-if="audienceTabs.length"
                                id="public-audience-panel"
                                key="audience"
                                role="tabpanel"
                                aria-labelledby="public-audience-tab"
                            >
                                <DashboardBreakdownCard
                                    embedded
                                    id="public-audience"
                                    title="Audience"
                                    description="Anonymous details about visitors"
                                    :icon="UsersRound"
                                    :tabs="audienceTabs"
                                />
                            </section>
                        </Transition>
                    </div>
                </Card>

                <footer class="pt-2 text-center text-xs text-muted-foreground">
                    Shared with Peekchimp · Data updates as the website receives
                    visits.
                </footer>
            </main>
        </div>
    </TooltipProvider>
</template>

<style scoped>
.public-panel-enter-active {
    transition:
        opacity 160ms ease-out,
        transform 160ms cubic-bezier(0.2, 0, 0, 1);
}

.public-panel-leave-active {
    transition:
        opacity 100ms ease-in,
        transform 100ms ease-in;
}

.public-panel-enter-from {
    opacity: 0;
    transform: translateY(2px);
}

.public-panel-leave-to {
    opacity: 0;
    transform: translateY(-2px);
}
</style>
