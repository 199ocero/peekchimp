<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit as profile } from '@/routes/profile';
import { update as updateAi, test as testAi } from '@/routes/settings/ai';

const props = defineProps<{
    providers: string[];
    settings: {
        provider: string;
        model: string | null;
        baseUrl: string | null;
        isEnabled: boolean;
        status: string;
        hasApiKey: boolean;
    } | null;
}>();
const form = useForm({
    provider: props.settings?.provider || props.providers[0] || 'openai',
    model: props.settings?.model || '',
    api_key: '',
    base_url: props.settings?.baseUrl || '',
    is_enabled: props.settings?.isEnabled || false,
});
function save(): void {
    form.patch(updateAi().url, { preserveScroll: true });
}
function test(): void {
    form.post(testAi().url, { preserveScroll: true });
}
</script>

<template>
    <Head title="AI settings" />
    <div
        class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 pt-6 pb-20 sm:px-6"
    >
        <header>
            <p
                class="text-xs tracking-[0.14em] text-muted-foreground uppercase"
            >
                Workspace settings
            </p>
            <h1 class="mt-2 text-2xl font-medium tracking-tight">
                AI insights
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Peekchimp sends aggregate changes only. Your workspace key stays
                encrypted and AI can be disabled at any time.
            </p>
        </header>
        <Card class="p-5"
            ><form class="space-y-5" @submit.prevent="save">
                <div class="grid gap-2">
                    <Label>Provider</Label
                    ><select
                        v-model="form.provider"
                        class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option
                            v-for="provider in providers"
                            :key="provider"
                            :value="provider"
                        >
                            {{ provider }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label>Model (optional)</Label
                    ><Input
                        v-model="form.model"
                        placeholder="Use provider default"
                    />
                </div>
                <div class="grid gap-2">
                    <Label
                        >API key
                        {{
                            settings?.hasApiKey
                                ? '(leave blank to keep current)'
                                : ''
                        }}</Label
                    ><Input
                        v-model="form.api_key"
                        type="password"
                        autocomplete="off"
                    />
                </div>
                <div class="grid gap-2">
                    <Label>Base URL (optional)</Label
                    ><Input
                        v-model="form.base_url"
                        placeholder="Provider default"
                    />
                </div>
                <label class="flex items-center gap-2 text-sm"
                    ><input v-model="form.is_enabled" type="checkbox" /> Enable
                    AI explanations</label
                >
                <div class="flex gap-3">
                    <Button :disabled="form.processing">Save settings</Button
                    ><Button
                        type="button"
                        variant="outline"
                        :disabled="form.processing"
                        @click="test"
                        >Test saved connection</Button
                    ><Button as-child type="button" variant="ghost"
                        ><Link :href="profile()">Back</Link></Button
                    >
                </div>
            </form></Card
        >
    </div>
</template>
