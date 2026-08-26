<script setup lang="ts">
import {
    FileQuestion,
    Globe2,
    Laptop,
    Monitor,
    MousePointerClick,
    Smartphone,
    Tablet,
    Tag,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import BrandLogo from '@/components/dashboard/BrandLogo.vue';
import { Card } from '@/components/ui/card';

type BreakdownKind =
    'source' | 'campaign' | 'ai' | 'country' | 'device' | 'browser' | 'os';

type BreakdownItem = { label: string; value: number };
type BreakdownTab = {
    id: string;
    label: string;
    description: string;
    emptyMessage: string;
    items: BreakdownItem[];
    kind: BreakdownKind;
    total: number;
};

const props = defineProps<{
    id: string;
    title: string;
    description: string;
    icon: Component;
    tabs: BreakdownTab[];
}>();

const activeTabId = ref(props.tabs[0]?.id ?? '');
const activeTabIndex = computed(() =>
    Math.max(
        0,
        props.tabs.findIndex((tab) => tab.id === activeTabId.value),
    ),
);
const activeTab = computed(
    () => props.tabs[activeTabIndex.value] ?? props.tabs[0],
);
const countryDisplayNames = new Intl.DisplayNames(undefined, {
    type: 'region',
});

function percentageOf(value: number, total: number): number {
    if (total <= 0) {
        return 0;
    }

    return Math.max(4, Math.min(100, (value / total) * 100));
}

function formatNumber(value: number): string {
    return new Intl.NumberFormat().format(value);
}

function countryFlag(countryCode: string): string | null {
    const normalizedCode = countryCode.trim().toUpperCase();

    if (!/^[A-Z]{2}$/.test(normalizedCode)) {
        return null;
    }

    return String.fromCodePoint(
        ...[...normalizedCode].map(
            (character) => character.charCodeAt(0) + 127397,
        ),
    );
}

function countryName(countryCode: string): string {
    return countryFlag(countryCode) === null
        ? countryCode
        : (countryDisplayNames.of(countryCode) ?? countryCode);
}

function deviceIcon(label: string): Component {
    const normalizedLabel = label.trim().toLowerCase();

    if (normalizedLabel === 'mobile') {
        return Smartphone;
    }

    if (normalizedLabel === 'tablet') {
        return Tablet;
    }

    return Laptop;
}

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
    const nextButton = buttons[nextIndex];

    nextButton.focus();
    nextButton.click();
}
</script>

<template>
    <Card class="gap-0 overflow-hidden p-1">
        <div
            class="flex h-full flex-col rounded-xl bg-background/70 p-4 sm:p-5"
        >
            <div class="flex items-start gap-3">
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-card text-muted-foreground"
                    aria-hidden="true"
                >
                    <component :is="icon" class="size-4" />
                </span>
                <div class="min-w-0">
                    <h2 class="font-medium">{{ title }}</h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ description }}
                    </p>
                </div>
            </div>

            <div
                class="relative mt-4 grid rounded-md border border-border bg-card/70 p-1"
                :style="{
                    gridTemplateColumns: `repeat(${tabs.length}, minmax(0, 1fr))`,
                }"
                role="tablist"
                :aria-label="`${title} views`"
            >
                <span
                    class="pointer-events-none absolute inset-y-1 left-1 rounded-[calc(var(--radius-md)-2px)] bg-accent shadow-[0_1px_2px_rgba(0,0,0,0.08)] transition-transform duration-200 ease-[cubic-bezier(0.2,0,0,1)] motion-reduce:transition-none"
                    :style="{
                        width: `calc((100% - 0.5rem) / ${tabs.length})`,
                        transform: `translateX(${activeTabIndex * 100}%)`,
                    }"
                    aria-hidden="true"
                />
                <button
                    v-for="tab in tabs"
                    :id="`${id}-${tab.id}-tab`"
                    :key="tab.id"
                    type="button"
                    role="tab"
                    class="relative z-10 h-8 min-w-0 cursor-pointer truncate rounded-md px-2 text-xs font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                    :class="
                        tab.id === activeTabId
                            ? 'text-foreground'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-selected="tab.id === activeTabId"
                    :aria-controls="`${id}-${tab.id}-panel`"
                    :tabindex="tab.id === activeTabId ? 0 : -1"
                    @click="activeTabId = tab.id"
                    @keydown.left.prevent="activateAdjacentTab($event, -1)"
                    @keydown.right.prevent="activateAdjacentTab($event, 1)"
                >
                    {{ tab.label }}
                </button>
            </div>

            <Transition name="breakdown-panel" mode="out-in">
                <div
                    v-if="activeTab"
                    :id="`${id}-${activeTab.id}-panel`"
                    :key="activeTab.id"
                    class="mt-3 flex h-56 flex-col overflow-hidden rounded-xl border border-border/80 bg-card/55 py-2"
                    role="tabpanel"
                    :aria-labelledby="`${id}-${activeTab.id}-tab`"
                    tabindex="0"
                >
                    <div
                        class="flex items-center justify-between gap-3 px-3 pb-2"
                    >
                        <p class="truncate text-xs text-muted-foreground">
                            {{ activeTab.description }}
                        </p>
                        <div class="flex shrink-0 items-center gap-2">
                            <a
                                v-if="activeTab.kind === 'country'"
                                href="https://db-ip.com"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-[10px] text-muted-foreground underline decoration-border underline-offset-2 transition-colors hover:text-foreground focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                IP geolocation by DB-IP
                            </a>
                            <span
                                class="font-mono text-[10px] text-muted-foreground tabular-nums"
                            >
                                {{ formatNumber(activeTab.total) }} total
                            </span>
                        </div>
                    </div>

                    <div
                        v-for="item in activeTab.items.slice(0, 5)"
                        :key="item.label"
                        class="group/row relative flex min-h-9 items-center gap-2.5 overflow-hidden px-3 text-sm transition-colors duration-100 hover:bg-accent/70"
                    >
                        <span
                            class="absolute inset-y-0 left-0 bg-foreground/[0.035] transition-[width] duration-200 ease-out motion-reduce:transition-none"
                            :style="{
                                width: `${percentageOf(item.value, activeTab.total)}%`,
                            }"
                            aria-hidden="true"
                        />
                        <span
                            class="relative flex size-5 shrink-0 items-center justify-center transition-transform duration-200 group-hover/row:translate-x-0.5 motion-reduce:transform-none"
                        >
                            <BrandLogo
                                v-if="
                                    activeTab.kind === 'ai' ||
                                    activeTab.kind === 'browser'
                                "
                                :name="item.label"
                            />
                            <Monitor
                                v-else-if="activeTab.kind === 'os'"
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span
                                v-else-if="activeTab.kind === 'country'"
                                class="text-sm leading-none"
                                aria-hidden="true"
                            >
                                {{ countryFlag(item.label) ?? '◌' }}
                            </span>
                            <component
                                :is="deviceIcon(item.label)"
                                v-else-if="activeTab.kind === 'device'"
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <Tag
                                v-else-if="activeTab.kind === 'campaign'"
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <MousePointerClick
                                v-else-if="item.label === 'Direct'"
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <Globe2
                                v-else
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </span>
                        <span class="relative min-w-0 flex-1 truncate">
                            {{
                                activeTab.kind === 'country'
                                    ? countryName(item.label)
                                    : item.label
                            }}
                        </span>
                        <span
                            class="relative shrink-0 font-mono text-xs text-muted-foreground tabular-nums"
                        >
                            {{ formatNumber(item.value) }}
                        </span>
                    </div>

                    <div
                        v-if="activeTab.items.length === 0"
                        class="flex flex-1 flex-col items-center justify-center gap-2 px-5 text-center"
                        role="status"
                    >
                        <FileQuestion
                            class="size-5 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ activeTab.emptyMessage }}
                        </p>
                    </div>
                </div>
            </Transition>
        </div>
    </Card>
</template>

<style scoped>
.breakdown-panel-enter-active {
    transition:
        opacity 160ms ease-out,
        transform 160ms cubic-bezier(0.2, 0, 0, 1),
        filter 160ms ease-out;
}

.breakdown-panel-leave-active {
    transition:
        opacity 100ms ease-in,
        transform 100ms ease-in;
}

.breakdown-panel-enter-from {
    opacity: 0;
    filter: blur(3px);
    transform: translateY(2px);
}

.breakdown-panel-leave-to {
    opacity: 0;
    transform: translateY(-2px);
}
</style>
