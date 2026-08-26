<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Bot, RefreshCw } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { scan as queueScan } from '@/routes/websites/ai-visibility';

const props = defineProps<{
    project: { id: number; name: string };
    scan: {
        status: string;
        score: number | null;
        findings: Record<
            string,
            {
                available?: boolean;
                title?: boolean;
                description?: boolean;
                structuredData?: boolean;
            }
        > | null;
        error: string | null;
    } | null;
}>();
function runScan(): void {
    router.post(queueScan(props.project.id).url, {}, { preserveScroll: true });
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
                    Can machines discover this site?
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    A separate technical check for crawler access and
                    machine-readable metadata. It does not claim what an AI
                    model knows.
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
                            {{ props.scan?.status || 'Not scanned yet' }}
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
            <div
                v-if="props.scan?.findings"
                class="mt-5 grid gap-3 sm:grid-cols-2"
            >
                <div
                    v-for="(finding, key) in props.scan.findings"
                    :key="key"
                    class="rounded-md border border-border p-3 text-sm"
                >
                    <div class="flex justify-between">
                        <span class="capitalize">{{ String(key) }}</span
                        ><span>{{
                            finding.available ? 'Found' : 'Needs attention'
                        }}</span>
                    </div>
                    <p
                        v-if="key === 'homepage'"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        Title: {{ finding.title ? 'yes' : 'no' }} · Description:
                        {{ finding.description ? 'yes' : 'no' }} · Structured
                        data: {{ finding.structuredData ? 'yes' : 'no' }}
                    </p>
                </div>
            </div></Card
        >
    </div>
</template>
