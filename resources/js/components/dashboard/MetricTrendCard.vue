<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowDownRight, ArrowRight, ArrowUpRight, Minus } from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import { Card } from '@/components/ui/card';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

type TrendState = 'positive' | 'negative' | 'neutral';
type Accent = 'emerald' | 'cyan' | 'orange' | 'violet' | 'rose';
type ChartPoint = { x: number; y: number };

const props = withDefaults(
    defineProps<{
        animationKey: string;
        accent?: Accent;
        actionHref?: string;
        actionLabel?: string;
        change: number | null;
        comparisonAvailable?: boolean;
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
        accent: 'emerald',
        comparisonAvailable: true,
        comparisonLabel: 'Previous period',
        inverse: false,
        isLive: false,
    },
);

const chartWidth = 180;
const chartHeight = 48;
const chartPadding = 2;

const trendState = computed<TrendState>(() => {
    if (!props.comparisonAvailable || props.change === null) {
        return 'neutral';
    }

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

const accentClasses: Record<Accent, { chart: string; icon: string }> = {
    emerald: {
        chart: 'text-emerald-500 dark:text-emerald-400',
        icon: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    },
    cyan: {
        chart: 'text-cyan-500 dark:text-cyan-400',
        icon: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400',
    },
    orange: {
        chart: 'text-orange-500 dark:text-orange-400',
        icon: 'bg-orange-500/10 text-orange-600 dark:text-orange-400',
    },
    violet: {
        chart: 'text-violet-500 dark:text-violet-400',
        icon: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    },
    rose: {
        chart: 'text-rose-500 dark:text-rose-400',
        icon: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
    },
};

const chartClass = computed(() => accentClasses[props.accent].chart);
const iconClass = computed(() => accentClasses[props.accent].icon);

const trendIcon = computed(() => {
    if (!props.comparisonAvailable || props.change === null) {
        return Minus;
    }

    if (props.currentValue > props.previousValue) {
        return ArrowUpRight;
    }

    if (props.currentValue < props.previousValue) {
        return ArrowDownRight;
    }

    return Minus;
});

const trendLabel = computed(() => {
    if (!props.comparisonAvailable) {
        return 'Not enough prior data';
    }

    if (props.change === null) {
        return props.currentValue > 0
            ? `Started from ${props.previousValueLabel}`
            : 'No change';
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
    <Card
        class="flex min-h-52 flex-col gap-0 overflow-hidden rounded-2xl border-border/80 bg-card p-4 shadow-xs sm:p-5"
    >
        <div class="flex items-center gap-2.5">
            <span
                :class="[
                    'flex size-9 shrink-0 items-center justify-center rounded-full',
                    iconClass,
                ]"
                aria-hidden="true"
            >
                <component :is="icon" class="size-4.5" />
            </span>
            <Tooltip>
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="cursor-help rounded-md text-left text-xs font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                        :aria-label="`${label}: ${value}. More information`"
                    >
                        {{ label }}
                    </button>
                </TooltipTrigger>
                <TooltipContent class="max-w-80 !text-wrap" side="top">
                    {{ detail }}
                </TooltipContent>
            </Tooltip>
        </div>

        <div :key="`${animationKey}-${value}`" class="data-arrive mt-4">
            <p
                class="text-3xl font-semibold tracking-[-0.045em] tabular-nums sm:text-[2rem]"
            >
                {{ value }}
            </p>

            <div class="mt-2 flex min-h-9 flex-col items-start gap-0.5">
                <span
                    :class="[
                        'inline-flex items-center gap-1 text-xs font-semibold tabular-nums',
                        stateClass,
                    ]"
                >
                    <component
                        :is="trendIcon"
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    {{ trendLabel }}
                </span>
                <span class="text-[11px] text-muted-foreground tabular-nums">
                    <template v-if="comparisonAvailable">
                        vs previous · {{ previousValueLabel }}
                    </template>
                    <template v-else>
                        Collecting a previous matching period
                    </template>
                </span>
                <Link
                    v-if="actionHref"
                    :href="actionHref"
                    class="mt-1 inline-flex items-center gap-1 text-[11px] font-medium text-primary hover:underline"
                >
                    {{ actionLabel }}
                    <ArrowRight class="size-3" aria-hidden="true" />
                </Link>
            </div>
        </div>

        <div class="mt-auto pt-3">
            <svg
                class="h-12 w-full overflow-visible"
                :class="chartClass"
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
