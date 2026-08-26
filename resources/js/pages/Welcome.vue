<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    ChartNoAxesCombined,
    Monitor,
    MousePointerClick,
    Moon,
    ShieldCheck,
    Sun,
} from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import DashboardTrafficChart from '@/components/dashboard/DashboardTrafficChart.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/composables/useAppearance';
import { dashboard, home, login, register } from '@/routes';

const page = usePage();
const isScrolled = ref(false);
const { appearance, updateAppearance } = useAppearance();

const appearanceOptions = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;

const sampleRange = {
    key: 'landing-sample',
    label: 'Last 7 days · Sample data',
    interval: 'day' as const,
};
const sampleMetrics = { pageviews: 3492, visitors: 1284 };
const sampleTimeseries = [
    { label: 'Mon', pageviews: 420, visitors: 158 },
    { label: 'Tue', pageviews: 472, visitors: 170 },
    { label: 'Wed', pageviews: 451, visitors: 162 },
    { label: 'Thu', pageviews: 505, visitors: 184 },
    { label: 'Fri', pageviews: 486, visitors: 176 },
    { label: 'Sat', pageviews: 612, visitors: 230 },
    { label: 'Sun', pageviews: 546, visitors: 204 },
];

function updateScrolledState(): void {
    isScrolled.value = window.scrollY > 8;
}

onMounted(() => {
    updateScrolledState();
    window.addEventListener('scroll', updateScrolledState, { passive: true });
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', updateScrolledState);
});
</script>

<template>
    <Head title="Simple, private website analytics" />

    <div class="min-h-svh overflow-x-clip bg-background text-foreground">
        <header
            :data-scrolled="isScrolled"
            class="landing-header sticky top-0 z-50 mx-auto h-20 w-full"
        >
            <div
                class="landing-nav mx-auto flex h-16 w-full items-center justify-between rounded-full border border-transparent px-5 sm:px-6"
            >
                <Link
                    :href="page.props.auth.user ? dashboard() : home()"
                    class="flex items-center gap-2.5 rounded-full pr-2 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    aria-label="Peekchimp home"
                >
                    <span
                        class="flex size-9 items-center justify-center overflow-hidden rounded-full bg-secondary"
                    >
                        <AppLogoIcon class="size-8" />
                    </span>
                    <span class="text-sm font-medium tracking-[-0.03em]"
                        >Peekchimp</span
                    >
                </Link>

                <nav class="flex items-center gap-1.5" aria-label="Primary">
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="rounded-full"
                                aria-label="Change appearance"
                            >
                                <Sun
                                    v-if="appearance === 'light'"
                                    class="size-4"
                                />
                                <Moon
                                    v-else-if="appearance === 'dark'"
                                    class="size-4"
                                />
                                <Monitor v-else class="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-36">
                            <DropdownMenuRadioGroup :model-value="appearance">
                                <DropdownMenuRadioItem
                                    v-for="option in appearanceOptions"
                                    :key="option.value"
                                    :value="option.value"
                                    class="cursor-pointer"
                                    @select="updateAppearance(option.value)"
                                >
                                    <component
                                        :is="option.Icon"
                                        class="size-4"
                                    />
                                    {{ option.label }}
                                </DropdownMenuRadioItem>
                            </DropdownMenuRadioGroup>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <Button
                        v-if="page.props.auth.user"
                        as-child
                        size="sm"
                        class="rounded-full"
                    >
                        <Link :href="dashboard()">Open dashboard</Link>
                    </Button>
                    <template v-else>
                        <Button
                            as-child
                            variant="ghost"
                            size="sm"
                            class="rounded-full"
                        >
                            <Link :href="login()">Log in</Link>
                        </Button>
                        <Button
                            v-if="page.props.canRegister"
                            as-child
                            size="sm"
                            class="rounded-full"
                        >
                            <Link :href="register()">Start free</Link>
                        </Button>
                    </template>
                </nav>
            </div>
        </header>

        <main
            class="mx-auto flex w-full max-w-6xl flex-col gap-14 px-5 pb-16 sm:gap-20 sm:px-6 sm:pb-24"
        >
            <section
                class="grid items-center gap-10 pt-8 sm:pt-14 lg:grid-cols-[1fr_0.9fr] lg:gap-16 lg:pt-20"
            >
                <div>
                    <p
                        class="inline-flex items-center gap-2 rounded-full border border-border bg-card px-3 py-1.5 text-xs text-muted-foreground shadow-[0_1px_2px_rgb(0_0_0/0.12)]"
                    >
                        <span class="size-1.5 rounded-full bg-success" />
                        Clear, privacy-friendly website analytics
                    </p>
                    <h1
                        class="mt-6 max-w-3xl text-5xl leading-[0.96] font-medium tracking-[-0.06em] text-balance sm:text-6xl lg:text-7xl"
                    >
                        Know what brings people to your site.
                        <span class="text-muted-foreground"
                            >Without watching who they are.</span
                        >
                    </h1>
                    <p
                        class="mt-6 max-w-xl text-base leading-7 text-pretty text-muted-foreground sm:text-lg sm:leading-8"
                    >
                        See where visits come from, which pages get read, and
                        how traffic changes over time. No cookies, personal
                        profiles, or analytics maze.
                    </p>
                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        <Button as-child size="lg" class="rounded-full">
                            <Link
                                v-if="page.props.auth.user"
                                :href="dashboard()"
                            >
                                Open your dashboard
                                <ArrowRight class="size-4" />
                            </Link>
                            <Link
                                v-else
                                :href="
                                    page.props.canRegister
                                        ? register()
                                        : login()
                                "
                            >
                                Add your website
                                <ArrowRight class="size-4" />
                            </Link>
                        </Button>
                        <Button
                            as-child
                            variant="ghost"
                            size="lg"
                            class="rounded-full"
                        >
                            <a href="#how-it-works">See how it works</a>
                        </Button>
                    </div>
                </div>

                <div class="min-w-0">
                    <DashboardTrafficChart
                        :range="sampleRange"
                        :metrics="sampleMetrics"
                        :timeseries="sampleTimeseries"
                    />
                </div>
            </section>

            <section
                id="how-it-works"
                class="scroll-mt-24 rounded-[2rem] border border-border bg-card p-6 shadow-[0_1px_2px_rgb(0_0_0/0.12)] sm:p-10"
            >
                <div class="max-w-xl">
                    <p
                        class="text-xs tracking-[0.12em] text-muted-foreground uppercase"
                    >
                        How it helps
                    </p>
                    <h2
                        class="mt-3 text-3xl leading-tight font-medium tracking-[-0.05em] text-balance sm:text-4xl"
                    >
                        Three useful answers. One quiet dashboard.
                    </h2>
                    <p class="mt-3 text-sm leading-6 text-muted-foreground">
                        Peekchimp keeps the report focused on decisions you can
                        actually make about your website.
                    </p>
                </div>

                <div class="mt-8 grid gap-3 md:grid-cols-3">
                    <article
                        v-for="item in [
                            {
                                icon: MousePointerClick,
                                number: '01',
                                title: 'Add one small script',
                                text: 'Connect your site in a few minutes and start collecting only the essentials.',
                            },
                            {
                                icon: ChartNoAxesCombined,
                                number: '02',
                                title: 'Read the whole story',
                                text: 'See traffic changes, popular pages, and visit sources in the same view.',
                            },
                            {
                                icon: ShieldCheck,
                                number: '03',
                                title: 'Decide what to improve',
                                text: 'Use clear trends to improve content and campaigns without profiling visitors.',
                            },
                        ]"
                        :key="item.number"
                        class="rounded-[1.5rem] bg-background p-5"
                    >
                        <div class="flex items-center justify-between">
                            <component
                                :is="item.icon"
                                class="size-5 text-primary"
                                aria-hidden="true"
                            />
                            <span
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ item.number }}
                            </span>
                        </div>
                        <h3 class="mt-8 text-sm font-medium tracking-[-0.02em]">
                            {{ item.title }}
                        </h3>
                        <p class="mt-2 text-sm leading-6 text-muted-foreground">
                            {{ item.text }}
                        </p>
                    </article>
                </div>
            </section>
        </main>

        <footer
            class="mx-auto flex w-full max-w-6xl flex-col gap-2 px-5 pb-8 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6"
        >
            <span>Useful website analytics without visitor profiles.</span>
            <span>Built with Laravel and Vue.</span>
        </footer>
    </div>
</template>

<style scoped>
.landing-nav {
    max-width: min(100%, 76rem);
    transition:
        max-width 700ms cubic-bezier(0.32, 0.72, 0, 1),
        height 700ms cubic-bezier(0.32, 0.72, 0, 1),
        margin 700ms cubic-bezier(0.32, 0.72, 0, 1),
        padding 700ms cubic-bezier(0.32, 0.72, 0, 1),
        background-color 700ms cubic-bezier(0.32, 0.72, 0, 1),
        border-color 700ms cubic-bezier(0.32, 0.72, 0, 1),
        box-shadow 700ms cubic-bezier(0.32, 0.72, 0, 1);
}

.landing-header[data-scrolled='true'] .landing-nav {
    height: 3rem;
    max-width: calc(100% - 1.5rem);
    margin-top: 0.75rem;
    padding-inline: 0.75rem;
    border-color: color-mix(in srgb, var(--foreground) 16%, transparent);
    background-color: color-mix(in srgb, var(--background) 84%, transparent);
    box-shadow:
        inset 0 1px 0 rgb(255 255 255 / 6%),
        inset 0 -1px 0 rgb(0 0 0 / 14%),
        0 1px 1px rgb(0 0 0 / 12%),
        0 8px 24px rgb(0 0 0 / 16%);
    backdrop-filter: blur(24px) saturate(1.25);
}

@media (min-width: 640px) {
    .landing-header[data-scrolled='true'] .landing-nav {
        max-width: 42rem;
    }
}

@media (prefers-reduced-motion: reduce) {
    .landing-nav {
        transition: none;
    }
}
</style>
