<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { store } from '@/routes/websites/goals';

const props = defineProps<{
    website: { id: number; name: string };
    goals: Array<{
        id: number;
        name: string;
        type: string;
        eventName: string | null;
        path: string | null;
        analytics: {
            conversions: number;
            conversionRate: number;
            trend: { previous: number; change: number | null };
        };
    }>;
}>();
const form = useForm({
    name: '',
    type: 'event',
    event_name: '',
    path: '',
    path_operator: 'exact',
    property_match: {},
});

function trendLabel(change: number | null): string {
    if (change === null) {
        return 'New';
    }

    return (change >= 0 ? '+' : '') + change + '%';
}

function submit(): void {
    form.post(store(props.website.id).url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head :title="`${website.name} goals`" />
    <div
        class="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 pt-6 pb-20 sm:px-6"
    >
        <header class="flex items-end justify-between gap-4">
            <div>
                <p
                    class="text-xs tracking-[0.14em] text-muted-foreground uppercase"
                >
                    Goals
                </p>
                <h1 class="mt-2 text-2xl font-medium tracking-tight">
                    Measure outcomes
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Keep conversion setup small and tied to actions you can
                    improve.
                </p>
            </div>
            <Button as-child variant="outline"
                ><Link :href="dashboard()">Back to dashboard</Link></Button
            >
        </header>
        <Card class="p-5"
            ><form class="grid gap-4 md:grid-cols-5" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label>Name</Label
                    ><Input v-model="form.name" placeholder="Signup complete" />
                </div>
                <div class="grid gap-2">
                    <Label>Type</Label
                    ><select
                        v-model="form.type"
                        class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="event">Event</option>
                        <option value="url">URL</option>
                    </select>
                </div>
                <div v-if="form.type === 'event'" class="grid gap-2">
                    <Label>Event name</Label
                    ><Input
                        v-model="form.event_name"
                        placeholder="signup_completed"
                    />
                </div>
                <div v-else class="grid gap-2">
                    <Label>URL path</Label
                    ><Input v-model="form.path" placeholder="/thanks" />
                </div>
                <div class="flex items-end md:col-span-2">
                    <Button class="w-full" :disabled="form.processing"
                        >Add goal</Button
                    >
                </div>
            </form></Card
        >
        <Card class="divide-y divide-border"
            ><div
                v-if="goals.length === 0"
                class="p-6 text-sm text-muted-foreground"
            >
                No goals configured yet.
            </div>
            <div
                v-for="goal in goals"
                :key="goal.id"
                class="flex items-center justify-between gap-4 p-5"
            >
                <div>
                    <p class="font-medium">{{ goal.name }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ goal.type === 'event' ? goal.eventName : goal.path }}
                    </p>
                </div>
                <div class="text-right">
                    <p class="font-medium">{{ goal.analytics.conversions }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ goal.analytics.conversionRate }}% conversion rate ·
                        {{ trendLabel(goal.analytics.trend.change) }} vs prior
                    </p>
                </div>
            </div></Card
        >
    </div>
</template>
