<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit as profile } from '@/routes/profile';
import { update as updateAi, test as testAi } from '@/routes/settings/ai';

type ModelOption = {
    value: string;
    label: string;
    tier: string;
    description: string;
};

type ProviderOption = {
    value: string;
    label: string;
    defaultModel: string;
    modelDocsUrl: string;
    models: ModelOption[];
};

const props = defineProps<{
    providers: ProviderOption[];
    settings: {
        provider: string;
        model: string | null;
        baseUrl: string | null;
        isEnabled: boolean;
        status: string;
        hasApiKey: boolean;
    } | null;
}>();

const initialProvider = props.providers.some(
    (provider) => provider.value === props.settings?.provider,
)
    ? (props.settings?.provider ?? 'openai')
    : (props.providers[0]?.value ?? 'openai');
const initialProviderOption = props.providers.find(
    (provider) => provider.value === initialProvider,
);
const initialModel = initialProviderOption?.models.some(
    (model) => model.value === props.settings?.model,
)
    ? (props.settings?.model ?? initialProviderOption.defaultModel)
    : (initialProviderOption?.defaultModel ?? '');

const form = useForm({
    provider: initialProvider,
    model: initialModel,
    api_key: '',
    base_url: null as null,
    is_enabled: props.settings?.isEnabled || false,
});

const selectedProvider = computed(() =>
    props.providers.find((provider) => provider.value === form.provider),
);
const selectedModel = computed(() =>
    selectedProvider.value?.models.find((model) => model.value === form.model),
);

watch(
    () => form.provider,
    () => {
        form.model = selectedProvider.value?.defaultModel ?? '';
        form.clearErrors('provider', 'model');
    },
);

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
                    <Label for="provider">Provider</Label>
                    <select
                        id="provider"
                        v-model="form.provider"
                        class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm transition-colors outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <option
                            v-for="provider in providers"
                            :key="provider.value"
                            :value="provider.value"
                        >
                            {{ provider.label }}
                        </option>
                    </select>
                    <InputError :message="form.errors.provider" />
                </div>
                <div class="grid gap-2">
                    <div class="flex items-center justify-between gap-3">
                        <Label for="model">Model</Label>
                        <a
                            v-if="selectedProvider"
                            :href="selectedProvider.modelDocsUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                        >
                            View model docs
                        </a>
                    </div>
                    <select
                        id="model"
                        v-model="form.model"
                        class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm transition-colors outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <option
                            v-for="model in selectedProvider?.models"
                            :key="model.value"
                            :value="model.value"
                        >
                            {{ model.label }} · {{ model.tier }}
                        </option>
                    </select>
                    <p
                        v-if="selectedModel"
                        class="text-sm leading-5 text-muted-foreground"
                    >
                        {{ selectedModel.description }}
                    </p>
                    <InputError :message="form.errors.model" />
                </div>
                <div class="grid gap-2">
                    <Label for="api-key"
                        >API key
                        {{
                            settings?.hasApiKey
                                ? '(leave blank to keep current)'
                                : ''
                        }}</Label
                    ><Input
                        id="api-key"
                        v-model="form.api_key"
                        type="password"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.api_key" />
                </div>
                <label class="flex items-center gap-2 text-sm"
                    ><input v-model="form.is_enabled" type="checkbox" /> Enable
                    AI explanations</label
                >
                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
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
