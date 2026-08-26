<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { store } from '@/routes/websites/actions';

const props = defineProps<{
    website: { id: number; name: string };
    actions: Array<{
        id: number;
        name: string;
        event_name: string;
        page_path: string | null;
        is_active: boolean;
    }>;
}>();

const form = useForm({ name: '', event_name: '', page_path: '/' });

function submit(): void {
    form.post(store(props.website.id).url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head :title="`${website.name} actions`" />
    <div
        class="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 pt-6 pb-20 sm:px-6"
    >
        <header class="flex items-end justify-between gap-4">
            <div>
                <p
                    class="text-xs tracking-[0.14em] text-muted-foreground uppercase"
                >
                    Important actions
                </p>
                <h1 class="mt-2 text-2xl font-medium tracking-tight">
                    Track the clicks that matter
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Choose an existing event and see its click-through rate.
                </p>
            </div>
            <Button as-child variant="outline"
                ><Link :href="dashboard()">Back to dashboard</Link></Button
            >
        </header>

        <Card class="p-5">
            <form class="grid gap-4 md:grid-cols-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="action-name">Name</Label
                    ><Input
                        id="action-name"
                        v-model="form.name"
                        placeholder="Get started"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="action-event">Event name</Label
                    ><Input
                        id="action-event"
                        v-model="form.event_name"
                        placeholder="get_started_clicked"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="action-path">Page path</Label
                    ><Input
                        id="action-path"
                        v-model="form.page_path"
                        placeholder="/"
                    />
                </div>
                <div class="flex items-end">
                    <Button class="w-full" :disabled="form.processing"
                        >Add action</Button
                    >
                </div>
            </form>
        </Card>

        <Card class="divide-y divide-border">
            <div
                v-if="actions.length === 0"
                class="p-6 text-sm text-muted-foreground"
            >
                No important actions yet.
            </div>
            <div
                v-for="action in actions"
                :key="action.id"
                class="flex items-center justify-between gap-4 p-5"
            >
                <div>
                    <p class="font-medium">{{ action.name }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ action.event_name }} ·
                        {{ action.page_path || 'all pages' }}
                    </p>
                </div>
                <span
                    class="rounded-full bg-muted px-2.5 py-1 text-xs text-muted-foreground"
                    >{{ action.is_active ? 'Active' : 'Paused' }}</span
                >
            </div>
        </Card>
    </div>
</template>
