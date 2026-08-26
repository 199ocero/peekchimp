<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Bot, FileText } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { dashboard } from '@/routes';

defineProps<{
    project: { id: number; name: string; timezone: string };
    range: { label: string };
    traffic: {
        visitors: number;
        visits: number;
        pageviews: number;
        conversions: number;
        previousVisits: number;
        change: number | null;
        sources: Array<{ label: string; value: number }>;
        pages: Array<{ label: string; value: number }>;
    };
}>();

function trendLabel(change: number | null): string {
    if (change === null) {
        return 'New in this period';
    }

    return `${change >= 0 ? '+' : ''}${change}% vs previous period`;
}
</script>

<template>
    <Head :title="`${project.name} AI traffic`" />
    <div
        class="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 pt-6 pb-20 sm:px-6"
    >
        <header class="flex items-end justify-between gap-4">
            <div>
                <p
                    class="text-xs tracking-[0.14em] text-muted-foreground uppercase"
                >
                    AI traffic
                </p>
                <h1 class="mt-2 text-2xl font-medium tracking-tight">
                    Visitors referred by AI products
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ range.label }} · Based only on identifiable referrers and
                    UTM sources.
                </p>
            </div>
            <Button as-child variant="outline"
                ><Link :href="dashboard()">Back to dashboard</Link></Button
            >
        </header>
        <p class="-mt-3 text-sm text-muted-foreground">
            {{ trendLabel(traffic.change) }}
        </p>
        <div class="grid gap-3 sm:grid-cols-4">
            <Card class="p-4"
                ><p class="text-xs text-muted-foreground">Visitors</p>
                <p class="mt-2 text-2xl font-medium">
                    {{ traffic.visitors }}
                </p></Card
            ><Card class="p-4"
                ><p class="text-xs text-muted-foreground">Visits</p>
                <p class="mt-2 text-2xl font-medium">
                    {{ traffic.visits }}
                </p></Card
            ><Card class="p-4"
                ><p class="text-xs text-muted-foreground">Page views</p>
                <p class="mt-2 text-2xl font-medium">
                    {{ traffic.pageviews }}
                </p></Card
            ><Card class="p-4"
                ><p class="text-xs text-muted-foreground">Conversions</p>
                <p class="mt-2 text-2xl font-medium">
                    {{ traffic.conversions }}
                </p></Card
            >
        </div>
        <div class="grid gap-3 md:grid-cols-2">
            <Card class="p-5"
                ><div class="flex items-center gap-3">
                    <Bot class="size-4 text-muted-foreground" />
                    <div>
                        <h2 class="font-medium">AI sources</h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Identifiable assistant referrals
                        </p>
                    </div>
                </div>
                <div class="mt-4 space-y-3">
                    <div
                        v-for="source in traffic.sources"
                        :key="source.label"
                        class="flex justify-between text-sm"
                    >
                        <span>{{ source.label }}</span
                        ><span
                            class="font-mono text-xs text-muted-foreground"
                            >{{ source.value }}</span
                        >
                    </div>
                    <p
                        v-if="!traffic.sources.length"
                        class="text-sm text-muted-foreground"
                    >
                        No identifiable AI referrals in this period.
                    </p>
                </div></Card
            ><Card class="p-5"
                ><div class="flex items-center gap-3">
                    <FileText class="size-4 text-muted-foreground" />
                    <div>
                        <h2 class="font-medium">Top pages</h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Pages receiving AI-referred views
                        </p>
                    </div>
                </div>
                <div class="mt-4 space-y-3">
                    <div
                        v-for="page in traffic.pages"
                        :key="page.label"
                        class="flex justify-between text-sm"
                    >
                        <span class="truncate">{{ page.label }}</span
                        ><span
                            class="font-mono text-xs text-muted-foreground"
                            >{{ page.value }}</span
                        >
                    </div>
                    <p
                        v-if="!traffic.pages.length"
                        class="text-sm text-muted-foreground"
                    >
                        No AI-referred page views in this period.
                    </p>
                </div></Card
            >
        </div>
    </div>
</template>
