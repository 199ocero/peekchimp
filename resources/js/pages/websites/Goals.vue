<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { destroy, store, update } from '@/routes/websites/goals';

type Goal = {
    id: number;
    name: string;
    type: 'event' | 'url';
    eventName: string | null;
    path: string | null;
    pathOperator: 'exact' | 'prefix';
    propertyMatch: Record<string, boolean | number | string> | null;
    isActive: boolean;
    analytics: {
        conversions: number;
        conversionRate: number;
        trend: { previous: number; change: number | null };
    };
};

const props = defineProps<{
    website: { id: number; name: string };
    goals: Goal[];
}>();

const editingGoalId = ref<number | null>(null);
const form = useForm<{
    name: string;
    type: 'event' | 'url';
    event_name: string;
    path: string;
    path_operator: 'exact' | 'prefix';
    property_match: Record<string, boolean | number | string> | null;
    is_active: boolean;
}>({
    name: '',
    type: 'event',
    event_name: '',
    path: '',
    path_operator: 'exact',
    property_match: null,
    is_active: true,
});

function trendLabel(change: number | null): string {
    if (change === null) {
        return 'New';
    }

    return (change >= 0 ? '+' : '') + change + '%';
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: cancelEditing,
    };

    if (editingGoalId.value !== null) {
        form.patch(
            update({
                project: props.website.id,
                goal: editingGoalId.value,
            }).url,
            options,
        );

        return;
    }

    form.post(store(props.website.id).url, options);
}

function editGoal(goal: Goal): void {
    editingGoalId.value = goal.id;
    form.clearErrors();
    form.name = goal.name;
    form.type = goal.type;
    form.event_name = goal.eventName ?? '';
    form.path = goal.path ?? '';
    form.path_operator = goal.pathOperator;
    form.property_match = goal.propertyMatch ? { ...goal.propertyMatch } : null;
    form.is_active = goal.isActive;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function cancelEditing(): void {
    editingGoalId.value = null;
    form.reset();
    form.clearErrors();
}

function deleteGoal(goal: Goal): void {
    if (
        !window.confirm(
            `Delete “${goal.name}” and its conversion history? This cannot be undone.`,
        )
    ) {
        return;
    }

    router.delete(destroy({ project: props.website.id, goal: goal.id }).url, {
        preserveScroll: true,
        onSuccess: () => {
            if (editingGoalId.value === goal.id) {
                cancelEditing();
            }
        },
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
            ><form class="grid gap-4 md:grid-cols-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="goal-name">Name</Label
                    ><Input
                        id="goal-name"
                        v-model="form.name"
                        placeholder="Signup complete"
                    />
                    <p v-if="form.errors.name" class="text-xs text-destructive">
                        {{ form.errors.name }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="goal-type">Type</Label
                    ><select
                        id="goal-type"
                        v-model="form.type"
                        class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="event">Event</option>
                        <option value="url">URL</option>
                    </select>
                </div>
                <div v-if="form.type === 'event'" class="grid gap-2">
                    <Label for="goal-event-name">Event name</Label
                    ><Input
                        id="goal-event-name"
                        v-model="form.event_name"
                        placeholder="signup_completed"
                    />
                    <p
                        v-if="form.errors.event_name"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.event_name }}
                    </p>
                </div>
                <div v-else class="grid gap-2">
                    <Label for="goal-path">URL path</Label
                    ><Input
                        id="goal-path"
                        v-model="form.path"
                        placeholder="/thanks"
                    />
                    <p v-if="form.errors.path" class="text-xs text-destructive">
                        {{ form.errors.path }}
                    </p>
                </div>
                <div v-if="form.type === 'url'" class="grid gap-2">
                    <Label for="goal-path-operator">Match</Label>
                    <select
                        id="goal-path-operator"
                        v-model="form.path_operator"
                        class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="exact">Exact path</option>
                        <option value="prefix">Starts with</option>
                    </select>
                </div>
                <label class="flex items-center gap-2 self-end pb-2 text-sm">
                    <input
                        v-model="form.is_active"
                        type="checkbox"
                        class="size-4 accent-primary"
                    />
                    Active
                </label>
                <div class="flex items-end gap-2 md:col-span-2">
                    <Button class="flex-1" :disabled="form.processing">
                        {{
                            editingGoalId === null ? 'Add goal' : 'Save changes'
                        }}
                    </Button>
                    <Button
                        v-if="editingGoalId !== null"
                        type="button"
                        variant="outline"
                        :disabled="form.processing"
                        @click="cancelEditing"
                    >
                        Cancel
                    </Button>
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
                class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <div class="flex items-center gap-2">
                        <p class="font-medium">{{ goal.name }}</p>
                        <span
                            class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                        >
                            {{ goal.isActive ? 'Active' : 'Paused' }}
                        </span>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ goal.type === 'event' ? goal.eventName : goal.path }}
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="text-right">
                        <p class="font-medium">
                            {{ goal.analytics.conversions }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ goal.analytics.conversionRate }}% conversion rate
                            · {{ trendLabel(goal.analytics.trend.change) }} vs
                            prior
                        </p>
                    </div>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        :aria-label="`Edit ${goal.name}`"
                        @click="editGoal(goal)"
                    >
                        <Pencil class="size-4" />
                    </Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        :aria-label="`Delete ${goal.name}`"
                        @click="deleteGoal(goal)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div></div
        ></Card>
    </div>
</template>
