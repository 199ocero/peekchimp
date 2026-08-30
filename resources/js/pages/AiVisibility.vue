<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { Bot, FileSearch, RefreshCw } from '@lucide/vue';
import { watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { store as queueScan } from '@/routes/websites/crawl';

const props = defineProps<{
    project: { id: number; name: string };
    scan: {
        status: string;
        score: number | null;
        findings: {
            robots?: { available?: boolean; status?: number | null };
            sitemap?: { available?: boolean; status?: number | null };
            homepage?: { available?: boolean; status?: number | null };
            llmsTxt?: { available?: boolean; status?: number | null };
            discoveredPages?: number;
            crawl?: {
                pagesDiscovered: number;
                pagesCrawled: number;
                pagesSuccessful: number;
                pagesFailed: number;
            };
            pages?: Array<{
                path: string;
                status: number | null;
                title: boolean;
                description: boolean;
                structuredData: boolean;
                error: string | null;
            }>;
        } | null;
        error: string | null;
        startedAt: string | null;
        completedAt: string | null;
    } | null;
}>();

const { start, stop } = usePoll(3000, { only: ['scan'] }, { autoStart: false });

watch(
    () => props.scan?.status,
    (status) => {
        if (status === 'queued' || status === 'running') {
            start();
        } else {
            stop();
        }
    },
    { immediate: true },
);

function runScan(): void {
    router.post(queueScan(props.project.id).url, {}, { preserveScroll: true });
}

function formatTimestamp(value: string | null | undefined): string {
    if (!value) {
        return 'Not completed yet';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
</script>

<template>
    <Head :title="`${project.name} AI visibility`" />
    <div
        class="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 pt-6 pb-20 sm:px-6"
    >
        <header class="flex items-end justify-between gap-4">
            <div>
                <p
                    class="text-xs tracking-[0.14em] text-muted-foreground uppercase"
                >
                    AI visibility
                </p>
                <h1 class="mt-2 text-2xl font-medium tracking-tight">
                    Website content scan
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Safely captures public page content and technical signals so
                    your read-only growth consultant can make specific,
                    evidence-backed recommendations.
                </p>
            </div>
            <Button as-child variant="outline"
                ><Link :href="dashboard()">Back to dashboard</Link></Button
            >
        </header>
        <Card class="p-5"
            ><div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <Bot class="size-5 text-muted-foreground" />
                    <div>
                        <h2 class="font-medium">Latest scan</h2>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ props.scan?.status || 'Not scanned yet' }} ·
                            {{ formatTimestamp(props.scan?.completedAt) }}
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span
                        v-if="
                            props.scan?.score !== null &&
                            props.scan?.score !== undefined
                        "
                        class="font-mono text-2xl"
                        >{{ props.scan.score }}/100</span
                    ><Button
                        :disabled="
                            props.scan?.status === 'running' ||
                            props.scan?.status === 'queued'
                        "
                        @click="runScan"
                        ><RefreshCw class="mr-2 size-4" />Run scan</Button
                    >
                </div>
            </div>
            <p v-if="props.scan?.error" class="mt-4 text-sm text-destructive">
                {{ props.scan.error }}
            </p>
            <div v-if="props.scan?.findings" class="mt-5 space-y-5">
                <div
                    v-if="props.scan.findings.crawl"
                    class="grid gap-3 sm:grid-cols-4"
                >
                    <div
                        v-for="metric in [
                            [
                                'Discovered',
                                props.scan.findings.crawl.pagesDiscovered,
                            ],
                            ['Crawled', props.scan.findings.crawl.pagesCrawled],
                            [
                                'Successful',
                                props.scan.findings.crawl.pagesSuccessful,
                            ],
                            ['Failed', props.scan.findings.crawl.pagesFailed],
                        ]"
                        :key="String(metric[0])"
                        class="rounded-md border border-border p-3"
                    >
                        <p class="text-xs text-muted-foreground">
                            {{ metric[0] }}
                        </p>
                        <p class="mt-1 font-mono text-xl">{{ metric[1] }}</p>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="(finding, key) in {
                            robots: props.scan.findings.robots,
                            sitemap: props.scan.findings.sitemap,
                            homepage: props.scan.findings.homepage,
                            llmsTxt: props.scan.findings.llmsTxt,
                        }"
                        :key="key"
                        class="rounded-md border border-border p-3 text-sm"
                    >
                        <div class="flex justify-between">
                            <span class="capitalize">{{ String(key) }}</span
                            ><span>{{
                                finding?.available ? 'Found' : 'Needs attention'
                            }}</span>
                        </div>
                        <p class="mt-2 text-xs text-muted-foreground">
                            HTTP {{ finding?.status ?? 'unavailable' }}
                        </p>
                    </div>
                </div>

                <div v-if="props.scan.findings.pages?.length">
                    <div class="mb-3 flex items-center gap-2">
                        <FileSearch class="size-4 text-muted-foreground" />
                        <h3 class="text-sm font-medium">Captured pages</h3>
                    </div>
                    <div
                        class="overflow-x-auto rounded-md border border-border"
                    >
                        <table class="w-full text-left text-sm">
                            <thead
                                class="bg-muted/40 text-xs text-muted-foreground"
                            >
                                <tr>
                                    <th class="px-3 py-2 font-medium">Page</th>
                                    <th class="px-3 py-2 font-medium">
                                        Status
                                    </th>
                                    <th class="px-3 py-2 font-medium">Title</th>
                                    <th class="px-3 py-2 font-medium">Meta</th>
                                    <th class="px-3 py-2 font-medium">
                                        Schema
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="pageFinding in props.scan.findings.pages.slice(
                                        0,
                                        20,
                                    )"
                                    :key="pageFinding.path"
                                    class="border-t border-border"
                                >
                                    <td
                                        class="max-w-xs truncate px-3 py-2 font-mono text-xs"
                                    >
                                        {{ pageFinding.path }}
                                    </td>
                                    <td class="px-3 py-2">
                                        {{ pageFinding.status ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        {{ pageFinding.title ? 'Yes' : 'No' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        {{
                                            pageFinding.description
                                                ? 'Yes'
                                                : 'No'
                                        }}
                                    </td>
                                    <td class="px-3 py-2">
                                        {{
                                            pageFinding.structuredData
                                                ? 'Yes'
                                                : 'No'
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div></Card
        >
    </div>
</template>
