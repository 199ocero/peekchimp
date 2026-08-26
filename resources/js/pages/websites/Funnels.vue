<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { store } from '@/routes/websites/funnels';

const props = defineProps<{
    website: { id: number; name: string };
    funnels: Array<{
        id: number;
        name: string;
        steps: Array<{ name: string }>;
        analytics: {
            steps: Array<{
                name: string;
                users: number;
                dropOffPercentage: number;
            }>;
            conversionRate: number;
        };
    }>;
}>();

type FunnelStepForm = {
    name: string;
    type: 'url' | 'event';
    path: string;
    event_name: string;
    path_operator: 'exact' | 'prefix';
};

const form = useForm<{
    name: string;
    is_active: boolean;
    steps: FunnelStepForm[];
}>({
    name: '',
    is_active: true,
    steps: [
        {
            name: 'Landing page',
            type: 'url',
            path: '/',
            event_name: '',
            path_operator: 'exact',
        },
        {
            name: 'Signup complete',
            type: 'event',
            path: '',
            event_name: 'signup_completed',
            path_operator: 'exact',
        },
    ],
});

function addStep(): void {
    if (form.steps.length >= 5) {
        return;
    }

    form.steps.push({
        name: 'Step ' + (form.steps.length + 1),
        type: 'event',
        path: '',
        event_name: '',
        path_operator: 'exact',
    });
}

function removeStep(index: number): void {
    if (form.steps.length <= 2) {
        return;
    }

    form.steps.splice(index, 1);
}

function submit(): void {
    form.post(store(props.website.id).url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head :title="`${website.name} funnels`" />
    <div
        class="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 pt-6 pb-20 sm:px-6"
    >
        <header class="flex items-end justify-between gap-4">
            <div>
                <p
                    class="text-xs tracking-[0.14em] text-muted-foreground uppercase"
                >
                    Funnels
                </p>
                <h1 class="mt-2 text-2xl font-medium tracking-tight">
                    Find the drop-off
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Simple, ordered journeys answer where users stop
                    progressing.
                </p>
            </div>
            <Button as-child variant="outline"
                ><Link :href="dashboard()">Back to dashboard</Link></Button
            >
        </header>
        <Card class="p-5"
            ><form class="space-y-5" @submit.prevent="submit">
                <div class="flex gap-4">
                    <div class="grid flex-1 gap-2">
                        <Label>Name</Label
                        ><Input
                            v-model="form.name"
                            placeholder="Signup funnel"
                        />
                    </div>
                    <div class="flex items-end">
                        <Button :disabled="form.processing">Add funnel</Button>
                    </div>
                </div>
                <div class="space-y-3">
                    <div
                        v-for="(step, index) in form.steps"
                        :key="index"
                        class="grid gap-3 rounded-md border border-border p-3 md:grid-cols-[auto_1fr_9rem_1fr_auto]"
                    >
                        <span
                            class="flex size-8 items-center justify-center rounded-full bg-muted text-xs text-muted-foreground"
                            >{{ index + 1 }}</span
                        >
                        <Input v-model="step.name" placeholder="Step name" />
                        <select
                            v-model="step.type"
                            class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="url">URL reached</option>
                            <option value="event">Event occurred</option>
                        </select>
                        <Input
                            v-if="step.type === 'url'"
                            v-model="step.path"
                            placeholder="/pricing"
                        />
                        <Input
                            v-else
                            v-model="step.event_name"
                            placeholder="signup_completed"
                        />
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            :disabled="form.steps.length <= 2"
                            :aria-label="'Remove step ' + (index + 1)"
                            @click="removeStep(index)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                    <Button
                        v-if="form.steps.length < 5"
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="addStep"
                    >
                        <Plus class="mr-2 size-4" />Add step
                    </Button>
                </div>
            </form></Card
        ><Card class="divide-y divide-border"
            ><div
                v-if="funnels.length === 0"
                class="p-6 text-sm text-muted-foreground"
            >
                No funnels configured yet.
            </div>
            <div v-for="funnel in funnels" :key="funnel.id" class="p-5">
                <div class="flex items-center justify-between">
                    <p class="font-medium">{{ funnel.name }}</p>
                    <span class="text-sm text-muted-foreground"
                        >{{ funnel.analytics.conversionRate }}% complete</span
                    >
                </div>
                <div class="mt-4 grid gap-2 md:grid-cols-2">
                    <div
                        v-for="step in funnel.analytics.steps"
                        :key="step.name"
                        class="rounded-md bg-muted/60 p-3 text-sm"
                    >
                        <div class="flex justify-between">
                            <span>{{ step.name }}</span
                            ><span>{{ step.users }}</span>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ step.dropOffPercentage }}% drop-off to next step
                        </p>
                    </div>
                </div>
            </div></Card
        >
    </div>
</template>
