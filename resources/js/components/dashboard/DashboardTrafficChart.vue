<script setup lang="ts">
import { Activity, ChartNoAxesCombined } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Card } from '@/components/ui/card';

type SeriesPoint = { label: string; pageviews: number; visitors: number };
type ChartPoint = SeriesPoint & {
    x: number;
    pageviewsY: number;
    visitorsY: number;
};

const props = withDefaults(
    defineProps<{
        embedded?: boolean;
        range: {
            key: string;
            label: string;
            interval: 'hour' | 'day';
        };
        metrics: {
            pageviews: number;
            visitors: number;
        };
        timeseries: SeriesPoint[];
    }>(),
    {
        embedded: false,
    },
);

const hoveredChartIndex = ref<number | null>(null);
const dimensions = {
    width: 960,
    height: 260,
    top: 18,
    right: 16,
    bottom: 22,
    left: 42,
};

const scale = computed(() =>
    Math.max(
        1,
        ...props.timeseries.flatMap((point) => [
            point.pageviews,
            point.visitors,
        ]),
    ),
);

const chartData = computed<ChartPoint[]>(() => {
    const plotWidth = dimensions.width - dimensions.left - dimensions.right;
    const plotHeight = dimensions.height - dimensions.top - dimensions.bottom;

    return props.timeseries.map((point, index) => {
        const x =
            props.timeseries.length <= 1
                ? dimensions.left + plotWidth / 2
                : dimensions.left +
                  (index / (props.timeseries.length - 1)) * plotWidth;

        return {
            ...point,
            x,
            pageviewsY:
                dimensions.height -
                dimensions.bottom -
                (point.pageviews / scale.value) * plotHeight,
            visitorsY:
                dimensions.height -
                dimensions.bottom -
                (point.visitors / scale.value) * plotHeight,
        };
    });
});

const pageviewPath = computed(() =>
    createSmoothPath(chartData.value, 'pageviewsY'),
);
const visitorPath = computed(() =>
    createSmoothPath(chartData.value, 'visitorsY'),
);
const areaPath = computed(() => {
    const points = chartData.value;

    if (points.length === 0) {
        return '';
    }

    const baseline = dimensions.height - dimensions.bottom;
    const firstPoint = points[0];
    const lastPoint = points[points.length - 1];

    if (points.length === 1) {
        return `M ${firstPoint.x - 18} ${firstPoint.pageviewsY} L ${firstPoint.x + 18} ${firstPoint.pageviewsY} L ${firstPoint.x + 18} ${baseline} L ${firstPoint.x - 18} ${baseline} Z`;
    }

    return `${pageviewPath.value} L ${lastPoint.x} ${baseline} L ${firstPoint.x} ${baseline} Z`;
});
const guides = computed(() => {
    const values = [scale.value, scale.value / 2, 0];
    const plotHeight = dimensions.height - dimensions.top - dimensions.bottom;

    return values.map((value) => ({
        label: formatNumber(Math.round(value)),
        y: dimensions.top + plotHeight - (value / scale.value) * plotHeight,
    }));
});
const labels = computed(() => {
    const labelCount = Math.min(5, props.timeseries.length);

    if (labelCount <= 1) {
        return props.timeseries;
    }

    return Array.from(
        { length: labelCount },
        (_, index) =>
            props.timeseries[
                Math.round(
                    (index / (labelCount - 1)) * (props.timeseries.length - 1),
                )
            ],
    ).filter(
        (point, index, points) =>
            points.findIndex((candidate) => candidate.label === point.label) ===
            index,
    );
});
const hoveredPoint = computed(() =>
    hoveredChartIndex.value === null
        ? null
        : (chartData.value[hoveredChartIndex.value] ?? null),
);
const tooltipStyle = computed(() => {
    if (hoveredPoint.value === null) {
        return {};
    }

    return {
        left: `${Math.min(86, Math.max(14, (hoveredPoint.value.x / dimensions.width) * 100))}%`,
        top: `${Math.max(8, (hoveredPoint.value.pageviewsY / dimensions.height) * 100)}%`,
        transform:
            hoveredPoint.value.pageviewsY < 72
                ? 'translate(-50%, 10px)'
                : 'translate(-50%, calc(-100% - 10px))',
    };
});

function createSmoothPath(
    points: ChartPoint[],
    yKey: 'pageviewsY' | 'visitorsY',
): string {
    if (points.length === 0) {
        return '';
    }

    if (points.length === 1) {
        return `M ${points[0].x - 18} ${points[0][yKey]} L ${points[0].x + 18} ${points[0][yKey]}`;
    }

    return points.reduce((path, point, index) => {
        if (index === 0) {
            return `M ${point.x} ${point[yKey]}`;
        }

        const previousPoint = points[index - 1];
        const controlX = (previousPoint.x + point.x) / 2;

        return `${path} C ${controlX} ${previousPoint[yKey]}, ${controlX} ${point[yKey]}, ${point.x} ${point[yKey]}`;
    }, '');
}

function handleHover(event: MouseEvent): void {
    if (chartData.value.length === 0) {
        return;
    }

    const bounds = (
        event.currentTarget as SVGSVGElement
    ).getBoundingClientRect();
    const relativeX = Math.max(
        0,
        Math.min(bounds.width, event.clientX - bounds.left),
    );
    const chartX = (relativeX / bounds.width) * dimensions.width;

    hoveredChartIndex.value = chartData.value.reduce(
        (closestIndex, point, index) =>
            Math.abs(point.x - chartX) <
            Math.abs(chartData.value[closestIndex].x - chartX)
                ? index
                : closestIndex,
        0,
    );
}

function formatNumber(value: number): string {
    return new Intl.NumberFormat().format(value);
}
</script>

<template>
    <component
        :is="embedded ? 'div' : Card"
        :class="embedded ? 'min-w-0' : 'gap-0 overflow-hidden p-1'"
    >
        <div
            :class="
                embedded ? 'min-w-0' : 'rounded-xl bg-background/70 p-4 sm:p-5'
            "
        >
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="flex items-start gap-3">
                    <span
                        class="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-card text-muted-foreground"
                        aria-hidden="true"
                    >
                        <ChartNoAxesCombined class="size-4" />
                    </span>
                    <div>
                        <h2 class="font-medium">Traffic over time</h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Views and estimated visitors · {{ range.label }} ·
                            {{ range.interval === 'hour' ? 'Hourly' : 'Daily' }}
                        </p>
                    </div>
                </div>
                <div
                    class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs"
                    aria-label="Chart legend"
                >
                    <span class="flex items-center gap-2">
                        <i
                            class="h-0.5 w-5 rounded-full bg-primary"
                            aria-hidden="true"
                        />
                        <span class="text-muted-foreground">Views</span>
                        <span class="font-mono tabular-nums">{{
                            formatNumber(metrics.pageviews)
                        }}</span>
                    </span>
                    <span class="flex items-center gap-2">
                        <i
                            class="h-0.5 w-5 rounded-full bg-muted-foreground/65"
                            aria-hidden="true"
                        />
                        <span class="text-muted-foreground">Visitors</span>
                        <span class="font-mono tabular-nums">{{
                            formatNumber(metrics.visitors)
                        }}</span>
                    </span>
                </div>
            </div>

            <div
                v-if="metrics.pageviews > 0"
                :key="range.key"
                class="data-arrive mt-5"
            >
                <div
                    class="relative overflow-hidden rounded-xl border border-border/80 bg-card/60 px-2 pt-3 sm:px-3"
                >
                    <span
                        v-for="guide in guides"
                        :key="guide.y"
                        class="pointer-events-none absolute left-2 z-10 -translate-y-1/2 font-mono text-[10px] text-muted-foreground sm:left-3"
                        :style="{
                            top: `${(guide.y / dimensions.height) * 100}%`,
                        }"
                        aria-hidden="true"
                    >
                        {{ guide.label }}
                    </span>
                    <svg
                        viewBox="0 0 960 260"
                        preserveAspectRatio="none"
                        class="h-64 w-full overflow-visible"
                        role="img"
                        aria-labelledby="traffic-chart-title traffic-chart-description"
                        @mousemove="handleHover"
                        @mouseleave="hoveredChartIndex = null"
                    >
                        <title id="traffic-chart-title">
                            Visitors and views over time
                        </title>
                        <desc id="traffic-chart-description">
                            {{ range.interval === 'hour' ? 'Hourly' : 'Daily' }}
                            views and estimated visitors for {{ range.label }}.
                        </desc>
                        <defs>
                            <linearGradient
                                id="traffic-fill"
                                x1="0"
                                x2="0"
                                y1="0"
                                y2="1"
                            >
                                <stop
                                    offset="0"
                                    stop-color="var(--primary)"
                                    stop-opacity="0.24"
                                />
                                <stop
                                    offset="0.55"
                                    stop-color="var(--primary)"
                                    stop-opacity="0.07"
                                />
                                <stop
                                    offset="1"
                                    stop-color="var(--primary)"
                                    stop-opacity="0"
                                />
                            </linearGradient>
                        </defs>
                        <g aria-hidden="true">
                            <line
                                v-for="guide in guides"
                                :key="guide.y"
                                :x1="dimensions.left"
                                :x2="dimensions.width - dimensions.right"
                                :y1="guide.y"
                                :y2="guide.y"
                                stroke="var(--border)"
                                stroke-width="1"
                                vector-effect="non-scaling-stroke"
                            />
                        </g>
                        <path
                            class="traffic-area"
                            :d="areaPath"
                            fill="url(#traffic-fill)"
                        />
                        <path
                            class="traffic-line traffic-line--secondary"
                            :d="visitorPath"
                            fill="none"
                            stroke="var(--muted-foreground)"
                            stroke-opacity="0.65"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            vector-effect="non-scaling-stroke"
                        />
                        <path
                            class="traffic-line"
                            :d="pageviewPath"
                            fill="none"
                            stroke="var(--primary)"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2.5"
                            vector-effect="non-scaling-stroke"
                        />
                        <g v-if="hoveredPoint" aria-hidden="true">
                            <line
                                :x1="hoveredPoint.x"
                                :x2="hoveredPoint.x"
                                :y1="dimensions.top"
                                :y2="dimensions.height - dimensions.bottom"
                                stroke="var(--foreground)"
                                stroke-opacity="0.18"
                                stroke-width="1"
                                vector-effect="non-scaling-stroke"
                            />
                            <circle
                                :cx="hoveredPoint.x"
                                :cy="hoveredPoint.pageviewsY"
                                r="4"
                                fill="var(--card)"
                                stroke="var(--primary)"
                                stroke-width="2.5"
                                vector-effect="non-scaling-stroke"
                            />
                            <circle
                                :cx="hoveredPoint.x"
                                :cy="hoveredPoint.visitorsY"
                                r="3.5"
                                fill="var(--card)"
                                stroke="var(--muted-foreground)"
                                stroke-width="2"
                                vector-effect="non-scaling-stroke"
                            />
                        </g>
                        <rect
                            x="0"
                            y="0"
                            width="960"
                            height="260"
                            fill="transparent"
                            class="cursor-crosshair"
                        />
                    </svg>
                    <Transition name="chart-tooltip">
                        <div
                            v-if="hoveredPoint"
                            class="pointer-events-none absolute z-20 min-w-40 rounded-md border border-border bg-popover px-3 py-2.5 text-xs text-popover-foreground shadow-[0_1px_2px_rgba(0,0,0,0.08),0_8px_24px_rgba(0,0,0,0.12)]"
                            :style="tooltipStyle"
                        >
                            <p class="mb-2 font-medium">
                                {{ hoveredPoint.label }}
                            </p>
                            <div
                                class="flex items-center justify-between gap-5"
                            >
                                <span class="text-muted-foreground">Views</span>
                                <span class="font-mono tabular-nums">{{
                                    formatNumber(hoveredPoint.pageviews)
                                }}</span>
                            </div>
                            <div
                                class="mt-1 flex items-center justify-between gap-5"
                            >
                                <span class="text-muted-foreground"
                                    >Visitors</span
                                >
                                <span class="font-mono tabular-nums">{{
                                    formatNumber(hoveredPoint.visitors)
                                }}</span>
                            </div>
                        </div>
                    </Transition>
                </div>
                <div
                    class="ml-[4.4%] flex justify-between gap-2 overflow-hidden pt-2 pr-[1.7%] font-mono text-[10px] text-muted-foreground"
                    aria-hidden="true"
                >
                    <span
                        v-for="point in labels"
                        :key="point.label"
                        class="truncate"
                        >{{ point.label }}</span
                    >
                </div>
                <table class="sr-only">
                    <caption>
                        Visitors and views over time
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Views</th>
                            <th scope="col">Visitors</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="point in timeseries" :key="point.label">
                            <th scope="row">{{ point.label }}</th>
                            <td>{{ point.pageviews }}</td>
                            <td>{{ point.visitors }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-else
                class="mt-5 flex min-h-64 flex-col items-center justify-center rounded-xl border border-border/80 bg-card/60 px-6 text-center"
                role="status"
            >
                <span
                    class="mb-3 flex size-10 items-center justify-center rounded-full bg-muted text-muted-foreground"
                    aria-hidden="true"
                >
                    <Activity class="size-5" />
                </span>
                <p class="font-medium">No visits in this period</p>
                <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                    Choose a wider date range or check again after your next
                    visitors arrive.
                </p>
            </div>
        </div>
    </component>
</template>

<style scoped>
.data-arrive {
    animation: data-arrive 160ms ease-out both;
}
.traffic-line {
    transform-box: fill-box;
    animation: traffic-line-arrive 320ms cubic-bezier(0.2, 0, 0, 1) both;
}
.traffic-line--secondary {
    animation-delay: 40ms;
}
.traffic-area {
    animation: traffic-area-arrive 360ms ease-out both;
}
.chart-tooltip-enter-active {
    transition:
        opacity 120ms ease-out,
        scale 120ms cubic-bezier(0.2, 0, 0, 1);
}
.chart-tooltip-leave-active {
    transition:
        opacity 80ms ease-in,
        scale 80ms ease-in;
}
.chart-tooltip-enter-from,
.chart-tooltip-leave-to {
    opacity: 0;
    scale: 0.97;
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
@keyframes traffic-line-arrive {
    from {
        opacity: 0.3;
        filter: blur(2px);
        transform: translateY(2px);
    }
    to {
        opacity: 1;
        filter: blur(0);
        transform: translateY(0);
    }
}
@keyframes traffic-area-arrive {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}
@media (prefers-reduced-motion: reduce) {
    .data-arrive,
    .traffic-line,
    .traffic-area {
        animation: none;
    }
}
</style>
