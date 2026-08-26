<script setup lang="ts">
import { ArrowDownRight, ArrowUpRight, Minus } from '@lucide/vue';
import DashboardTrafficChart from '@/components/dashboard/DashboardTrafficChart.vue';
import { Card } from '@/components/ui/card';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

type DashboardMetric = {
    id: string;
    label: string;
    value: string | number;
    detail: string;
    currentValue: number;
    previousValue?: number;
    previousValueLabel?: string;
    change?: number | null;
    inverse?: boolean;
};

type SeriesPoint = { label: string; pageviews: number; visitors: number };

withDefaults(
    defineProps<{
        primaryMetrics: DashboardMetric[];
        secondaryMetrics?: DashboardMetric[];
        range: {
            key: string;
            label: string;
            interval: 'hour' | 'day';
        };
        chartMetrics?: { pageviews: number; visitors: number };
        timeseries?: SeriesPoint[];
    }>(),
    {
        secondaryMetrics: () => [],
        chartMetrics: undefined,
        timeseries: () => [],
    },
);

function trendLabel(metric: DashboardMetric): string {
    if (metric.previousValue === undefined) {
        return 'Current period';
    }

    if (metric.change == null) {
        return metric.currentValue > 0 ? 'New' : 'No prior data';
    }

    if (metric.change === 0) {
        return '0%';
    }

    const value = new Intl.NumberFormat(undefined, {
        maximumFractionDigits: 1,
    }).format(Math.abs(metric.change));

    return `${metric.change > 0 ? '+' : '−'}${value}%`;
}

function trendIcon(metric: DashboardMetric) {
    if (metric.previousValue === undefined) {
        return Minus;
    }

    if (metric.currentValue > metric.previousValue) {
        return ArrowUpRight;
    }

    if (metric.currentValue < metric.previousValue) {
        return ArrowDownRight;
    }

    return Minus;
}

function trendClass(metric: DashboardMetric): string {
    if (metric.previousValue === undefined) {
        return 'text-muted-foreground';
    }

    if (metric.currentValue === metric.previousValue) {
        return 'text-muted-foreground';
    }

    const increased = metric.currentValue > metric.previousValue;
    const improved = metric.inverse ? !increased : increased;

    return improved ? 'text-success' : 'text-destructive';
}
</script>

<template>
    <Card class="gap-0 overflow-hidden p-1">
        <div class="rounded-xl bg-background/70">
            <div
                class="flex items-start justify-between gap-4 px-4 py-4 sm:px-5"
            >
                <div>
                    <h2 class="font-medium">Overview</h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        The numbers that best describe this period.
                    </p>
                </div>
                <span
                    class="rounded-full bg-secondary px-2.5 py-1 font-mono text-[10px] text-muted-foreground tabular-nums"
                >
                    {{ range.label }}
                </span>
            </div>

            <div
                class="grid grid-cols-2 border-y border-border/80 bg-card/55"
                :class="primaryMetrics.length >= 4 && 'lg:grid-cols-4'"
                aria-label="Primary metrics"
            >
                <div
                    v-for="(metric, index) in primaryMetrics"
                    :key="metric.id"
                    class="min-w-0 px-4 py-4 sm:px-5"
                    :class="[
                        index % 2 === 1 && 'border-l border-border/80',
                        index >= 2 && 'border-t border-border/80 lg:border-t-0',
                        index === 2 && 'lg:border-l lg:border-border/80',
                    ]"
                >
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                class="block max-w-full cursor-help rounded-md text-left outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                                :aria-label="`${metric.label}: ${metric.value}. More information`"
                            >
                                <span
                                    class="block truncate text-2xl font-medium tracking-[-0.04em] tabular-nums sm:text-3xl"
                                >
                                    {{ metric.value }}
                                </span>
                                <span
                                    class="mt-1 block text-xs text-muted-foreground"
                                >
                                    {{ metric.label }}
                                </span>
                            </button>
                        </TooltipTrigger>
                        <TooltipContent class="max-w-72 !text-wrap" side="top">
                            {{ metric.detail }}
                            <template v-if="metric.previousValueLabel">
                                Previous period:
                                {{ metric.previousValueLabel }}.
                            </template>
                        </TooltipContent>
                    </Tooltip>
                    <span
                        class="mt-3 inline-flex items-center gap-1 text-[11px] font-medium tabular-nums"
                        :class="trendClass(metric)"
                    >
                        <component
                            :is="trendIcon(metric)"
                            class="size-3"
                            aria-hidden="true"
                        />
                        {{ trendLabel(metric) }}
                    </span>
                </div>
            </div>

            <div
                v-if="secondaryMetrics.length"
                class="grid grid-cols-2 border-b border-border/80"
                :class="[
                    secondaryMetrics.length === 3 && 'sm:grid-cols-3',
                    secondaryMetrics.length >= 4 && 'sm:grid-cols-4',
                ]"
                aria-label="Engagement metrics"
            >
                <div
                    v-for="(metric, index) in secondaryMetrics"
                    :key="metric.id"
                    class="min-w-0 px-4 py-3.5 sm:px-5"
                    :class="[
                        index % 2 === 1 && 'border-l border-border/80',
                        index >= 2 && 'border-t border-border/80 sm:border-t-0',
                        index === 2 && 'sm:border-l sm:border-border/80',
                    ]"
                >
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                class="block max-w-full cursor-help rounded-md text-left outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                                :aria-label="`${metric.label}: ${metric.value}. More information`"
                            >
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{ metric.label }}
                                </span>
                                <span
                                    class="mt-1.5 block truncate font-mono text-base font-medium tabular-nums"
                                >
                                    {{ metric.value }}
                                </span>
                            </button>
                        </TooltipTrigger>
                        <TooltipContent class="max-w-72 !text-wrap" side="top">
                            {{ metric.detail }}
                            <template v-if="metric.previousValueLabel">
                                Previous period:
                                {{ metric.previousValueLabel }}.
                            </template>
                        </TooltipContent>
                    </Tooltip>
                    <span
                        class="mt-1 inline-flex items-center gap-1 text-[10px] tabular-nums"
                        :class="trendClass(metric)"
                    >
                        <component
                            :is="trendIcon(metric)"
                            class="size-3"
                            aria-hidden="true"
                        />
                        {{ trendLabel(metric) }}
                    </span>
                </div>
            </div>

            <div v-if="chartMetrics" class="p-4 sm:p-5">
                <DashboardTrafficChart
                    embedded
                    :range="range"
                    :metrics="chartMetrics"
                    :timeseries="timeseries"
                />
            </div>
        </div>
    </Card>
</template>
