<script setup lang="ts">
import { ArrowDownRight, ArrowUpRight, Minus } from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import { Card } from '@/components/ui/card';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

type TrendState = 'positive' | 'negative' | 'neutral';
type ChartPoint = { x: number; y: number };

const props = withDefaults(
    defineProps<{
        animationKey: string;
        change: number | null;
        comparisonLabel?: string;
        currentValue: number;
        detail: string;
        icon: Component;
        inverse?: boolean;
        isLive?: boolean;
        label: string;
        previousValue: number;
        previousValueLabel: string;
        series: number[];
        value: string | number;
    }>(),
    {
        comparisonLabel: 'Previous period',
        inverse: false,
        isLive: false,
    },
);

const chartWidth = 180;
const chartHeight = 48;
const chartPadding = 2;

const trendState = computed<TrendState>(() => {
    if (props.currentValue === props.previousValue) {
        return 'neutral';
    }

    const increased = props.currentValue > props.previousValue;
    const improved = props.inverse ? !increased : increased;

    return improved ? 'positive' : 'negative';
});

const stateClass = computed(() => {
    if (trendState.value === 'positive') {
        return 'text-success';
    }

    if (trendState.value === 'negative') {
        return 'text-destructive';
    }

    return 'text-muted-foreground';
});

const trendIcon = computed(() => {
    if (props.currentValue > props.previousValue) {
        return ArrowUpRight;
    }

    if (props.currentValue < props.previousValue) {
        return ArrowDownRight;
    }

    return Minus;
});

const trendLabel = computed(() => {
    if (props.change === null) {
        return props.currentValue > 0 ? 'New' : 'No prior data';
    }

    if (props.change === 0) {
        return '0%';
    }

    const sign = props.change > 0 ? '+' : '−';
    const value = new Intl.NumberFormat(undefined, {
        maximumFractionDigits: 1,
    }).format(Math.abs(props.change));

    return `${sign}${value}%`;
});

const chartPoints = computed<ChartPoint[]>(() => {
    const values = props.series.length > 1 ? props.series : [0, 0];
    const minimum = Math.min(...values);
    const maximum = Math.max(...values);
    const range = maximum - minimum;
    const usableHeight = chartHeight - chartPadding * 2;
    const step = (chartWidth - chartPadding * 2) / (values.length - 1);

    return values.map((value, index) => ({
        x: chartPadding + step * index,
        y:
            range === 0
                ? chartHeight / 2
                : chartPadding + ((maximum - value) / range) * usableHeight,
    }));
});

const linePath = computed(() => smoothPath(chartPoints.value));
const areaPath = computed(() => {
    const points = chartPoints.value;

    if (points.length === 0) {
        return '';
    }

    return `${linePath.value} L ${points.at(-1)?.x ?? chartWidth} ${chartHeight} L ${points[0].x} ${chartHeight} Z`;
});
const gradientId = computed(
    () =>
        `metric-${props.label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-area`,
);

function smoothPath(points: ChartPoint[]): string {
    if (points.length === 0) {
        return '';
    }

    let path = `M ${points[0].x} ${points[0].y}`;

    for (let index = 1; index < points.length; index++) {
        const previous = points[index - 1];
        const current = points[index];
        const midpoint = (previous.x + current.x) / 2;

        path += ` C ${midpoint} ${previous.y}, ${midpoint} ${current.y}, ${current.x} ${current.y}`;
    }

    return path;
}
</script>

<template>
    <Card class="gap-0 overflow-hidden p-1">
        <div
            class="flex h-full min-h-40 flex-col rounded-xl bg-background/70 p-3.5 sm:p-4"
        >
            <div class="flex items-start gap-3">
                <span
                    :class="[
                        'flex size-8 shrink-0 items-center justify-center rounded-md border',
                        isLive
                            ? 'border-primary/25 bg-primary/10 text-primary'
                            : 'border-border bg-card text-muted-foreground',
                    ]"
                    aria-hidden="true"
                >
                    <component :is="icon" class="size-4" />
                </span>
                <div
                    :key="`${animationKey}-${value}`"
                    class="data-arrive min-w-0 flex-1"
                >
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                class="group/value block max-w-full cursor-help rounded-md text-left outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                                :aria-label="`${label}: ${value}. More information`"
                            >
                                <span
                                    class="block text-xl font-medium tracking-[-0.035em] tabular-nums transition-colors group-hover/value:text-primary sm:text-2xl"
                                >
                                    {{ value }}
                                </span>
                            </button>
                        </TooltipTrigger>
                        <TooltipContent class="max-w-80 !text-wrap" side="top">
                            {{ detail }}
                        </TooltipContent>
                    </Tooltip>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ label }}
                    </p>
                </div>
            </div>

            <svg
                class="mt-4 h-10 w-full overflow-visible"
                :class="stateClass"
                :viewBox="`0 0 ${chartWidth} ${chartHeight}`"
                preserveAspectRatio="none"
                aria-hidden="true"
            >
                <defs>
                    <linearGradient
                        :id="gradientId"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="0%"
                            stop-color="currentColor"
                            stop-opacity="0.22"
                        />
                        <stop
                            offset="100%"
                            stop-color="currentColor"
                            stop-opacity="0"
                        />
                    </linearGradient>
                </defs>
                <g :key="animationKey" class="metric-sparkline-arrive">
                    <path :d="areaPath" :fill="`url(#${gradientId})`" />
                    <path
                        :d="linePath"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        vector-effect="non-scaling-stroke"
                    />
                </g>
            </svg>

            <div class="mt-auto flex flex-col items-start gap-1">
                <span
                    :class="[
                        'inline-flex items-center gap-1 text-xs font-medium tabular-nums',
                        stateClass,
                    ]"
                >
                    <component
                        :is="trendIcon"
                        class="size-3"
                        aria-hidden="true"
                    />
                    {{ trendLabel }} vs comparison period
                </span>
                <span
                    class="text-[11px] leading-4 text-muted-foreground tabular-nums"
                >
                    {{ comparisonLabel }}: {{ previousValueLabel }}
                </span>
            </div>
        </div>
    </Card>
</template>

<style scoped>
.metric-sparkline-arrive {
    clip-path: inset(0 100% 0 0);
    animation: metric-sparkline-reveal 500ms cubic-bezier(0.2, 0, 0, 1) forwards;
}

@keyframes metric-sparkline-reveal {
    to {
        clip-path: inset(0 0 0 0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .metric-sparkline-arrive {
        clip-path: none;
        animation: none;
    }
}
</style>
